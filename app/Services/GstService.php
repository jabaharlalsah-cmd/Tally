<?php

namespace App\Services;

use App\Models\CompanyFeature;
use App\Models\Ledger;
use App\Models\Voucher;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The single authoritative GST engine for ZeroBook — the tax math a CA firm can
 * trust. Everything is computed in **integer paise** so the numbers are exact.
 *
 * Rules (accounting invoice; the rate lives on the Sales/Purchase ledger):
 *   - A supply is intra-state when party.state == company.state, else inter-state.
 *   - Intra-state → CGST + SGST, each at half the rate.
 *   - Inter-state → IGST at the full rate.
 *   - Tax is grouped by rate slab; each (tax-type, slab) is its own tax line.
 *
 * Paise formula (identical on the client so the live figure matches the post):
 *   IGST_paise  = round(baseP * r / 100)
 *   CGST_paise  = SGST_paise = round(baseP * r / 200)   // half the rate
 *
 * The same method that computes the tax is used to **re-verify** a posted
 * voucher on the server, so client-supplied tax amounts are never trusted.
 */
class GstService
{
    /** Cached (role,type) => tax ledger id map. */
    private ?array $taxLedgerMap = null;

    public function company(): CompanyFeature
    {
        return CompanyFeature::current();
    }

    public function enabled(): bool
    {
        return (bool) $this->company()->gst;
    }

    public function companyState(): ?string
    {
        // Phase 12A — identity lives on the companies row (was company_features).
        return activeCompany()?->state;
    }

    /** Normalise a state name for comparison (trim + lower-case). */
    public static function normState(?string $s): string
    {
        return strtolower(trim((string) $s));
    }

    public function isIntraState(?string $partyState): bool
    {
        $company = self::normState($this->companyState());
        $party = self::normState($partyState);

        // If either side has no state we cannot prove inter-state, so treat as
        // intra-state (CGST+SGST) — the conservative, most-common default.
        if ($company === '' || $party === '') {
            return true;
        }

        return $company === $party;
    }

    /**
     * Map of the six reserved duty ledgers keyed "role.type", e.g. "output.central".
     *
     * @return array<string,int>
     */
    public function taxLedgerMap(): array
    {
        if ($this->taxLedgerMap !== null) {
            return $this->taxLedgerMap;
        }
        $map = [];
        foreach (Ledger::whereNotNull('tax_type')->whereNotNull('tax_role')->get() as $l) {
            $map[$l->tax_role.'.'.$l->tax_type] = $l->id;
        }

        return $this->taxLedgerMap = $map;
    }

    /** The tax ledger id for a role (output|input) + type (central|state|integrated). */
    public function taxLedgerId(string $role, string $type): ?int
    {
        return $this->taxLedgerMap()[$role.'.'.$type] ?? null;
    }

    /** The rate% for a nominal (Sales/Purchase) ledger, or null. */
    public function rateForLedger(int $ledgerId): ?float
    {
        $rate = Ledger::whereKey($ledgerId)->value('gst_rate');

        return $rate !== null ? (float) $rate : null;
    }

