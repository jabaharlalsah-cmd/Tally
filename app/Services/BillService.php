<?php

namespace App\Services;

use App\Models\AccountGroup;
use App\Models\BillAllocation;
use App\Models\CompanyFeature;
use App\Models\Ledger;
use App\Models\Voucher;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The single authoritative bill-wise engine for ZeroBook (Phase 5C).
 *
 * A "bill" is the set of allocations sharing (ledger_id, ref_name). Its pending
 * is the SIGNED sum of its allocations in **Dr-terms paise** (Dr = +, Cr = −) —
 * the same convention as BalanceService — where the sign comes from the voucher
 * entry each allocation belongs to. Because every bill-wise line is fully split
 * across allocations, Σ(bill pending) for a ledger == Σ(its entries), so:
 *
 *     ledger closing (Dr-terms) = Σ bill pending + opening balance (Dr-terms)
 *
 * The opening balance is the only unbilled part — surfaced as "on account" in the
 * reconciliation so the outstanding never silently diverges from the balance.
 */
class BillService
{
    /** Whether bill-wise is switched on at the company level (F11). */
    public function enabled(): bool
    {
        return (bool) CompanyFeature::current()->bill_by_bill;
    }

    /** True if a ledger id maintains balances bill-by-bill. */
    public function isBillWise(int $ledgerId): bool
    {
        return (bool) Ledger::whereKey($ledgerId)->value('maintain_bill_by_bill');
    }

    /**
     * Aggregate bills for the given ledgers (all bill-wise ledgers if null), as
     * of $to (inclusive; null = all time). Returns a flat list keyed nothing,
     * each: ledger_id, ref_name, pending (Dr-terms paise), original (paise),
     * bill_date, due_date, ref_type (opening), voucher_ids[].
     *
     * @return array<int,array<string,mixed>>
     */
    public function bills(?array $ledgerIds = null, ?Carbon $to = null): array
    {
        $q = BillAllocation::query()
            ->join('vouchers', 'vouchers.id', '=', 'bill_allocations.voucher_id')
            ->leftJoin('voucher_entries', 'voucher_entries.id', '=', 'bill_allocations.voucher_entry_id')
            // Phase 15C — the bill-wise choke point: a provisional voucher's bill appears in
            // Outstandings only when its scenario is in view (default real books). Kept consistent
            // with BalanceService::ledgerClosings (used for the on-account remainder) which reads
            // the same ScenarioContext.
            ->tap(fn ($qq) => \App\Support\ScenarioContext::apply($qq, 'vouchers'))
            ->when($ledgerIds !== null, fn ($qq) => $qq->whereIn('bill_allocations.ledger_id', $ledgerIds))
            ->when($to !== null, fn ($qq) => $qq->where('vouchers.date', '<=', $to->toDateString()))
            ->orderBy('vouchers.date')
            ->orderBy('bill_allocations.id')
            ->get([
                'bill_allocations.ledger_id',
                'bill_allocations.ref_name',
                'bill_allocations.ref_type',
                'bill_allocations.amount',
                'bill_allocations.due_date',
                'bill_allocations.voucher_id',
                'voucher_entries.dr_cr as dr_cr',
                'vouchers.date as v_date',
            ]);

        $bills = [];
        foreach ($q as $r) {
            $key = $r->ledger_id.'|'.$r->ref_name;
            $paise = (int) round(((float) $r->amount) * 100);
            $signed = $r->dr_cr === 'Cr' ? -$paise : $paise; // Dr-terms

            if (! isset($bills[$key])) {
                $bills[$key] = [
                    'ledger_id' => (int) $r->ledger_id,
                    'ref_name' => $r->ref_name,
                    'pending' => 0,
                    'original' => 0,
                    'bill_date' => $r->v_date,
                    'due_date' => null,
                    'ref_type' => $r->ref_type,
                    'voucher_ids' => [],
                ];
            }
            $bills[$key]['pending'] += $signed;
            // The opening leg (New Ref / Advance) sets the original amount + due date.
            if (in_array($r->ref_type, ['new', 'advance'], true) && $bills[$key]['original'] === 0) {
                $bills[$key]['original'] = $paise;
                $bills[$key]['due_date'] = $r->due_date ? Carbon::parse($r->due_date)->toDateString() : null;
            }
            if (! in_array($r->voucher_id, $bills[$key]['voucher_ids'], true)) {
                $bills[$key]['voucher_ids'][] = (int) $r->voucher_id;
            }
        }

        return array_values($bills);
    }

