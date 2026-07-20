<?php

namespace App\Services;

use App\Models\AccountGroup;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\BudgetLinePeriod;
use App\Models\BudgetRevision;
use App\Models\Ledger;
use App\Models\Voucher;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Phase 15A — the Budgets engine.
 *
 * A read-mostly overlay: it stores TARGETS and their per-month allocation, and computes
 * variance by asking BalanceService for the ACTUALS. It never sums vouchers itself — that
 * keeps budgets automatically correct when the accounting core is correct, and prevents
 * "budget report says X, Trial Balance says Y" drift.
 *
 * Everything monetary is compared in INTEGER PAISE (Dr-terms internally from BalanceService,
 * sign-normalised per the target's nature so income/expense read in the natural direction).
 * Budget target amounts are stored as decimal rupees and multiplied to paise at the edge.
 */
class BudgetService
{
    /**
     * A built-in "seasonal" curve (India retail: festival-heavy Sep–Dec). Percentage weights
     * per fiscal month (April-start). The editor's Seasonal one-click applies this; a caller may
     * pass its own 12-weight array instead.
     */
    public const SEASONAL_TEMPLATE = [6, 6, 7, 7, 8, 10, 12, 12, 10, 8, 7, 7];

    public function __construct(private BalanceService $balances)
    {
    }

    /** The CURRENT (post-revision) 12-month targets for a line, in rupees (for the editor). */
    public function lineCurrentMonths(BudgetLine $line): array
    {
        $line->loadMissing('periods');
        $byMonth = [];
        foreach ($line->periods as $p) {
            $byMonth[$p->month][] = $p;
        }
        $out = [];
        for ($m = 1; $m <= 12; $m++) {
            $rows = $byMonth[$m] ?? [];
            $eff = null;
            foreach ($rows as $p) {
                if ($eff === null
                    || ($p->revised_from !== null && ($eff->revised_from === null || $p->revised_from->gt($eff->revised_from)))) {
                    $eff = $p;
                }
            }
            $out[$m - 1] = $eff ? (float) $eff->target_amount : 0.0;
        }

        return $out;
    }

    // ── create ──────────────────────────────────────────────────────────────────

    /**
     * @param  array<int,array{ledger_id?:?int,account_group_id?:?int,annual_target:float|int,allocation_method?:string,months?:?array<float|int>,notes?:?string}>  $lines
     */
    public function createBudget(string $name, int $fyStart, array $lines, ?int $userId = null, bool $primary = false, ?string $notes = null): Budget
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('A budget needs a name.');
        }
        if ($lines === []) {
            throw new InvalidArgumentException('A budget needs at least one target line.');
        }

        return DB::transaction(function () use ($name, $fyStart, $lines, $userId, $primary, $notes) {
            $budget = Budget::create([
                'name' => $name,
                'fiscal_year_start' => $fyStart,
                'is_primary' => false,
                'created_by_user_id' => $userId,
                'notes' => $notes,
            ]);

            $seenLedgers = [];
            $seenGroups = [];
            foreach ($lines as $raw) {
                $line = $this->persistLine($budget, $raw, $seenLedgers, $seenGroups);
                $this->writePeriods($line, $this->allocate($raw), null);
            }

            if ($primary) {
                $budget->makePrimary();
            }

            return $budget->refresh();
        });
    }

    /** Validate + create one budget_line (does NOT write its periods). */
    private function persistLine(Budget $budget, array $raw, array &$seenLedgers, array &$seenGroups): BudgetLine
    {
        $ledgerId = $raw['ledger_id'] ?? null;
        $groupId = $raw['account_group_id'] ?? null;

        if (($ledgerId === null) === ($groupId === null)) {
            throw new InvalidArgumentException('Each budget line must target exactly one of a ledger or a group.');
        }

        // Membership is validated through the BelongsToCompany scope: a ledger/group from
        // another company simply does not resolve, so a cross-company target is rejected here.
        if ($ledgerId !== null) {
            if (! Ledger::whereKey($ledgerId)->exists()) {
                throw new InvalidArgumentException("Ledger #{$ledgerId} does not belong to this company.");
            }
            if (isset($seenLedgers[$ledgerId])) {
                throw new InvalidArgumentException('Duplicate budget line for the same ledger.');
            }
            $seenLedgers[$ledgerId] = true;
        } else {
            if (! AccountGroup::whereKey($groupId)->exists()) {
                throw new InvalidArgumentException("Group #{$groupId} does not belong to this company.");
            }
            if (isset($seenGroups[$groupId])) {
                throw new InvalidArgumentException('Duplicate budget line for the same group.');
            }
            $seenGroups[$groupId] = true;
        }

        $method = $raw['allocation_method'] ?? 'even';
        if (! in_array($method, BudgetLine::METHODS, true)) {
            throw new InvalidArgumentException("Unknown allocation method [{$method}].");
        }

        return BudgetLine::create([
            'budget_id' => $budget->id,
            'ledger_id' => $ledgerId,
            'account_group_id' => $groupId,
            // annual_target is always the sum of the resolved months (kept exact below).
            'annual_target' => 0,
            'allocation_method' => $method,
            'notes' => $raw['notes'] ?? null,
        ]);
    }

    /** Write 12 budget_line_periods from a resolved paise[] allocation; keep annual_target exact. */
    private function writePeriods(BudgetLine $line, array $monthsPaise, ?Carbon $revisedFrom): void
    {
        $stamp = $revisedFrom?->toDateString();
        $now = now();
        $rows = [];
        foreach ($monthsPaise as $i => $paise) {
            $rows[] = [
                'budget_line_id' => $line->id,
                'month' => $i + 1,
                'target_amount' => $paise / 100,
                'revised_from' => $stamp,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        BudgetLinePeriod::insert($rows);

        if ($revisedFrom === null) {
            // The original allocation defines the line's headline annual figure.
            $line->forceFill(['annual_target' => array_sum($monthsPaise) / 100])->save();
        }
    }

    // ── allocation methods ───────────────────────────────────────────────────────

    /** Resolve a line's per-month targets (12 integer paise, summing exactly to the annual). */
    private function allocate(array $raw): array
    {
        $method = $raw['allocation_method'] ?? 'even';
        $annualPaise = (int) round((float) ($raw['annual_target'] ?? 0) * 100);
        $months = $raw['months'] ?? null;

        return match ($method) {
            'custom' => $this->fromRupeeMonths($months),
            'seasonal' => $this->fromWeights($annualPaise, $months),
            default => $this->spreadEven($annualPaise),
        };
    }

    /** annual / 12, remainder spread over the earliest months (sum is EXACT for any sign). */
    private function spreadEven(int $annualPaise): array
    {
        // Floor division (not intdiv, which truncates toward zero) so the remainder is 0..11 for a
        // negative annual too — the 12 months then still sum exactly to the annual.
        $base = (int) floor($annualPaise / 12);
        $rem = $annualPaise - $base * 12; // 0..11 for either sign
        $out = [];
        for ($i = 0; $i < 12; $i++) {
            $out[$i] = $base + ($i < $rem ? 1 : 0);
        }

        return $out;
    }

    /** Explicit per-month rupee amounts (custom). Missing months = 0; padded/truncated to 12. */
    private function fromRupeeMonths(?array $months): array
    {
        if ($months === null) {
            throw new InvalidArgumentException('Custom allocation needs a 12-month amount array.');
        }
        $out = [];
        for ($i = 0; $i < 12; $i++) {
            $out[$i] = (int) round((float) ($months[$i] ?? 0) * 100);
        }

        return $out;
    }

    /** Percentage weights (seasonal) resolved to paise; the rounding remainder lands on the largest month. */
    private function fromWeights(int $annualPaise, ?array $weights): array
    {
        if ($weights === null) {
            throw new InvalidArgumentException('Seasonal allocation needs a 12-month weight array.');
        }
        $totalW = 0.0;
        for ($i = 0; $i < 12; $i++) {
            $totalW += (float) ($weights[$i] ?? 0);
        }
        if ($totalW <= 0) {
            throw new InvalidArgumentException('Seasonal weights must sum to a positive value.');
        }

        $out = [];
        for ($i = 0; $i < 12; $i++) {
            $out[$i] = (int) round($annualPaise * ((float) ($weights[$i] ?? 0)) / $totalW);
        }
        // Correct any rounding drift so the months sum exactly to the annual.
        $diff = $annualPaise - array_sum($out);
        if ($diff !== 0) {
            $maxIdx = 0;
            for ($i = 1; $i < 12; $i++) {
                if ($out[$i] > $out[$maxIdx]) {
                    $maxIdx = $i;
                }
            }
            $out[$maxIdx] += $diff;
        }

        return $out;
    }

    // ── revision ──────────────────────────────────────────────────────────────────

    /**
     * Revise a budget from an effective date forward. Historical months (before the effective
     * fiscal month) keep their ORIGINAL targets untouched; from the effective month on, new
     * budget_line_periods carry the revision date. An audit row is always written.
     *
     * @param  array<int,array{annual_target?:float|int,allocation_method?:string,months?:?array<float|int>}>  $newLines  keyed by budget_line_id
     */
    public function reviseBudget(Budget $budget, array $newLines, Carbon $effectiveFrom, ?int $userId = null, ?string $notes = null): BudgetRevision
    {
        $fyStart = (int) $budget->fiscal_year_start;
        $fyOpen = Voucher::fyOpenFor($fyStart);
        $fyEnd = $fyOpen->copy()->addYear()->subDay();

        // A revision effective after the fiscal year has ended affects no month of this budget —
        // reject it rather than silently clamping onto (and overwriting) the final month.
        if ($effectiveFrom->gt($fyEnd)) {
            throw new InvalidArgumentException('The effective date is after this budget\'s fiscal year — nothing to revise.');
        }

        // Revisions must be chronological: a new one cannot pre-date the latest existing revision,
        // or a back-dated entry would be silently shadowed by a forward-dated one already in place.
        $latest = $budget->revisions()->max('revised_at');
        if ($latest !== null && $effectiveFrom->lt(Carbon::parse($latest))) {
            throw new InvalidArgumentException('A later revision (effective '.Carbon::parse($latest)->toDateString().') already exists — a new revision must be on or after it.');
        }

        return DB::transaction(function () use ($budget, $newLines, $effectiveFrom, $userId, $notes, $fyStart) {
            $effMonth = $this->fyMonthIndexOf($effectiveFrom, $fyStart);

            $revision = BudgetRevision::create([
                'budget_id' => $budget->id,
                'revised_at' => $effectiveFrom->toDateString(),
                'revised_by_user_id' => $userId,
                'notes' => $notes,
            ]);

            foreach ($newLines as $lineId => $raw) {
                /** @var BudgetLine|null $line */
                $line = $budget->lines()->whereKey($lineId)->first();
                if (! $line) {
                    throw new InvalidArgumentException("Budget line #{$lineId} is not part of this budget.");
                }

                $monthsPaise = $this->allocate(array_merge([
                    'allocation_method' => $line->allocation_method,
                ], $raw));

                // Idempotent re-revision on the same effective date: clear this date's rows first.
                BudgetLinePeriod::where('budget_line_id', $line->id)
                    ->whereDate('revised_from', $effectiveFrom->toDateString())
                    ->delete();

                // Only months FROM the effective boundary forward are rewritten.
                $now = now();
                $rows = [];
                for ($m = $effMonth; $m <= 12; $m++) {
                    $rows[] = [
                        'budget_line_id' => $line->id,
                        'month' => $m,
                        'target_amount' => $monthsPaise[$m - 1] / 100,
                        'revised_from' => $effectiveFrom->toDateString(),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                if ($rows !== []) {
                    BudgetLinePeriod::insert($rows);
                }
            }

            return $revision;
        });
    }

    // ── variance ────────────────────────────────────────────────────────────────

    /**
     * Per-line target vs actual for a period range. Returns, per budget line:
     * target (current, post-revision), original_target, actual, variance, variance_pct,
     * is_favorable, nature, plus drill metadata. With $includeUntracked, income/expense
     * ledgers with NO budget line are appended as "No target" rows (target null, not 0).
     *
     * @return array{lines: array<int,array>, months: array<int>, from: string, to: string}
     */
    public function variance(Budget $budget, Carbon $from, Carbon $to, bool $includeUntracked = false): array
    {
        $fyStart = (int) $budget->fiscal_year_start;

        // Clamp the ACTUALS window to the budget's fiscal year so actual and target measure the
        // SAME span — the target side is already capped at the 12 fiscal months by coveredMonths().
        // Without this, a report window spilling into an adjacent FY would count out-of-FY activity
        // against a 12-month target and read a phantom favorable/unfavorable variance.
        $fyOpen = Voucher::fyOpenFor($fyStart);
        $fyEnd = $fyOpen->copy()->addYear()->subDay();
        $aFrom = $from->lt($fyOpen) ? $fyOpen->copy() : $from->copy();
        $aTo = $to->gt($fyEnd) ? $fyEnd->copy() : $to->copy();
        $outsideFy = $aTo->lt($aFrom); // the whole window is outside this budget's FY

        $months = $outsideFy ? [] : $this->coveredMonths($fyStart, $aFrom, $aTo);
        $ledgerBal = $outsideFy ? [] : $this->balances->ledgerBalances($aFrom, $aTo); // id => [...,'net'=>paise]
        $tree = $outsideFy ? [] : $this->balances->tree($aFrom, $aTo);
        $natureByGroup = AccountGroup::pluck('nature', 'id')->all();

        $budget->load(['lines.ledger.group', 'lines.accountGroup', 'lines.periods']);

        $rows = [];
        $trackedLedgerIds = [];
        $groupCoveredLedgers = []; // ledgers rolled up inside a budgeted GROUP line

        foreach ($budget->lines as $line) {
            $nature = $line->nature();

            if ($line->isGroupLine()) {
                $node = $this->findGroupNode($tree, (int) $line->account_group_id);
                $rawNet = $node['net'] ?? 0;
                $drill = null;
                $drillLabel = $line->targetName();
                // A group covers all its descendant ledgers — mark them tracked + group-covered.
                foreach ($this->descendantLedgerIds((int) $line->account_group_id) as $lid) {
                    $trackedLedgerIds[$lid] = true;
                    $groupCoveredLedgers[$lid] = true;
                }
            } else {
                $trackedLedgerIds[$line->ledger_id] = true;
                $rawNet = $ledgerBal[$line->ledger_id]['net'] ?? 0;
                $drill = (int) $line->ledger_id;
                $drillLabel = $line->targetName();
            }

            $actual = $this->normalise($nature, (int) $rawNet);
            [$current, $original] = $this->targetPaise($line, $months);

            $rows[] = $this->row(
                key: ($line->isGroupLine() ? 'gl' : 'll').$line->id,
                lineId: $line->id,
                name: $drillLabel,
                kind: $line->isGroupLine() ? 'group' : 'ledger',
                nature: $nature,
                target: $current,
                original: $original,
                actual: $actual,
                drillLedgerId: $drill,
                noTarget: false,
            );
        }

        // A ledger line whose ledger is ALSO rolled up by a budgeted group line would be counted
        // twice in any total — flag it so aggregation (report totals + summary) skips it. Its own
        // per-line variance still shows.
        foreach ($rows as &$r) {
            if ($r['kind'] === 'ledger' && ! $r['no_target'] && $r['drill_ledger_id'] !== null
                && isset($groupCoveredLedgers[$r['drill_ledger_id']])) {
                $r['redundant_in_total'] = true;
            }
        }
        unset($r);

        if ($includeUntracked) {
            foreach ($ledgerBal as $lid => $lb) {
                if (isset($trackedLedgerIds[$lid])) {
                    continue;
                }
                $nature = $natureByGroup[$lb['group_id']] ?? null;
                if (! in_array($nature, ['Income', 'Expenses'], true)) {
                    continue; // only surface P&L ledgers as "No target"
                }
                if (($lb['net'] ?? 0) === 0) {
                    continue; // no activity + no target => nothing to show
                }
                $rows[] = $this->row(
                    key: 'un'.$lid,
                    lineId: null,
                    name: $lb['name'],
                    kind: 'ledger',
                    nature: $nature,
                    target: null,
                    original: null,
                    actual: $this->normalise($nature, (int) $lb['net']),
                    drillLedgerId: (int) $lid,
                    noTarget: true,
                );
            }
        }

        return [
            'lines' => $rows,
            'months' => $months,
            'from' => $aFrom->toDateString(),
            'to' => ($outsideFy ? $aFrom : $aTo)->toDateString(),
        ];
    }

    /** One variance row (all money in paise; null target = "No target"). */
    private function row(string $key, ?int $lineId, string $name, string $kind, ?string $nature, ?int $target, ?int $original, int $actual, ?int $drillLedgerId, bool $noTarget): array
    {
        $variance = $target === null ? null : $actual - $target;
        $variancePct = ($target === null || $target === 0) ? null : round(($variance / $target) * 100, 2);

        return [
            'key' => $key,
            'line_id' => $lineId,
            'name' => $name,
            'kind' => $kind,
            'nature' => $nature,
            'no_target' => $noTarget,
            'target' => $target,
            'original_target' => $original,
            'actual' => $actual,
            'variance' => $variance,
            'variance_pct' => $variancePct,
            'is_favorable' => $variance === null ? null : $this->isFavorable($nature, $variance),
            'revised' => $target !== null && $original !== null && $target !== $original,
            'drill_ledger_id' => $drillLedgerId,
            // Set true later for a ledger line already rolled up by a budgeted group line, so
            // totals/summary count it once.
            'redundant_in_total' => false,
        ];
    }

    // ── summary ──────────────────────────────────────────────────────────────────

    /** Single-page roll-up: revenue / expense budget-vs-actual, projected vs actual net profit. */
    public function summary(Budget $budget, Carbon $asOf): array
    {
        $fyStart = (int) $budget->fiscal_year_start;
        $from = Voucher::fyOpenFor($fyStart);
        $fyEnd = $from->copy()->addYear()->subDay();
        // Keep the as-of inside the fiscal year: not before it opens, not after it closes — so the
        // YTD roll-up never counts activity from an adjacent FY against this budget's targets.
        if ($asOf->lt($from)) {
            $asOf = $from->copy();
        } elseif ($asOf->gt($fyEnd)) {
            $asOf = $fyEnd->copy();
        }

        $v = $this->variance($budget, $from, $asOf, false);

        $revBudget = $revActual = $expBudget = $expActual = 0;
        foreach ($v['lines'] as $r) {
            if ($r['redundant_in_total']) {
                continue; // a ledger already rolled up by a budgeted group — count once
            }
            if ($r['nature'] === 'Income') {
                $revBudget += (int) ($r['target'] ?? 0);
                $revActual += (int) $r['actual'];
            } elseif ($r['nature'] === 'Expenses') {
                $expBudget += (int) ($r['target'] ?? 0);
                $expActual += (int) $r['actual'];
            }
        }

        $projectedNet = $revBudget - $expBudget;
        $actualNet = $revActual - $expActual;

        return [
            'from' => $from->toDateString(),
            'as_of' => $asOf->toDateString(),
            'revenue' => $this->pair($revBudget, $revActual, 'Income'),
            'expense' => $this->pair($expBudget, $expActual, 'Expenses'),
            'net_profit' => [
                'budget' => $projectedNet,
                'actual' => $actualNet,
                'variance' => $actualNet - $projectedNet,
                'is_favorable' => ($actualNet - $projectedNet) >= 0,
            ],
        ];
    }

    private function pair(int $budget, int $actual, string $nature): array
    {
        $variance = $actual - $budget;

        return [
            'budget' => $budget,
            'actual' => $actual,
            'variance' => $variance,
            'variance_pct' => $budget === 0 ? null : round(($variance / $budget) * 100, 2),
            'is_favorable' => $this->isFavorable($nature, $variance),
        ];
    }

    // ── period / sign helpers ─────────────────────────────────────────────────────

    /**
     * The effective (current, post-revision) target and the ORIGINAL target, summed over the
     * given fiscal months, in paise. For each month the effective row is the one with the
     * greatest revised_from ≤ that month (null = original, the earliest).
     *
     * @return array{0:int,1:int} [current, original]
     */
    private function targetPaise(BudgetLine $line, array $months): array
    {
        $byMonth = [];
        foreach ($line->periods as $p) {
            $byMonth[$p->month][] = $p;
        }

        $current = 0;
        $original = 0;
        foreach ($months as $m) {
            $rows = $byMonth[$m] ?? [];
            if ($rows === []) {
                continue;
            }
            $orig = null;
            $eff = null;
            foreach ($rows as $p) {
                if ($p->revised_from === null) {
                    $orig = $p;
                }
                if ($eff === null
                    || ($p->revised_from !== null && ($eff->revised_from === null || $p->revised_from->gt($eff->revised_from)))) {
                    $eff = $p;
                }
            }
            $current += (int) round((float) $eff->target_amount * 100);
            $original += (int) round((float) (($orig ?? $eff)->target_amount) * 100);
        }

        return [$current, $original];
    }

    /** Fiscal-month ordinals (1..12) whose calendar month overlaps [from,to]. */
    private function coveredMonths(int $fyStart, Carbon $from, Carbon $to): array
    {
        $open = Voucher::fyOpenFor($fyStart);
        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $mStart = $open->copy()->addMonthsNoOverflow($m - 1);
            $mEnd = $mStart->copy()->endOfMonth();
            if ($mStart->lte($to) && $mEnd->gte($from)) {
                $months[] = $m;
            }
        }

        return $months;
    }

    /**
     * Which fiscal-month ordinal a date falls in for the given FY. A date before the FY opens
     * clamps to month 1 (a revision then rewrites the whole year); a date after the FY ends
     * returns >12 so the reviseBudget loop writes no rows (it never overwrites month 12).
     */
    private function fyMonthIndexOf(Carbon $date, int $fyStart): int
    {
        $open = Voucher::fyOpenFor($fyStart);
        $idx = ($date->year - $open->year) * 12 + ($date->month - $open->month) + 1;

        return max(1, $idx);
    }

    /** Dr-terms paise → natural positive magnitude for the nature (Income/Liabilities flip). */
    private function normalise(?string $nature, int $drTermsPaise): int
    {
        return $drTermsPaise * $this->sign($nature);
    }

    private function sign(?string $nature): int
    {
        return match ($nature) {
            'Income', 'Liabilities' => -1,
            default => 1, // Expenses, Assets
        };
    }

    /** Favorable = Income/Assets up (actual ≥ target); Expenses/Liabilities down (actual ≤ target). */
    private function isFavorable(?string $nature, int $variance): bool
    {
        return in_array($nature, ['Income', 'Assets'], true) ? $variance >= 0 : $variance <= 0;
    }

    // ── tree / group helpers ──────────────────────────────────────────────────────

    /** Find a group node by id in a BalanceService::tree() forest (recurses children). */
    private function findGroupNode(array $nodes, int $groupId): ?array
    {
        foreach ($nodes as $node) {
            if (($node['type'] ?? null) === 'group' && (int) ($node['id'] ?? 0) === $groupId) {
                return $node;
            }
            if (! empty($node['children'])) {
                $found = $this->findGroupNode($node['children'], $groupId);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * All ledger ids under a group (recursing sub-groups), for group-coverage / untracked. Memoised
     * per ACTIVE COMPANY (not a process-global static) so a second company's variance in the same
     * process never sees the first company's group/ledger tree.
     */
    private array $treeMemo = [];

    private function descendantLedgerIds(int $groupId): array
    {
        $company = \App\Support\ActiveCompany::id() ?? 0;
        if (! isset($this->treeMemo[$company])) {
            $childrenOf = [];
            foreach (AccountGroup::get(['id', 'parent_id']) as $g) {
                $childrenOf[$g->parent_id ?? 0][] = $g->id;
            }
            $ledgersOf = [];
            foreach (Ledger::whereNotNull('group_id')->get(['id', 'group_id']) as $l) {
                $ledgersOf[$l->group_id][] = $l->id;
            }
            $this->treeMemo[$company] = ['children' => $childrenOf, 'ledgers' => $ledgersOf];
        }
        $childrenOf = $this->treeMemo[$company]['children'];
        $ledgersOf = $this->treeMemo[$company]['ledgers'];

        $ids = [];
        $stack = [$groupId];
        while ($stack !== []) {
            $g = array_pop($stack);
            foreach ($ledgersOf[$g] ?? [] as $lid) {
                $ids[] = $lid;
            }
            foreach ($childrenOf[$g] ?? [] as $child) {
                $stack[] = $child;
            }
        }

        return $ids;
    }
}
