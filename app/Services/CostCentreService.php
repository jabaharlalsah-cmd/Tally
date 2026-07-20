<?php

namespace App\Services;

use App\Models\CompanyFeature;
use App\Models\CostAllocation;
use App\Models\CostCentre;
use App\Models\Ledger;
use App\Models\Voucher;
use App\Support\ScenarioContext;
use Carbon\Carbon;

/**
 * The cost-centre engine for ZeroBook (Phase 5D). Mirrors BillService: a
 * cost-applicable ledger line is fully split across cost allocations, validated
 * to the paise on the shared posting path and re-read by the breakup report.
 *
 * Cost allocations are ANALYTICAL ONLY — they add no accounting entry, so the
 * Trial Balance / Balance Sheet / P&L are untouched. Amounts are stored as a
 * magnitude in rupees; the accounting sense (Dr/Cr) comes from the voucher entry.
 */
class CostCentreService
{
    /** Whether cost centres are switched on at the company level (F11). */
    public function enabled(): bool
    {
        return (bool) CompanyFeature::current()->cost_centres;
    }

    /** True if a ledger id has "cost centres applicable" on. */
    public function isCostApplicable(int $ledgerId): bool
    {
        return (bool) Ledger::whereKey($ledgerId)->value('cost_centres_applicable');
    }

    // ---- posting (shared path) ----------------------------------------------

    /**
     * Validate the cost allocations inside a voucher payload. For every line whose
     * ledger is cost-applicable (and the feature is on), the allocations must be
     * present and sum to the line amount to the paise, with valid cost-centre ids.
     * Any allocations that ARE supplied are validated regardless of the feature.
     *
     * @return ?string  an error message, or null if valid
     */
    public function validatePayload(array $payload): ?string
    {
        $enabled = $this->enabled();
        $lines = $payload['lines'] ?? [];

        // Valid cost-centre ids (small master — cache once).
        $validIds = CostCentre::pluck('id')->flip();

        foreach ($lines as $line) {
            $ledgerId = (int) ($line['ledger_id'] ?? 0);
            $allocs = $line['cost_allocations'] ?? [];
            $applicable = $this->isCostApplicable($ledgerId);

            if (empty($allocs)) {
                // Only a cost-applicable line under the active feature MUST allocate.
                if ($applicable && $enabled) {
                    $name = Ledger::whereKey($ledgerId)->value('name') ?? 'ledger';
                    return "Allocate the cost centres for “{$name}”.";
                }
                continue;
            }
            // Allocations supplied → the ledger MUST be cost-applicable. This is the
            // server-authoritative per-ledger gate: a crafted or stale payload can
            // never tag cost centres onto a non-applicable ledger.
            if (! $applicable) {
                $name = Ledger::whereKey($ledgerId)->value('name') ?? 'ledger';
                return "Cost centres are not applicable for “{$name}”.";
            }

            $lineP = (int) round(((float) ($line['amount'] ?? 0)) * 100);
            $sum = 0;
            foreach ($allocs as $a) {
                $ccId = (int) ($a['cost_centre_id'] ?? 0);
                if (! $validIds->has($ccId)) {
                    return 'Invalid cost centre on the allocation.';
                }
                if (((float) ($a['amount'] ?? 0)) <= 0) {
                    return 'Cost allocation amounts must be greater than zero.';
                }
                $sum += (int) round(((float) ($a['amount'] ?? 0)) * 100);
            }
            if ($sum !== $lineP) {
                $name = Ledger::whereKey($ledgerId)->value('name') ?? 'ledger';
                return sprintf(
                    'Cost allocations for “%s” (%s) must equal the line amount (%s).',
                    $name,
                    number_format($sum / 100, 2),
                    number_format($lineP / 100, 2)
                );
            }
        }

        return null;
    }

    /**
     * Persist cost allocations for a freshly written voucher. $lineEntryIds maps
     * the line index → the created VoucherEntry id. Called inside the voucher
     * transaction, after the entries are written.
     */
    public function persist(Voucher $voucher, array $lines, array $lineEntryIds): void
    {
        foreach ($lines as $idx => $line) {
            $allocs = $line['cost_allocations'] ?? [];
            if (empty($allocs)) {
                continue;
            }
            // Defence in depth: never write cost rows against a non-applicable
            // ledger even if validation were somehow bypassed.
            if (! $this->isCostApplicable((int) $line['ledger_id'])) {
                continue;
            }
            $entryId = $lineEntryIds[$idx] ?? null;
            foreach ($allocs as $a) {
                CostAllocation::create([
                    'voucher_id' => $voucher->id,
                    'ledger_id' => (int) $line['ledger_id'],
                    'voucher_entry_id' => $entryId,
                    'cost_centre_id' => (int) $a['cost_centre_id'],
                    'amount' => round((float) $a['amount'], 2),
                ]);
            }
        }
    }

    /** Existing cost allocations for a voucher, grouped by voucher_entry_id (alter prefill). */
    public function allocationsForVoucher(int $voucherId): array
    {
        $out = [];
        foreach (CostAllocation::where('voucher_id', $voucherId)->orderBy('id')->get() as $a) {
            $out[(int) $a->voucher_entry_id][] = [
                'cost_centre_id' => (int) $a->cost_centre_id,
                'amount' => (float) $a->amount,
            ];
        }

        return $out;
    }