    /**
     * Open bills per bill-wise ledger for the voucher screen's Against-Ref picker
     * (0 network while allocating). Pending is a positive magnitude plus the side.
     *
     * @return array<int,array<int,array<string,mixed>>>  keyed by ledger_id
     */
    public function openBillsCache(): array
    {
        $billWise = Ledger::where('maintain_bill_by_bill', true)->pluck('id')->map(fn ($i) => (int) $i)->all();
        if (! $billWise) {
            return [];
        }
        $out = [];
        foreach ($this->bills($billWise, null) as $b) {
            if ($b['pending'] === 0) {
                continue; // closed
            }
            $out[$b['ledger_id']][] = [
                'ref_name' => $b['ref_name'],
                'pending' => abs($b['pending']) / 100,
                'pending_paise' => abs($b['pending']),
                'side' => $b['pending'] > 0 ? 'Dr' : 'Cr',
                'due_date' => $b['due_date'],
                'original' => $b['original'] / 100,
            ];
        }

        return $out;
    }

    /**
     * Existing allocations for a voucher, grouped by voucher_entry_id, for the
     * alter screen so the sub-screen re-opens pre-filled.
     *
     * @return array<int,array<int,array<string,mixed>>>  keyed by voucher_entry_id
     */
    public function allocationsForVoucher(int $voucherId): array
    {
        $out = [];
        foreach (BillAllocation::where('voucher_id', $voucherId)->orderBy('id')->get() as $a) {
            $out[(int) $a->voucher_entry_id][] = [
                'ref_type' => $a->ref_type,
                'ref_name' => $a->ref_name,
                'amount' => (float) $a->amount,
                'due_date' => $a->due_date?->toDateString(),
            ];
        }

        return $out;
    }

    // ---- posting (shared path) ----------------------------------------------

    /**
     * Validate the bill allocations inside a voucher payload. For every line whose
     * ledger is bill-wise (and the feature is on), the allocations must be present
     * and sum to the line amount to the paise, with valid ref types/names. Any
     * allocations that ARE supplied are validated regardless of the feature flag.
     *
     * @return ?string  an error message, or null if valid
     */
    public function validatePayload(array $payload): ?string
    {
        $enabled = $this->enabled();
        $lines = $payload['lines'] ?? [];

        foreach ($lines as $idx => $line) {
            $ledgerId = (int) ($line['ledger_id'] ?? 0);
            $allocs = $line['allocations'] ?? [];
            $billWise = $this->isBillWise($ledgerId);

            if (empty($allocs)) {
                // Only a bill-wise line under the active feature MUST allocate.
                if ($billWise && $enabled) {
                    $name = Ledger::whereKey($ledgerId)->value('name') ?? 'ledger';
                    return "Allocate the bill references for “{$name}”.";
                }
                continue;
            }
            // Allocations supplied → the ledger MUST be bill-wise. Server-authoritative
            // per-ledger gate: a crafted/stale payload cannot tag bills onto a
            // non-bill-wise ledger.
            if (! $billWise) {
                $name = Ledger::whereKey($ledgerId)->value('name') ?? 'ledger';
                return "Bill-wise details are not applicable for “{$name}”.";
            }

            $lineP = (int) round(((float) ($line['amount'] ?? 0)) * 100);
            $sum = 0;
            foreach ($allocs as $a) {
                if (! in_array($a['ref_type'] ?? null, BillAllocation::TYPES, true)) {
                    return 'Invalid bill reference type on the allocation.';
                }
                if (trim((string) ($a['ref_name'] ?? '')) === '') {
                    return 'Every bill reference needs a name.';
                }
                if (((float) ($a['amount'] ?? 0)) <= 0) {
                    return 'Bill allocation amounts must be greater than zero.';
                }
                $sum += (int) round(((float) ($a['amount'] ?? 0)) * 100);
            }
            if ($sum !== $lineP) {
                $name = Ledger::whereKey($ledgerId)->value('name') ?? 'ledger';
                return sprintf(
                    'Bill allocations for “%s” (%s) must equal the line amount (%s).',
                    $name,
                    number_format($sum / 100, 2),
                    number_format($lineP / 100, 2)
                );
            }
        }

        return null;
    }