    /**
     * Compute the balanced GST tax lines for an invoice.
     *
     * @param  string  $type     'sales' | 'purchase'
     * @param  ?string $partyState  the party ledger's state
     * @param  array   $taxable  list of ['ledger_id'=>int,'amount'=>float] taxable lines
     * @return array{
     *   intra:bool,
     *   taxable_paise:int,
     *   tax_paise:int,
     *   total_paise:int,
     *   slabs:array<int,array{rate:float,base_paise:int,cgst:int,sgst:int,igst:int}>,
     *   lines:array<int,array{ledger_id:int,dr_cr:string,amount:float,tax_type:string,rate:float}>,
     *   by_ledger:array<int,int>
     * }
     */
    public function computeInvoiceTax(string $type, ?string $partyState, array $taxable): array
    {
        $intra = $this->isIntraState($partyState);
        // Sales & Credit Note are OUTPUT-tax (customer) side; Purchase & Debit Note
        // are INPUT-tax (supplier) side.
        $role = in_array($type, ['sales', 'credit_note'], true) ? 'output' : 'input';
        // The duty-ledger (and revenue-ledger) side. A Note REVERSES the original
        // tax, so it sits on the OPPOSITE side to its invoice:
        //   Sales:  Cr Output   →  Credit Note: Dr Output  (reverse)
        //   Purchase: Dr Input  →  Debit Note:  Cr Input   (reverse)
        $ledgerSide = match ($type) {
            'sales' => 'Cr',
            'credit_note' => 'Dr',
            'debit_note' => 'Cr',
            default => 'Dr', // purchase
        };

        // Group taxable base by rate slab (paise).
        $rates = $this->rateMap();
        $baseBySlab = [];      // rate => base paise
        $taxablePaise = 0;
        foreach ($taxable as $line) {
            $lid = (int) ($line['ledger_id'] ?? 0);
            $amtP = (int) round(((float) ($line['amount'] ?? 0)) * 100);
            $taxablePaise += $amtP;
            // An item-sourced line carries its own rate (the Stock Item's rate),
            // which overrides the ledger rate; a ledger line falls back to rateMap.
            $rate = array_key_exists('rate', $line) && $line['rate'] !== null
                ? (float) $line['rate']
                : ($rates[$lid] ?? 0.0);
            $key = (string) $rate;
            $baseBySlab[$key] = ($baseBySlab[$key] ?? 0) + $amtP;
        }

        $slabs = [];
        $taxLines = [];
        $byLedger = [];
        $taxPaise = 0;

        // Deterministic slab order (ascending rate) so output is stable.
        $keys = array_keys($baseBySlab);
        usort($keys, fn ($a, $b) => (float) $a <=> (float) $b);

        foreach ($keys as $key) {
            $rate = (float) $key;
            $baseP = $baseBySlab[$key];
            $cgst = 0;
            $sgst = 0;
            $igst = 0;
            if ($rate > 0) {
                if ($intra) {
                    $half = (int) round($baseP * $rate / 200);
                    $cgst = $half;
                    $sgst = $half;
                } else {
                    $igst = (int) round($baseP * $rate / 100);
                }
            }
            $slabs[] = ['rate' => $rate, 'base_paise' => $baseP, 'cgst' => $cgst, 'sgst' => $sgst, 'igst' => $igst];

            $push = function (string $taxType, int $paise) use (&$taxLines, &$byLedger, &$taxPaise, $role, $ledgerSide, $rate) {
                if ($paise <= 0) {
                    return;
                }
                $ledgerId = $this->taxLedgerId($role, $taxType);
                if (! $ledgerId) {
                    return; // duty ledger missing — nothing to post (never happens once seeded)
                }
                $taxLines[] = [
                    'ledger_id' => $ledgerId,
                    'dr_cr' => $ledgerSide,
                    'amount' => round($paise / 100, 2),
                    'tax_type' => $taxType,
                    'rate' => $rate,
                ];
                $byLedger[$ledgerId] = ($byLedger[$ledgerId] ?? 0) + $paise;
                $taxPaise += $paise;
            };

            $push('central', $cgst);
            $push('state', $sgst);
            $push('integrated', $igst);
        }

        return [
            'intra' => $intra,
            'taxable_paise' => $taxablePaise,
            'tax_paise' => $taxPaise,
            'total_paise' => $taxablePaise + $taxPaise,
            'slabs' => $slabs,
            'lines' => $taxLines,
            'by_ledger' => $byLedger,
        ];
    }

    /** ledger_id => gst_rate for all nominal ledgers that carry a rate. */
    private function rateMap(): array
    {
        return Ledger::whereNotNull('gst_rate')->pluck('gst_rate', 'id')
            ->map(fn ($r) => (float) $r)->all();
    }