    // ---- Cost Centre Breakup report -----------------------------------------

    /**
     * Per cost centre, the ledger-wise allocated amounts + total for the period.
     * Debit allocations are presented as positive "expense/outflow" and credit as
     * negative, so a cost centre's net reads naturally. (Dr = +, Cr = −.)
     *
     * @return array structured for the breakup screen (paise).
     */
    public function breakup(Carbon $from, Carbon $to): array
    {
        $rows = CostAllocation::query()
            ->join('vouchers', 'vouchers.id', '=', 'cost_allocations.voucher_id')
            ->tap(fn ($q) => ScenarioContext::apply($q, 'vouchers'))
            ->leftJoin('voucher_entries', 'voucher_entries.id', '=', 'cost_allocations.voucher_entry_id')
            ->leftJoin('ledgers', 'ledgers.id', '=', 'cost_allocations.ledger_id')
            ->where('vouchers.date', '>=', $from->toDateString())
            ->where('vouchers.date', '<=', $to->toDateString())
            ->get([
                'cost_allocations.cost_centre_id',
                'cost_allocations.amount',
                'cost_allocations.ledger_id',
                'voucher_entries.dr_cr as dr_cr',
                'ledgers.name as ledger_name',
            ]);

        // centre_id => [ 'ledgers' => [ledger_id => ['name'=>,'paise'=>]], 'total'=>paise ]
        $agg = [];
        foreach ($rows as $r) {
            $paise = (int) round(((float) $r->amount) * 100);
            $signed = $r->dr_cr === 'Cr' ? -$paise : $paise;
            $cc = (int) $r->cost_centre_id;
            $lid = (int) $r->ledger_id;
            if (! isset($agg[$cc])) {
                $agg[$cc] = ['ledgers' => [], 'total' => 0];
            }
            if (! isset($agg[$cc]['ledgers'][$lid])) {
                $agg[$cc]['ledgers'][$lid] = ['name' => $r->ledger_name ?? '(ledger)', 'paise' => 0];
            }
            $agg[$cc]['ledgers'][$lid]['paise'] += $signed;
            $agg[$cc]['total'] += $signed;
        }

        $centres = CostCentre::orderBy('name')->get();
        $out = [];
        $grand = 0;
        foreach ($centres as $c) {
            $data = $agg[$c->id] ?? ['ledgers' => [], 'total' => 0];
            $ledgerRows = [];
            foreach ($data['ledgers'] as $lid => $l) {
                $ledgerRows[] = [
                    'ledger_id' => $lid,
                    'name' => $l['name'],
                    'amount' => BalanceService::money($l['paise']),
                    'amount_signed' => $l['paise'],
                ];
            }
            usort($ledgerRows, fn ($a, $b) => strcmp($a['name'], $b['name']));
            $out[] = [
                'cost_centre_id' => $c->id,
                'name' => $c->name,
                'path' => $c->pathLabel(),
                'ledgers' => $ledgerRows,
                'total' => BalanceService::money($data['total']),
                'total_signed' => $data['total'],
                'has_activity' => $data['total'] !== 0 || ! empty($ledgerRows),
            ];
            $grand += $data['total'];
        }

        return [
            'centres' => $out,
            'grand_total' => BalanceService::money($grand),
            'grand_total_signed' => $grand,
            'from' => $from->format('d-M-Y'),
            'to' => $to->format('d-M-Y'),
        ];
    }

    /** Vouchers behind a single cost centre in the period, for the drill list. */
    public function centreVouchers(int $costCentreId, Carbon $from, Carbon $to): array
    {
        $rows = CostAllocation::query()
            ->where('cost_allocations.cost_centre_id', $costCentreId)
            ->join('vouchers', 'vouchers.id', '=', 'cost_allocations.voucher_id')
            ->tap(fn ($q) => ScenarioContext::apply($q, 'vouchers'))
            ->leftJoin('voucher_entries', 'voucher_entries.id', '=', 'cost_allocations.voucher_entry_id')
            ->leftJoin('ledgers', 'ledgers.id', '=', 'cost_allocations.ledger_id')
            ->where('vouchers.date', '>=', $from->toDateString())
            ->where('vouchers.date', '<=', $to->toDateString())
            ->orderBy('vouchers.date')
            ->orderBy('cost_allocations.id')
            ->get([
                'cost_allocations.voucher_id',
                'cost_allocations.amount',
                'voucher_entries.dr_cr as dr_cr',
                'ledgers.name as ledger_name',
                'vouchers.type as v_type',
                'vouchers.number as v_number',
                'vouchers.date as v_date',
            ]);

        $out = [];
        foreach ($rows as $r) {
            $paise = (int) round(((float) $r->amount) * 100);
            $out[] = [
                'voucher_id' => (int) $r->voucher_id,
                'display_number' => strtoupper(Voucher::TYPES[$r->v_type]['abbr'] ?? $r->v_type).'-'.$r->v_number,
                'type_label' => Voucher::TYPES[$r->v_type]['label'] ?? ucfirst($r->v_type),
                'date' => Carbon::parse($r->v_date)->format('d-M-Y'),
                'ledger' => $r->ledger_name ?? '(ledger)',
                'side' => $r->dr_cr ?? '',
                'amount' => BalanceService::money($paise),
            ];
        }

        return $out;
    }
}
