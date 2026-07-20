<?php

namespace App\Support;

use Closure;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;

/**
 * Phase 15C — the process-scoped "which scenarios are in view" holder (mirrors ActiveCompany).
 *
 * EVERY voucher-reading query calls ScenarioContext::apply($query) to constrain by the currently
 * selected scenarios. The default selection is EMPTY, which means `vouchers.scenario_id IS NULL`
 * (the real books) — so a report, service, or proof that never opts in produces byte-identical
 * output to before 15C. A report opts in by ScenarioContext::set([...]) (from the session picker)
 * or a service call wraps a body in ::runWith([...]); provisional vouchers of the selected
 * scenarios then flow into the computation exactly like real ones.
 *
 * The selection propagates naturally through NESTED service calls (BalanceService → StockService …)
 * because it lives here, not in a threaded parameter — so no inner helper can accidentally drop it.
 */
class ScenarioContext
{
    /**
     * The explicit in-process override. null = "no override" — fall back to the report picker's
     * session selection (so an ordinary report request honours the picker with zero middleware).
     * A non-null value (incl. the empty array) WINS over the session: ::runWith([]) forces real
     * books regardless of what the picker selected, which is exactly what the return-file and
     * impact-report paths rely on.
     *
     * @var array<int>|null
     */
    private static ?array $override = null;

    /** The scenario ids currently in view ([] = real books). */
    public static function selected(): array
    {
        return self::$override ?? self::fromSession();
    }

    public static function isViewingScenarios(): bool
    {
        return self::selected() !== [];
    }

    /** Force a selection in-process (overrides the session). Non-int / empty entries dropped. */
    public static function set(array $ids): void
    {
        self::$override = self::normalize($ids);
    }

    /** Drop the in-process override, reverting to the session-driven selection. */
    public static function clear(): void
    {
        self::$override = null;
    }

    /**
     * Run $fn with a specific selection, restoring the previous override after. A null $ids INHERITS
     * the current selection (used by the ?array $scenarioIds = null service parameters, so a caller
     * that passes nothing keeps whatever the request/report set — and the 29 prior proofs, which pass
     * nothing and have no started session, inherit the empty default = real books).
     */
    public static function runWith(?array $ids, Closure $fn): mixed
    {
        if ($ids === null) {
            return $fn();
        }
        $prev = self::$override;
        self::$override = self::normalize($ids);
        try {
            return $fn();
        } finally {
            self::$override = $prev;
        }
    }

    /** @param array<mixed> $ids @return array<int> */
    private static function normalize(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), fn ($i) => $i > 0)));
    }

    /**
     * The report picker's session selection, read straight from the session so EVERY request type —
     * the initial GET, a Livewire wire:model update, a wire:navigate — resolves the same value with
     * no middleware. Guarded hard: outside a started web session (CLI proofs, queued sync jobs, the
     * desktop sync API) there is no picker, so this is [] and reads stay on the real books.
     *
     * @return array<int>
     */
    private static function fromSession(): array
    {
        try {
            if (! app()->bound('session')) {
                return [];
            }
            $store = session();
            if (! $store->isStarted()) {
                return [];
            }
            $raw = self::normalize((array) $store->get('scenario.selected', []));
            if ($raw === []) {
                // The common case (no scenario selected) short-circuits BEFORE any query, so a
                // default report is byte-identical AND query-free.
                return [];
            }

            // Re-validate against the CURRENT company's ACTIVE scenarios so a since-deactivated,
            // since-deleted, or cross-company id can never silently fold provisional vouchers into
            // a report whose banner (which reads the same validated set) says "real books". The
            // Scenario query is company-scoped by BelongsToCompany. Runs only when a selection
            // exists, so the default path pays nothing.
            return \App\Models\Scenario::query()
                ->where('is_active', true)
                ->whereIn('id', $raw)
                ->pluck('id')
                ->map(fn ($i) => (int) $i)
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Constrain a query on a table that HAS a scenario_id column (the vouchers table, or an alias).
     * Empty selection → `scenario_id IS NULL` (real books, fast on the indexed column); otherwise
     * `scenario_id IS NULL OR scenario_id IN (...)`.
     *
     * @param  QueryBuilder|\Illuminate\Database\Eloquent\Builder  $query
     */
    public static function apply($query, string $table = 'vouchers'): void
    {
        $col = $table.'.scenario_id';
        $ids = self::selected();

        if ($ids === []) {
            $query->whereNull($col);

            return;
        }

        $query->where(function ($w) use ($col, $ids) {
            $w->whereNull($col)->orWhereIn($col, $ids);
        });
    }

    /**
     * Constrain a table that references vouchers by id but does NOT itself carry scenario_id
     * (bill_allocations, cost_allocations, stock_entries when not already joined to vouchers): the
     * row is included iff its voucher is in view. Uses a correlated EXISTS against vouchers.
     *
     * @param  QueryBuilder|\Illuminate\Database\Eloquent\Builder  $query
     */
    public static function applyByVoucher($query, string $voucherIdColumn): void
    {
        $ids = self::selected();

        $query->whereExists(function ($sub) use ($voucherIdColumn, $ids) {
            $sub->selectRaw('1')->from('vouchers')
                ->whereColumn('vouchers.id', $voucherIdColumn);
            if ($ids === []) {
                $sub->whereNull('vouchers.scenario_id');
            } else {
                $sub->where(function ($w) use ($ids) {
                    $w->whereNull('vouchers.scenario_id')->orWhereIn('vouchers.scenario_id', $ids);
                });
            }
        });
    }
}