    /**
     * Build taxable lines from item-invoice stock lines: the base is the entered
     * qty × selling-rate, and the tax rate is the Stock Item's own rate looked up
     * server-side (one query) — carried as a per-line 'rate' override so a per-item
     * rate is honoured and the client's tax can't be trusted blindly.
     *
     * @return array<int,array{amount:float,rate:float}>
     */
    private function taxableFromItems(array $items): array
    {
        $ids = array_map(fn ($it) => (int) ($it['stock_item_id'] ?? 0), $items);
        $rates = \App\Models\StockItem::whereIn('id', $ids)->pluck('gst_rate', 'id')->all();
        $out = [];
        foreach ($items as $it) {
            $iid = (int) ($it['stock_item_id'] ?? 0);
            $qty = (float) ($it['qty'] ?? 0);
            $rate = (float) ($it['rate'] ?? 0);
            $out[] = [
                'amount' => round($qty * $rate, 2),
                'rate' => isset($rates[$iid]) ? (float) $rates[$iid] : 0.0,
            ];
        }

        return $out;
    }

    /** ledger_id => tax_role for the duty ledgers (to classify posted lines). */
    private function taxRoleByLedger(): array
    {
        return Ledger::whereNotNull('tax_type')->pluck('tax_type', 'id')->all();
    }

    /**
     * Server-side authority: recompute the tax for an invoice payload and return
     * an error string if the posted tax lines don't match (per tax ledger, to the
     * paise) — otherwise null. Only invoice-mode Sales/Purchase with GST enabled
     * are checked; everything else passes through untouched.
     *
     * @param  array $payload  { type, party_ledger_id, lines[] }
     */
    public function verifyInvoicePayload(array $payload): ?string
    {
        $type = $payload['type'] ?? null;
        if (! in_array($type, Voucher::INVOICE_TYPES, true)) {
            return null;
        }
        // Only a party-set invoice with GST on is auto-verified; a manual
        // "as Voucher" Sales/Purchase (no party) is left to the user.
        if (! $this->enabled() || empty($payload['party_ledger_id'])) {
            return null;
        }

        $partyId = (int) $payload['party_ledger_id'];
        $lines = $payload['lines'] ?? [];
        $taxRole = $this->taxRoleByLedger();     // duty ledger id => tax_type
        $taxLedgerIds = array_keys($taxRole);

        // Split posted lines: party leg, tax lines, taxable (nominal) lines.
        $postedTaxByLedger = [];
        $taxable = [];
        foreach ($lines as $line) {
            $lid = (int) ($line['ledger_id'] ?? 0);
            if ($lid === $partyId) {
                continue; // party leg
            }
            $paise = (int) round(((float) ($line['amount'] ?? 0)) * 100);
            if (in_array($lid, $taxLedgerIds, true)) {
                $postedTaxByLedger[$lid] = ($postedTaxByLedger[$lid] ?? 0) + $paise;
            } else {
                $taxable[] = ['ledger_id' => $lid, 'amount' => (float) ($line['amount'] ?? 0)];
            }
        }

        // Item invoice: the taxable base and its rate come from the Stock Items
        // (server-side rate lookup), NOT the single aggregate revenue ledger line —
        // so a per-item rate is honoured and a tampered item amount can't sneak in.
        if (! empty($payload['items'])) {
            $taxable = $this->taxableFromItems($payload['items']);
        }

        $partyState = Ledger::whereKey($partyId)->value('state');
        $expected = $this->computeInvoiceTax($type, $partyState, $taxable);
        $expectedByLedger = $expected['by_ledger'];

        // Compare the two maps exactly (same ledgers, same paise per ledger).
        $ledgerIds = array_unique(array_merge(array_keys($expectedByLedger), array_keys($postedTaxByLedger)));
        foreach ($ledgerIds as $lid) {
            $exp = $expectedByLedger[$lid] ?? 0;
            $got = $postedTaxByLedger[$lid] ?? 0;
            if ($exp !== $got) {
                $name = Ledger::whereKey($lid)->value('name') ?? ('ledger #'.$lid);
                return sprintf(
                    'GST mismatch on %s: expected %s, got %s. Tax is computed on the server from the ledger rate and party state.',
                    $name,
                    number_format($exp / 100, 2),
                    number_format($got / 100, 2)
                );
            }
        }

        return null;
    }