    /**
     * Persist allocations for a freshly written voucher. $lineEntries maps the
     * line index → the created VoucherEntry id (in the same order as the payload
     * lines). Called inside the voucher transaction.
     */
    public function persist(Voucher $voucher, array $lines, array $lineEntryIds): void
    {
        foreach ($lines as $idx => $line) {
            $allocs = $line['allocations'] ?? [];
            if (empty($allocs)) {
                continue;
            }
            // Defence in depth: never write bill rows against a non-bill-wise ledger.
            if (! $this->isBillWise((int) $line['ledger_id'])) {
                continue;
            }
            $entryId = $lineEntryIds[$idx] ?? null;
            foreach ($allocs as $a) {
                BillAllocation::create([
                    'voucher_id' => $voucher->id,
                    'ledger_id' => (int) $line['ledger_id'],
                    'voucher_entry_id' => $entryId,
                    'ref_type' => $a['ref_type'],
                    'ref_name' => trim((string) $a['ref_name']),
                    'amount' => round((float) $a['amount'], 2),
                    'due_date' => ! empty($a['due_date']) ? $a['due_date'] : null,
                ]);
            }
        }
    }

    // ---- Outstandings report -------------------------------------------------

    /** Ledger ids (bill-wise) that live under a named group subtree. */
    public function billWiseLedgersUnder(string $groupName): array
    {
        $root = AccountGroup::where('name', $groupName)->value('id');
        if (! $root) {
            return [];
        }
        // collect the group subtree
        $all = AccountGroup::get(['id', 'parent_id']);
        $childrenOf = [];
        foreach ($all as $g) {
            $childrenOf[$g->parent_id][] = $g->id;
        }
        $groupIds = [];
        $stack = [$root];
        while ($stack) {
            $gid = array_pop($stack);
            $groupIds[] = $gid;
            foreach ($childrenOf[$gid] ?? [] as $c) {
                $stack[] = $c;
            }
        }

        return Ledger::whereIn('group_id', $groupIds)
            ->where('maintain_bill_by_bill', true)
            ->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    /**
     * Receivables ('receivable' → Sundry Debtors) / Payables ('payable' →
     * Sundry Creditors): open bills grouped by party, with aging + a
     * reconciliation remainder (closing = Σ pending + on-account).
     */
    public function outstandings(string $nature, Carbon $asOf): array
    {
        $groupName = $nature === 'payable' ? 'Sundry Creditors' : 'Sundry Debtors';
        $ledgerIds = $this->billWiseLedgersUnder($groupName);
        $ledgers = Ledger::whereIn('id', $ledgerIds)->orderBy('name')->get()->keyBy('id');

        $closings = app(BalanceService::class)->ledgerClosings($asOf); // id => Dr-terms paise
        $allBills = $this->bills($ledgerIds, $asOf);

        // group bills by ledger
        $byLedger = [];
        foreach ($allBills as $b) {
            $byLedger[$b['ledger_id']][] = $b;
        }

        $today = Carbon::today();
        $parties = [];
        $grandPending = 0;
        $grandOnAccount = 0;

        foreach ($ledgerIds as $lid) {
            if (! isset($ledgers[$lid])) {
                continue;
            }
            $bills = $byLedger[$lid] ?? [];
            $rows = [];
            $ledgerPending = 0;
            foreach ($bills as $b) {
                if ($b['pending'] === 0) {
                    continue; // closed
                }
                // Receivables show Dr-pending; Payables show Cr-pending.
                $isReceivable = $b['pending'] > 0;
                if (($nature === 'payable') === $isReceivable) {
                    // a debit bill under creditors (or vice-versa) is an advance the
                    // other way; still show it so the totals reconcile.
                }
                $pendingMag = abs($b['pending']);
                $overdue = 0;
                if ($b['due_date']) {
                    $d = Carbon::parse($b['due_date']);
                    $overdue = $today->greaterThan($d) ? $d->diffInDays($today) : 0;
                }
                $rows[] = [
                    'ref_name' => $b['ref_name'],
                    'bill_date' => $b['bill_date'] ? Carbon::parse($b['bill_date'])->format('d-M-Y') : '',
                    'due_date' => $b['due_date'] ? Carbon::parse($b['due_date'])->format('d-M-Y') : '',
                    'original' => BalanceService::money($b['original']),
                    'pending' => BalanceService::money($pendingMag),
                    'pending_paise' => $pendingMag,
                    'pending_signed' => $b['pending'],
                    'overdue_days' => $overdue,
                    'ledger_id' => $lid,
                ];
                $ledgerPending += $b['pending'];
            }
            $closing = $closings[$lid] ?? 0;
            $onAccount = $closing - $ledgerPending; // unbilled part (typically opening)

            if (empty($rows) && $onAccount === 0) {
                continue; // nothing outstanding for this party
            }

            $parties[] = [
                'ledger_id' => $lid,
                'name' => $ledgers[$lid]->name,
                'rows' => $rows,
                'pending_total' => BalanceService::money(abs($ledgerPending)),
                'pending_signed' => $ledgerPending,
                'on_account' => BalanceService::money(abs($onAccount)),
                'on_account_signed' => $onAccount,
                'closing' => BalanceService::money(abs($closing)),
                'closing_side' => BalanceService::drcr($closing),
            ];
            $grandPending += $ledgerPending;
            $grandOnAccount += $onAccount;
        }

        $grandClosing = $grandPending + $grandOnAccount;

        return [
            'nature' => $nature,
            'group' => $groupName,
            'parties' => $parties,
            'grand_pending' => BalanceService::money(abs($grandPending)),
            'grand_pending_signed' => $grandPending,
            'grand_on_account' => BalanceService::money(abs($grandOnAccount)),
            'grand_on_account_signed' => $grandOnAccount,
            'grand_closing' => BalanceService::money(abs($grandClosing)),
            'reconciles' => ($grandPending + $grandOnAccount) === $grandClosing, // always true by construction
            'as_of' => $asOf->format('d-M-Y'),
        ];
    }

    /** Vouchers behind a single bill (ledger + ref_name), for the drill list. */
    public function billVouchers(int $ledgerId, string $refName): array
    {
        $allocs = BillAllocation::where('ledger_id', $ledgerId)
            ->where('ref_name', $refName)
            // Phase 15C — the drill hides a provisional voucher's bill unless its scenario is in view.
            ->whereHas('voucher', fn ($q) => \App\Support\ScenarioContext::apply($q, 'vouchers'))
            ->with(['voucher', 'entry'])
            ->orderBy('id')
            ->get();

        $rows = [];
        $seen = [];
        foreach ($allocs as $a) {
            $v = $a->voucher;
            if (! $v || isset($seen[$v->id])) {
                continue;
            }
            $seen[$v->id] = true;
            $paise = (int) round(((float) $a->amount) * 100);
            $rows[] = [
                'voucher_id' => $v->id,
                'display_number' => $v->displayNumber(),
                'type_label' => Voucher::TYPES[$v->type]['label'] ?? ucfirst($v->type),
                'date' => $v->date->format('d-M-Y'),
                'ref_type' => BillAllocation::TYPE_LABELS[$a->ref_type] ?? $a->ref_type,
                'amount' => BalanceService::money($paise),
                'side' => $a->entry?->dr_cr ?? '',
            ];
        }

        return $rows;
    }
}
