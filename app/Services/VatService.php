<?php

namespace App\Services;

use App\Models\CompanyFeature;
use App\Models\Ledger;
use App\Models\Voucher;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The Nepal VAT engine for ZeroBook — a second, mutually-exclusive tax regime
 * that mirrors GstService's architecture (same posting path, same duty-ledger
 * pattern, same server-side authority) but simpler: VAT is a SINGLE FLAT RATE
 * with no intra/inter-state split and no CGST/SGST/IGST breakdown.
 *
 * Everything is integer paise. The rate lives on the Sales/Purchase ledger's
 * gst_rate column (the shared rate seam — read as "the VAT rate" when VAT is the
 * active regime), never hardcoded. The same compute method re-verifies a posted
 * voucher, so client-supplied VAT is never trusted.
 *
 *   VAT_paise = round(taxable_base_paise * rate / 100)
 *
 * GST and VAT are mutually exclusive; every method here is a no-op (returns the
 * empty/null result) unless VAT is the enabled regime.
 */
class VatService
{
    /** @var array<string,int>|null  role.type => tax ledger id */
    private ?array $taxLedgerMap = null;

    public function company(): CompanyFeature
    {
        return CompanyFeature::current();
    }

    public function enabled(): bool
    {
        return (bool) $this->company()->vat;
    }

    public function companyPan(): ?string
    {
        // Phase 12A — identity lives on the companies row (was company_features).
        return activeCompany()?->pan;
    }

    /** Map of the VAT duty ledgers keyed "role.vat" (output.vat / input.vat). */
    public function taxLedgerMap(): array
    {
        if ($this->taxLedgerMap !== null) {
            return $this->taxLedgerMap;
        }
        $map = [];
        foreach (Ledger::where('tax_type', 'vat')->whereNotNull('tax_role')->get() as $l) {
            $map[$l->tax_role.'.vat'] = $l->id;
        }

        return $this->taxLedgerMap = $map;
    }

    /** The VAT duty ledger id for a role (output|input). */
    public function taxLedgerId(string $role): ?int
    {
        return $this->taxLedgerMap()[$role.'.vat'] ?? null;
    }

    /** The rate% for a nominal (Sales/Purchase) ledger — the shared rate seam. */
    public function rateForLedger(int $ledgerId): ?float
    {
        $rate = Ledger::whereKey($ledgerId)->value('gst_rate');

        return $rate !== null ? (float) $rate : null;
    }

    /**
     * Compute the balanced VAT tax lines for an invoice — one VAT line per rate
     * slab, on the ledger side (Sales credits Output VAT; Purchase debits Input VAT).
     *
     * @param  string $type     'sales' | 'purchase'
     * @param  array  $taxable  list of ['ledger_id'=>int,'amount'=>float]
     * @return array{taxable_paise:int,tax_paise:int,total_paise:int,slabs:array,lines:array,by_ledger:array<int,int>}
     */
    public function computeInvoiceTax(string $type, array $taxable): array
    {
        // Sales & Credit Note = Output VAT (customer) side; Purchase & Debit Note =
        // Input VAT (supplier) side. A Note reverses the tax → opposite side to the
        // invoice (Sales Cr Output → Credit Note Dr Output; Purchase Dr Input →
        // Debit Note Cr Input).
        $role = in_array($type, ['sales', 'credit_note'], true) ? 'output' : 'input';
        $ledgerSide = match ($type) {
            'sales' => 'Cr',
            'credit_note' => 'Dr',
            'debit_note' => 'Cr',
            default => 'Dr', // purchase
        };
        $ledgerId = $this->taxLedgerId($role);

        $rates = $this->rateMap();
        $baseBySlab = [];
        $taxablePaise = 0;
        foreach ($taxable as $line) {
            $lid = (int) ($line['ledger_id'] ?? 0);
            $amtP = (int) round(((float) ($line['amount'] ?? 0)) * 100);
            $taxablePaise += $amtP;
            // An item-sourced line carries its own rate (the Stock Item's rate),
            // overriding the ledger rate; a ledger line falls back to rateMap.
            $rate = array_key_exists('rate', $line) && $line['rate'] !== null
                ? (float) $line['rate']
                : ($rates[$lid] ?? 0.0);
            $baseBySlab[(string) $rate] = ($baseBySlab[(string) $rate] ?? 0) + $amtP;
        }

        $keys = array_keys($baseBySlab);
        usort($keys, fn ($a, $b) => (float) $a <=> (float) $b);

        $slabs = [];
        $taxLines = [];
        $byLedger = [];
        $taxPaise = 0;
        foreach ($keys as $key) {
            $rate = (float) $key;
            $baseP = $baseBySlab[$key];
            $vat = $rate > 0 ? (int) round($baseP * $rate / 100) : 0;
            $slabs[] = ['rate' => $rate, 'base_paise' => $baseP, 'vat' => $vat];
            if ($vat > 0 && $ledgerId) {
                $taxLines[] = [
                    'ledger_id' => $ledgerId,
                    'dr_cr' => $ledgerSide,
                    'amount' => round($vat / 100, 2),
                    'tax_type' => 'vat',
                    'rate' => $rate,
                ];
                $byLedger[$ledgerId] = ($byLedger[$ledgerId] ?? 0) + $vat;
                $taxPaise += $vat;
            }
        }

        return [
            'taxable_paise' => $taxablePaise,
            'tax_paise' => $taxPaise,
            'total_paise' => $taxablePaise + $taxPaise,
            'slabs' => $slabs,
            'lines' => $taxLines,
            'by_ledger' => $byLedger,
        ];
    }

