<?php

namespace App\Services;

use App\Models\AccountGroup;
use App\Models\Ledger;
use App\Models\Voucher;
use App\Models\VoucherEntry;
use App\Services\StockService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The single authoritative balance engine for ZeroBook. Every report (Trial
 * Balance, Balance Sheet, Profit & Loss) and the voucher current-balance display
 * calls this — the accounting math lives here and nowhere else.
 *
 * Convention: everything is computed in INTEGER PAISE in "Dr terms" (Dr positive,
 * Cr negative), so a closing of +50000 means Dr 500.00 and -50000 means Cr 500.00.
 * Rounding to rupees happens only at the presentation edge.
 */
class BalanceService
{
    /**
     * Primary income/expense roots that belong ABOVE the Gross Profit line (the
     * Trading account). Everything else of Income/Expense nature is Indirect and
     * sits below it. Phase 6D uses this only to split the P&L for Gross Profit;
     * the Net-Profit total is unaffected by the split.
     */
    public const TRADING_ROOTS = ['Sales Accounts', 'Purchase Accounts', 'Direct Incomes', 'Direct Expenses'];

    /**
     * Per-ledger balances for the period (ledgers with a real group only; the
     * special Profit & Loss A/c has no group and is represented by computed
     * profit instead).
     *
     * @return array<int, array{id:int,name:string,group_id:int,opening:int,net:int,closing:int}>
     */
    public function ledgerBalances(Carbon $from, Carbon $to): array
    {
        $ledgers = Ledger::whereNotNull('group_id')->orderBy('name')->get();

        // Opening as of `from` = book opening + all movement strictly before `from`.
        $priorNet = $this->netByLedger(null, $from->copy()->subDay());
        $periodNet = $this->netByLedger($from, $to);

        $out = [];
        foreach ($ledgers as $l) {
            $opening = $this->openingPaise($l) + ($priorNet[$l->id] ?? 0);
            $net = $periodNet[$l->id] ?? 0;
            $out[$l->id] = [
                'id' => $l->id,
                'name' => $l->name,
                'group_id' => (int) $l->group_id,
                'opening' => $opening,
                'net' => $net,
                'closing' => $opening + $net,
            ];
        }

        return $out;
    }