    // ---- GST summary report --------------------------------------------------

    /**
     * Output/input tax + taxable value for the period, per the tax ledgers and
     * the Sales/Purchase nominal groups. Reuses BalanceService period nets.
     *
     * @return array structured for the GST summary screen (paise).
     */
    public function summary(Carbon $from, Carbon $to): array
    {
        $bs = app(BalanceService::class);
        $lb = $bs->ledgerBalances($from, $to); // id => [...,'net'=>paise Dr-terms]

        // GST duty ledgers only — the VAT duty ledgers (tax_type 'vat') belong to
        // the other, mutually-exclusive regime and are handled by VatService.
        $taxLedgers = Ledger::whereIn('tax_type', ['central', 'state', 'integrated'])->get()->keyBy('id');

        $out = ['central' => 0, 'state' => 0, 'integrated' => 0];
        $in = ['central' => 0, 'state' => 0, 'integrated' => 0];
        $outLedgerIds = ['central' => null, 'state' => null, 'integrated' => null];
        $inLedgerIds = ['central' => null, 'state' => null, 'integrated' => null];

        foreach ($taxLedgers as $id => $l) {
            $net = $lb[$id]['net'] ?? 0; // Dr-terms paise
            if ($l->tax_role === 'output') {
                // Output tax is a liability → naturally Cr → net negative; present +.
                $out[$l->tax_type] += -$net;
                $outLedgerIds[$l->tax_type] = $id;
            } else {
                // Input tax (ITC) is Dr → net positive.
                $in[$l->tax_type] += $net;
                $inLedgerIds[$l->tax_type] = $id;
            }
        }

        // Taxable value: Sales group ledgers (Cr → present +) and Purchase group (Dr).
        $salesGroupIds = $this->descendantLedgerIds('Sales Accounts');
        $purchaseGroupIds = $this->descendantLedgerIds('Purchase Accounts');
        $taxableSales = 0;
        $taxablePurchase = 0;
        foreach ($lb as $id => $row) {
            if (in_array($id, $salesGroupIds, true)) {
                $taxableSales += -$row['net'];
            }
            if (in_array($id, $purchaseGroupIds, true)) {
                $taxablePurchase += $row['net'];
            }
        }

        $outputTotal = $out['central'] + $out['state'] + $out['integrated'];
        $inputTotal = $in['central'] + $in['state'] + $in['integrated'];

        return [
            'taxable_sales' => $taxableSales,
            'taxable_purchase' => $taxablePurchase,
            'output' => $out,
            'input' => $in,
            'output_ledger_ids' => $outLedgerIds,
            'input_ledger_ids' => $inLedgerIds,
            'output_total' => $outputTotal,
            'input_total' => $inputTotal,
            'net_payable' => $outputTotal - $inputTotal,
        ];
    }

    /** All ledger ids under a named group (direct children only — tax/nominal groups are flat). */
    private function descendantLedgerIds(string $groupName): array
    {
        // Phase 12A — the scoped model, NOT DB::table: seeded group names exist once
        // PER COMPANY now, and a raw lookup would resolve an arbitrary company's id.
        $groupId = \App\Models\AccountGroup::where('name', $groupName)->value('id');
        if (! $groupId) {
            return [];
        }

        return Ledger::where('group_id', $groupId)->pluck('id')->map(fn ($i) => (int) $i)->all();
    }
}