    /** ledger_id => gst_rate for nominal ledgers that carry a rate (shared seam). */
    private function rateMap(): array
    {
        return Ledger::whereNotNull('gst_rate')->pluck('gst_rate', 'id')
            ->map(fn ($r) => (float) $r)->all();
    }

    /**
     * Build taxable lines from item-invoice stock lines: base = qty × selling-rate,
     * tax rate = the Stock Item's own rate (one server-side query), carried as a
     * per-line 'rate' override. Mirrors GstService::taxableFromItems.
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

    /**
     * Server-side authority: recompute the expected VAT from the taxable lines +
     * ledger rate and return an error string if the posted VAT ledger line(s)
     * don't match, to the paise — otherwise null. A no-op unless VAT is enabled
     * and this is a party-set Sales/Purchase invoice (mirrors GstService).
     */
    public function verifyInvoicePayload(array $payload): ?string
    {
        $type = $payload['type'] ?? null;
        if (! in_array($type, Voucher::INVOICE_TYPES, true)) {
            return null;
        }
        if (! $this->enabled() || empty($payload['party_ledger_id'])) {
            return null;
        }

        $partyId = (int) $payload['party_ledger_id'];
        $vatLedgerIds = array_values($this->taxLedgerMap());

        $postedVatByLedger = [];
        $taxable = [];
        foreach ($payload['lines'] ?? [] as $line) {
            $lid = (int) ($line['ledger_id'] ?? 0);
            if ($lid === $partyId) {
                continue;
            }
            $paise = (int) round(((float) ($line['amount'] ?? 0)) * 100);
            if (in_array($lid, $vatLedgerIds, true)) {
                $postedVatByLedger[$lid] = ($postedVatByLedger[$lid] ?? 0) + $paise;
            } else {
                $taxable[] = ['ledger_id' => $lid, 'amount' => (float) ($line['amount'] ?? 0)];
            }
        }

        // Item invoice: the taxable base and its rate come from the Stock Items
        // (server-side rate lookup), not the aggregate revenue ledger line.
        if (! empty($payload['items'])) {
            $taxable = $this->taxableFromItems($payload['items']);
        }

        $expected = $this->computeInvoiceTax($type, $taxable)['by_ledger'];

        $ledgerIds = array_unique(array_merge(array_keys($expected), array_keys($postedVatByLedger)));
        foreach ($ledgerIds as $lid) {
            $exp = $expected[$lid] ?? 0;
            $got = $postedVatByLedger[$lid] ?? 0;
            if ($exp !== $got) {
                $name = Ledger::whereKey($lid)->value('name') ?? ('ledger #'.$lid);
                return sprintf(
                    'VAT mismatch on %s: expected %s, got %s. VAT is computed on the server from the ledger rate.',
                    $name,
                    number_format($exp / 100, 2),
                    number_format($got / 100, 2)
                );
            }
        }

        return null;
    }

    // ---- VAT summary report --------------------------------------------------

    /**
     * Output VAT, Input VAT and Net Payable for the period, plus the taxable value
     * on each side. Reuses BalanceService period nets (Dr-terms paise).
     */
    public function summary(Carbon $from, Carbon $to): array
    {
        $bs = app(BalanceService::class);
        $lb = $bs->ledgerBalances($from, $to);

        $output = 0;
        $input = 0;
        $outputLedgerId = null;
        $inputLedgerId = null;
        foreach (Ledger::where('tax_type', 'vat')->get() as $l) {
            $net = $lb[$l->id]['net'] ?? 0;
            if ($l->tax_role === 'output') {
                $output += -$net;          // liability → naturally Cr
                $outputLedgerId = $l->id;
            } else {
                $input += $net;            // ITC → Dr
                $inputLedgerId = $l->id;
            }
        }

        $salesGroupIds = $this->groupLedgerIds('Sales Accounts');
        $purchaseGroupIds = $this->groupLedgerIds('Purchase Accounts');
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

        return [
            'taxable_sales' => $taxableSales,
            'taxable_purchase' => $taxablePurchase,
            'output' => $output,
            'input' => $input,
            'output_ledger_id' => $outputLedgerId,
            'input_ledger_id' => $inputLedgerId,
            'net_payable' => $output - $input,
        ];
    }

    private function groupLedgerIds(string $groupName): array
    {
        // Phase 12A — the scoped model, NOT DB::table (see GstService::descendantLedgerIds).
        $groupId = \App\Models\AccountGroup::where('name', $groupName)->value('id');
        if (! $groupId) {
            return [];
        }

        return Ledger::where('group_id', $groupId)->pluck('id')->map(fn ($i) => (int) $i)->all();
    }
}