    /** Sum of movement per ledger (Dr positive) between two dates, in paise. */
    private function netByLedger(?Carbon $from, ?Carbon $to): array
    {
        $q = VoucherEntry::query()
            ->join('vouchers', 'vouchers.id', '=', 'voucher_entries.voucher_id');
        // Phase 15C — the single scenario choke point: every balance figure funnels through here,
        // so real-books-only (default) or the selected scenarios are applied once for the whole engine.
        \App\Support\ScenarioContext::apply($q, 'vouchers');
        // Raw-column range comparisons (not whereDate) so the index on
        // vouchers.date is usable — vouchers.date is a DATE column, so a plain
        // '>='/'<=' against the yyyy-mm-dd string is an exact, index-friendly
        // bound with no DATE() wrapper around the column.
        if ($from) {
            $q->where('vouchers.date', '>=', $from->toDateString());
        }
        if ($to) {
            $q->where('vouchers.date', '<=', $to->toDateString());
        }
        $rows = $q->select('voucher_entries.ledger_id')
            ->selectRaw("SUM(CASE WHEN voucher_entries.dr_cr = 'Dr' THEN voucher_entries.amount ELSE -voucher_entries.amount END) AS net")
            ->groupBy('voucher_entries.ledger_id')
            ->get();

        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r->ledger_id] = (int) round(((float) $r->net) * 100);
        }

        return $map;
    }

    private function openingPaise(Ledger $l): int
    {
        $amt = (int) round(((float) $l->opening_balance) * 100);

        return $l->opening_balance_type === 'Cr' ? -$amt : $amt;
    }

    /**
     * The full account-group tree with per-node opening/net/closing rolled up
     * from child groups and ledgers. Every node is Dr-terms paise.
     */
    public function tree(Carbon $from, Carbon $to): array
    {
        $lb = $this->ledgerBalances($from, $to);
        $groups = AccountGroup::orderBy('sort_order')->orderBy('name')->get();

        $childrenOf = [];
        foreach ($groups as $g) {
            $childrenOf[$g->parent_id ?? 0][] = $g;
        }
        $ledgersOfGroup = [];
        foreach ($lb as $L) {
            $ledgersOfGroup[$L['group_id']][] = $L;
        }

        $build = function (AccountGroup $g) use (&$build, $childrenOf, $ledgersOfGroup) {
            $node = [
                'type' => 'group',
                'id' => $g->id,
                'name' => $g->name,
                'nature' => $g->nature,
                'is_primary' => (bool) $g->is_primary,
                'opening' => 0,
                'net' => 0,
                'closing' => 0,
                'children' => [],
                'ledgers' => [],
            ];
            foreach (($childrenOf[$g->id] ?? []) as $child) {
                $cn = $build($child);
                $node['children'][] = $cn;
                $node['opening'] += $cn['opening'];
                $node['net'] += $cn['net'];
                $node['closing'] += $cn['closing'];
            }
            foreach (($ledgersOfGroup[$g->id] ?? []) as $L) {
                $node['ledgers'][] = array_merge($L, ['type' => 'ledger']);
                $node['opening'] += $L['opening'];
                $node['net'] += $L['net'];
                $node['closing'] += $L['closing'];
            }

            return $node;
        };

        $roots = [];
        foreach (($childrenOf[0] ?? []) as $primary) {
            $roots[] = $build($primary);
        }

        return $roots;
    }

    /** Roots filtered to a set of natures, in a stable order. */
    private function rootsByNature(array $roots, array $natures): array
    {
        return array_values(array_filter($roots, fn ($n) => in_array($n['nature'], $natures, true)));
    }

    private function sumClosingByNature(array $lb, array $groupNature, string $nature, int $sign): int
    {
        $total = 0;
        foreach ($lb as $L) {
            if (($groupNature[$L['group_id']] ?? null) === $nature) {
                $total += $sign * $L['closing'];
            }
        }

        return $total;
    }

    /** map group_id => nature (denormalised nature is stored on every group). */
    private function groupNatureMap(): array
    {
        return AccountGroup::pluck('nature', 'id')->map(fn ($n) => (string) $n)->all();
    }

    // ---- Reports -------------------------------------------------------------

    public function trialBalance(Carbon $from, Carbon $to): array
    {
        $roots = $this->tree($from, $to);
        $lb = $this->ledgerBalances($from, $to);

        $totalDr = 0;
        $totalCr = 0;
        foreach ($lb as $L) {
            if ($L['closing'] > 0) {
                $totalDr += $L['closing'];
            } elseif ($L['closing'] < 0) {
                $totalCr += -$L['closing'];
            }
        }

        return [
            'roots' => $roots,
            'total_dr' => $totalDr,
            'total_cr' => $totalCr,
            'balanced' => $totalDr === $totalCr,
        ];
    }

    public function profitAndLoss(Carbon $from, Carbon $to): array
    {
        $roots = $this->tree($from, $to);
        $lb = $this->ledgerBalances($from, $to);
        $gn = $this->groupNatureMap();

        // Income is naturally Cr (closing negative) -> present positive as income.
        $totalIncome = $this->sumClosingByNature($lb, $gn, 'Income', -1);
        // Expenses naturally Dr (closing positive).
        $totalExpenses = $this->sumClosingByNature($lb, $gn, 'Expenses', 1);
        $ledgerNet = $totalIncome - $totalExpenses; // Phase 4 ledger-only net, preserved

        // Split the income/expense roots into TRADING (Sales/Purchase/Direct — the
        // Gross Profit account) and INDIRECT (below the Gross Profit line), by the
        // seeded root name. closing is Dr-terms paise: income roots negative.
        $incomeRoots = $this->rootsByNature($roots, ['Income']);
        $expenseRoots = $this->rootsByNature($roots, ['Expenses']);
        $isTrading = fn ($n) => in_array($n['name'], self::TRADING_ROOTS, true);
        $tradingIncomeRoots = array_values(array_filter($incomeRoots, $isTrading));
        $indirectIncomeRoots = array_values(array_filter($incomeRoots, fn ($n) => ! $isTrading($n)));
        $tradingExpenseRoots = array_values(array_filter($expenseRoots, $isTrading));
        $indirectExpenseRoots = array_values(array_filter($expenseRoots, fn ($n) => ! $isTrading($n)));

        $tradingIncome = (int) array_sum(array_map(fn ($n) => -$n['closing'], $tradingIncomeRoots));
        $tradingExpense = (int) array_sum(array_map(fn ($n) => $n['closing'], $tradingExpenseRoots));
        $indirectIncome = $totalIncome - $tradingIncome;
        $indirectExpense = $totalExpenses - $tradingExpense;

        // Phase 6D inventory correction. Opening Stock is period-independent (Σ item
        // opening_value); Closing Stock is as of the period end. Net Profit picks up
        // the correction term (closing − opening); this reduces EXACTLY to the Phase 4
        // ledger net when a company has no stock (both terms zero).
        $stock = app(StockService::class);
        $openingStock = (int) round($stock->totalOpeningValue() * 100);
        $closingStock = (int) round($stock->totalClosingValue($to) * 100);

        $grossProfit = ($tradingIncome + $closingStock) - ($tradingExpense + $openingStock);
        $net = $grossProfit + $indirectIncome - $indirectExpense; // == ledgerNet + (closing − opening)

        return [
            'expense_roots' => $expenseRoots,
            'income_roots' => $incomeRoots,
            'trading_expense_roots' => $tradingExpenseRoots,
            'trading_income_roots' => $tradingIncomeRoots,
            'indirect_expense_roots' => $indirectExpenseRoots,
            'indirect_income_roots' => $indirectIncomeRoots,
            'total_income' => $totalIncome,
            'total_expenses' => $totalExpenses,
            'trading_income' => $tradingIncome,
            'trading_expense' => $tradingExpense,
            'indirect_income' => $indirectIncome,
            'indirect_expense' => $indirectExpense,
            'opening_stock' => $openingStock,
            'closing_stock' => $closingStock,
            'gross_profit' => $grossProfit,
            'ledger_net' => $ledgerNet,   // Phase-4 ledger-only Net Profit (unchanged, callable on its own)
            'net' => $net,                // inventory-adjusted Net Profit (shared with the Balance Sheet)
            'is_profit' => $net >= 0,
        ];
    }

    public function balanceSheet(Carbon $from, Carbon $to): array
    {
        $roots = $this->tree($from, $to);
        $lb = $this->ledgerBalances($from, $to);
        $gn = $this->groupNatureMap();

        $totalAssetsLedger = $this->sumClosingByNature($lb, $gn, 'Assets', 1);       // Dr
        $totalLiabilities = $this->sumClosingByNature($lb, $gn, 'Liabilities', -1); // Cr

        $pl = $this->profitAndLoss($from, $to);
        $net = $pl['net'];                    // inventory-adjusted Net Profit (shared with P&L)
        $closingStock = $pl['closing_stock']; // paise, as of $to

        // Phase 6D — Stock-in-Hand. Add the computed closing-stock value INTO the
        // seeded "Stock-in-Hand" group node (additive to any ledger rollup already
        // there — never clobbered) so it shows in place and rolls up, and into the
        // assets total so the sheet balances with inventory. A no-stock company
        // skips this entirely and the output is byte-identical to before.
        $assetRoots = $this->rootsByNature($roots, ['Assets']);
        if ($closingStock !== 0) {
            foreach ($assetRoots as &$assetRoot) {
                $this->injectStockValue($assetRoot, $closingStock);
            }
            unset($assetRoot);
        }
        $totalAssets = $totalAssetsLedger + $closingStock;

        // Nett Profit sits on the liabilities side; a Nett Loss on the assets side.
        $liabSide = $totalLiabilities + max($net, 0);
        $assetSide = $totalAssets + max(-$net, 0);

        // Force the statement to balance with a "Difference in opening balances"
        // line on whichever side is short (exactly as Tally does).
        $diff = $assetSide - $liabSide;
        $liabDiff = $diff > 0 ? $diff : 0;
        $assetDiff = $diff < 0 ? -$diff : 0;

        return [
            'liability_roots' => $this->rootsByNature($roots, ['Liabilities']),
            'asset_roots' => $assetRoots, // Stock-in-Hand already folded in (Phase 6D)
            'total_liabilities' => $totalLiabilities,
            'total_assets' => $totalAssets,
            'closing_stock' => $closingStock,
            'net' => $net,
            'is_profit' => $net >= 0,
            'liability_diff' => $liabDiff,
            'asset_diff' => $assetDiff,
            'liability_total' => $liabSide + $liabDiff,
            'asset_total' => $assetSide + $assetDiff,
            'balanced' => ($liabSide + $liabDiff) === ($assetSide + $assetDiff),
        ];
    }

    /**
     * Add $amount (paise) to a "Stock-in-Hand" group node and every ancestor on
     * the path to it, so the Balance-Sheet inventory value shows in place and rolls
     * up through Current Assets to the Assets total. Returns true if the target
     * group was found in this subtree. (Phase 6D — additive, never clobbers.)
     */
    private function injectStockValue(array &$node, int $amount): bool
    {
        if ($node['name'] === 'Stock-in-Hand') {
            $node['closing'] += $amount;

            return true;
        }
        $found = false;
        foreach ($node['children'] as &$child) {
            if ($this->injectStockValue($child, $amount)) {
                $found = true;
            }
        }
        unset($child);
        if ($found) {
            $node['closing'] += $amount;
        }

        return $found;
    }

    /**
     * The rows for a single ledger's "Ledger Vouchers" drill screen — one row
     * per voucher touching the ledger in the period, with the ledger's Dr/Cr and
     * a running balance (Dr terms, paise).
     */
    public function ledgerVouchers(int $ledgerId, Carbon $from, Carbon $to): array
    {
        $ledger = Ledger::find($ledgerId);
        if (! $ledger) {
            return ['ledger' => null, 'rows' => [], 'opening' => 0, 'closing' => 0];
        }

        $priorNet = $this->netByLedger(null, $from->copy()->subDay());
        $opening = $this->openingPaise($ledger) + ($priorNet[$ledgerId] ?? 0);

        $entries = VoucherEntry::query()
            ->where('voucher_entries.ledger_id', $ledgerId)
            ->join('vouchers', 'vouchers.id', '=', 'voucher_entries.voucher_id')
            // Phase 15C — the Ledger Vouchers drill bypasses netByLedger's row list, so it filters here too.
            ->tap(fn ($q) => \App\Support\ScenarioContext::apply($q, 'vouchers'))
            // Index-friendly raw-column range (see netByLedger) — no DATE() wrap.
            ->where('vouchers.date', '>=', $from->toDateString())
            ->where('vouchers.date', '<=', $to->toDateString())
            ->orderBy('vouchers.date')
            ->orderBy('vouchers.id')
            ->select('voucher_entries.*', 'vouchers.date as v_date', 'vouchers.type as v_type', 'vouchers.number as v_number')
            ->with('voucher')
            ->get();

        $running = $opening;
        $rows = [];
        foreach ($entries as $e) {
            $paise = (int) round(((float) $e->amount) * 100);
            $signed = $e->dr_cr === 'Dr' ? $paise : -$paise;
            $running += $signed;

            $voucher = $e->voucher;
            $counter = $voucher
                ? $voucher->entries->where('id', '!=', $e->id)->pluck('ledger.name')->filter()->implode(', ')
                : '';

            $rows[] = [
                'voucher_id' => (int) $e->voucher_id,
                'date' => Carbon::parse($e->v_date)->format('d-M-Y'),
                'type' => $e->v_type,
                'type_label' => Voucher::TYPES[$e->v_type]['label'] ?? ucfirst($e->v_type),
                'number' => (int) $e->v_number,
                'display_number' => strtoupper(Voucher::TYPES[$e->v_type]['abbr'] ?? $e->v_type).'-'.$e->v_number,
                'particulars' => $counter !== '' ? $counter : '(self)',
                'dr_cr' => $e->dr_cr,
                'amount' => $paise,
                'running' => $running,
            ];
        }

        return [
            'ledger' => ['id' => $ledger->id, 'name' => $ledger->name],
            'rows' => $rows,
            'opening' => $opening,
            'closing' => $running,
        ];
    }

    /**
     * A light per-ledger closing balance as of a date, for the voucher-line
     * current-balance display. Returns all ledgers keyed by id (paise, Dr terms).
     *
     * @return array<int,int>
     */
    public function ledgerClosings(Carbon $asOf): array
    {
        $ledgers = Ledger::whereNotNull('group_id')->get();
        $net = $this->netByLedger(null, $asOf);
        $out = [];
        foreach ($ledgers as $l) {
            $out[$l->id] = $this->openingPaise($l) + ($net[$l->id] ?? 0);
        }

        return $out;
    }

    // ---- Presentation helpers (used by views) -------------------------------

    public static function money(int $paise): string
    {
        return number_format(abs($paise) / 100, 2);
    }

    public static function drcr(int $closingDr): string
    {
        if ($closingDr > 0) {
            return 'Dr';
        }
        if ($closingDr < 0) {
            return 'Cr';
        }

        return '';
    }

    /** @return array{amount:string,side:string} */
    public static function present(int $closingDr): array
    {
        return ['amount' => self::money($closingDr), 'side' => self::drcr($closingDr)];
    }

    public function withinFy(?string $from, ?string $to): array
    {
        $today = Carbon::today();
        $fyStart = Voucher::fyStartFor($today);
        $f = $from ? Carbon::parse($from) : Voucher::fyOpenFor($fyStart);
        $t = $to ? Carbon::parse($to) : $today;

        return [$f, $t];
    }
}
