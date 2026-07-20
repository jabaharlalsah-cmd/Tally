<?php

namespace App\Services;

use App\Models\AccountGroup;
use App\Models\Budget;
use App\Models\CompanyFeature;
use App\Models\RatioThreshold;
use App\Models\Voucher;
use Carbon\Carbon;

/**
 * Phase 15B — the Ratio Analysis engine.
 *
 * A read-only COMPOSITION of BalanceService figures: every numerator and denominator traces to a
 * BalanceService call (balanceSheet for the sheet, profitAndLoss for the P&L), so a ratio is
 * automatically correct whenever the Trial Balance is. Nothing re-sums vouchers.
 *
 * All BalanceService figures are integer PAISE in Dr-terms (Assets/Expenses positive, Liabilities/
 * Income/Capital negative); this service sign-normalises each figure to a positive magnitude before
 * composing. Ratios themselves are dimensionless, so paise/paise needs no unit conversion.
 *
 * Divide-by-zero returns null (rendered "N/A") with a reason. Negative denominators that are
 * MEANINGFUL (negative equity = deep-loss position) still compute and flag red; only a genuinely
 * undefined ratio (zero denominator) is N/A.
 */
class RatioService
{
    /** ratio_key => [label, group, direction, unit]. Direction drives health + delta favourability. */
    public const RATIOS = [
        'current_ratio' => ['Current Ratio', 'liquidity', 'higher_better', 'x'],
        'quick_ratio' => ['Quick Ratio (Acid Test)', 'liquidity', 'higher_better', 'x'],
        'cash_ratio' => ['Cash Ratio', 'liquidity', 'higher_better', 'x'],
        'debt_to_equity' => ['Debt-to-Equity', 'solvency', 'lower_better', 'x'],
        'debt_to_assets' => ['Debt-to-Assets', 'solvency', 'lower_better', 'x'],
        'interest_coverage' => ['Interest Coverage', 'solvency', 'higher_better', 'x'],
        'gross_profit_margin' => ['Gross Profit Margin', 'profitability', 'higher_better', '%'],
        'operating_profit_margin' => ['Operating Profit Margin', 'profitability', 'higher_better', '%'],
        'net_profit_margin' => ['Net Profit Margin', 'profitability', 'higher_better', '%'],
        'roe' => ['Return on Equity (ROE)', 'profitability', 'higher_better', '%'],
        'roa' => ['Return on Assets (ROA)', 'profitability', 'higher_better', '%'],
        'inventory_turnover' => ['Inventory Turnover', 'efficiency', 'higher_better', 'x'],
        'debtor_days' => ['Debtor Days', 'efficiency', 'lower_better', 'd'],
        'creditor_days' => ['Creditor Days', 'efficiency', 'higher_better', 'd'],
        'budget_variance_pct' => ['Budget Variance %', 'budget', 'higher_better', '%'],
        'burn_rate' => ['Burn Rate', 'budget', 'lower_better', 'x'],
    ];

    /** Industry-neutral default colour bands (green_min / amber_min in the ratio's unit). */
    public const DEFAULT_THRESHOLDS = [
        'current_ratio' => ['green_min' => 1.5, 'amber_min' => 1.0],
        'quick_ratio' => ['green_min' => 1.0, 'amber_min' => 0.7],
        'cash_ratio' => ['green_min' => 0.5, 'amber_min' => 0.2],
        'debt_to_equity' => ['green_min' => 1.0, 'amber_min' => 2.0],
        'debt_to_assets' => ['green_min' => 0.5, 'amber_min' => 0.7],
        'interest_coverage' => ['green_min' => 3.0, 'amber_min' => 1.5],
        'gross_profit_margin' => ['green_min' => 30.0, 'amber_min' => 15.0],
        'operating_profit_margin' => ['green_min' => 15.0, 'amber_min' => 5.0],
        'net_profit_margin' => ['green_min' => 10.0, 'amber_min' => 3.0],
        'roe' => ['green_min' => 15.0, 'amber_min' => 5.0],
        'roa' => ['green_min' => 8.0, 'amber_min' => 3.0],
        'inventory_turnover' => ['green_min' => 4.0, 'amber_min' => 2.0],
        'debtor_days' => ['green_min' => 45.0, 'amber_min' => 60.0],
        'creditor_days' => ['green_min' => 30.0, 'amber_min' => 15.0],
        'budget_variance_pct' => ['green_min' => 0.0, 'amber_min' => -10.0],
        'burn_rate' => ['green_min' => 1.0, 'amber_min' => 1.1],
    ];

    /** ratio_key => the BalanceService group names whose ledgers feed it (for the drill-down). */
    public const RATIO_GROUPS = [
        'current_ratio' => ['Current Assets', 'Current Liabilities'],
        'quick_ratio' => ['Current Assets', 'Current Liabilities'],
        'cash_ratio' => ['Cash-in-Hand', 'Bank Accounts', 'Current Liabilities'],
        'debt_to_equity' => ['Loans (Liability)', 'Capital Account'],
        'debt_to_assets' => ['Loans (Liability)'],
        'interest_coverage' => ['Indirect Expenses'],
        'gross_profit_margin' => ['Sales Accounts', 'Purchase Accounts', 'Direct Expenses'],
        'operating_profit_margin' => ['Sales Accounts', 'Indirect Expenses'],
        'net_profit_margin' => ['Sales Accounts'],
        'roe' => ['Capital Account'],
        'roa' => ['Current Assets', 'Fixed Assets'],
        'inventory_turnover' => ['Stock-in-Hand', 'Purchase Accounts'],
        'debtor_days' => ['Sundry Debtors', 'Sales Accounts'],
        'creditor_days' => ['Sundry Creditors', 'Purchase Accounts'],
    ];

    private ?array $thresholdMemo = null;

    public function __construct(private BalanceService $balances)
    {
    }

    // ── public ratio groups ───────────────────────────────────────────────────────

    public function liquidity(Carbon $asOf): array
    {
        return $this->section(['current_ratio', 'quick_ratio', 'cash_ratio'], Voucher::fyOpenFor(Voucher::fyStartFor($asOf)), $asOf);
    }

    public function solvency(Carbon $asOf): array
    {
        return $this->section(['debt_to_equity', 'debt_to_assets', 'interest_coverage'], Voucher::fyOpenFor(Voucher::fyStartFor($asOf)), $asOf);
    }

    public function profitability(Carbon $from, Carbon $to): array
    {
        return $this->section(['gross_profit_margin', 'operating_profit_margin', 'net_profit_margin', 'roe', 'roa'], $from, $to);
    }

    public function efficiency(Carbon $from, Carbon $to): array
    {
        return $this->section(['inventory_turnover', 'debtor_days', 'creditor_days'], $from, $to);
    }

    /** Budget-dependent ratios — empty unless the 15A Budgets feature is on and a primary budget exists. */
    public function budgetRatios(Carbon $from, Carbon $to): array
    {
        if (! (bool) CompanyFeature::current()->budgets) {
            return [];
        }
        $budget = Budget::primaryForCompany();
        if (! $budget) {
            return [];
        }

        $sum = app(BudgetService::class)->summary($budget, $to);
        $out = [];

        // Budget Variance %: net position actual vs budget.
        $bNet = (int) $sum['net_profit']['budget'];
        $aNet = (int) $sum['net_profit']['actual'];
        $out[] = $this->ratioRow('budget_variance_pct',
            $bNet === 0 ? null : round((($aNet - $bNet) / abs($bNet)) * 100, 2),
            $bNet === 0 ? 'Primary budget net is zero — variance not computable.' : null,
            [
                ['label' => 'Actual net', 'value_paise' => $aNet, 'source' => 'Budget vs Actual'],
                ['label' => 'Budgeted net', 'value_paise' => $bNet, 'source' => 'Primary budget'],
            ], null);

        // Burn Rate: cumulative operating-expense actual / budget.
        $eB = (int) $sum['expense']['budget'];
        $eA = (int) $sum['expense']['actual'];
        $out[] = $this->ratioRow('burn_rate',
            $eB === 0 ? null : round($eA / $eB, 2),
            $eB === 0 ? 'No expense budget for the period — burn rate not applicable.' : null,
            [
                ['label' => 'Actual expense', 'value_paise' => $eA, 'source' => 'Budget vs Actual'],
                ['label' => 'Budgeted expense', 'value_paise' => $eB, 'source' => 'Primary budget'],
            ], null);

        return $out;
    }

    /** All four (or five) sections at once for the dashboard — computes current + prior figures once. */
    public function dashboard(Carbon $asOf): array
    {
        $from = Voucher::fyOpenFor(Voucher::fyStartFor($asOf));

        return [
            'as_of' => $asOf->toDateString(),
            'from' => $from->toDateString(),
            'liquidity' => $this->liquidity($asOf),
            'solvency' => $this->solvency($asOf),
            'profitability' => $this->profitability($from, $asOf),
            'efficiency' => $this->efficiency($from, $asOf),
            'budget' => $this->budgetRatios($from, $asOf),
        ];
    }

    /** One ratio with its traced inputs, for the drill-down screen. */
    public function detail(string $ratioKey, Carbon $asOf): ?array
    {
        if (! isset(self::RATIOS[$ratioKey])) {
            return null;
        }
        $from = Voucher::fyOpenFor(Voucher::fyStartFor($asOf));
        $group = self::RATIOS[$ratioKey][1];
        $rows = match ($group) {
            'liquidity' => $this->liquidity($asOf),
            'solvency' => $this->solvency($asOf),
            'profitability' => $this->profitability($from, $asOf),
            'efficiency' => $this->efficiency($from, $asOf),
            'budget' => $this->budgetRatios($from, $asOf),
            default => [],
        };

        foreach ($rows as $r) {
            if ($r['key'] === $ratioKey) {
                return $r;
            }
        }

        return null;
    }

    /**
     * The ledgers feeding a ratio, grouped by their source group, each drillable to its vouchers.
     *
     * @return array<int,array{group:string, ledgers:array<int,array{ledger_id:int,name:string,closing:int}>}>
     */
    public function contributingLedgers(string $ratioKey, Carbon $asOf): array
    {
        $from = Voucher::fyOpenFor(Voucher::fyStartFor($asOf));
        $bs = $this->balances->balanceSheet($from, $asOf);
        $pl = $this->balances->profitAndLoss($from, $asOf);
        $roots = array_merge($bs['asset_roots'], $bs['liability_roots'], $pl['income_roots'], $pl['expense_roots']);

        $out = [];
        foreach (self::RATIO_GROUPS[$ratioKey] ?? [] as $gname) {
            $node = $this->node($roots, $gname);
            if (! $node) {
                continue;
            }
            $ledgers = [];
            $this->collectLedgers($node, $ledgers);
            $out[] = [
                'group' => $gname,
                'closing' => (int) $node['closing'],
                'ledgers' => $ledgers,
            ];
        }

        return $out;
    }

    private function collectLedgers(array $node, array &$out): void
    {
        foreach ($node['ledgers'] ?? [] as $l) {
            $out[] = ['ledger_id' => (int) $l['id'], 'name' => $l['name'], 'closing' => (int) $l['closing']];
        }
        foreach ($node['children'] ?? [] as $child) {
            $this->collectLedgers($child, $out);
        }
    }

    /** Sparkline data: the ratio value at each of the last $periods month-ends (≤ available history). */
    public function trend(string $ratioKey, Carbon $asOf, int $periods = 12): array
    {
        if (! isset(self::RATIOS[$ratioKey])) {
            return [];
        }
        $group = self::RATIOS[$ratioKey][1];
        // Walk back month by month within the AS-OF's fiscal year (fixed once), newest point capped
        // at the as-of date so a ratio never reaches before its books exist or past the stated date.
        $fyOpen = Voucher::fyOpenFor(Voucher::fyStartFor($asOf));
        $cursor = $asOf->copy()->endOfMonth();
        $points = [];
        for ($i = 0; $i < $periods; $i++) {
            $monthEnd = $cursor->copy();
            if ($monthEnd->gt($asOf)) {
                $monthEnd = $asOf->copy();
            }
            if ($monthEnd->lt($fyOpen)) {
                break;
            }
            $rows = match ($group) {
                'liquidity' => $this->section($this->keysOf('liquidity'), $fyOpen, $monthEnd, withPrior: false),
                'solvency' => $this->section($this->keysOf('solvency'), $fyOpen, $monthEnd, withPrior: false),
                'profitability' => $this->section($this->keysOf('profitability'), $fyOpen, $monthEnd, withPrior: false),
                'efficiency' => $this->section($this->keysOf('efficiency'), $fyOpen, $monthEnd, withPrior: false),
                default => [],
            };
            foreach ($rows as $r) {
                if ($r['key'] === $ratioKey) {
                    $points[] = ['label' => $monthEnd->format('M y'), 'value' => $r['value']];
                }
            }
            $cursor = $cursor->copy()->subMonthNoOverflow()->endOfMonth();
        }

        return array_reverse($points); // oldest → newest for the sparkline
    }

    /**
     * Sparkline data for EVERY (non-budget) ratio at once: computes figures once per month-end
     * (≤ $periods, never before the FY opens) and derives all ratios from each — so the whole
     * dashboard's sparklines cost ~12 balance-sheet computations, not 12 × 15.
     *
     * @return array<string, array<int,array{label:string,value:?float}>>  oldest → newest
     */
    public function trendGrid(Carbon $asOf, int $periods = 12): array
    {
        $fyOpen = Voucher::fyOpenFor(Voucher::fyStartFor($asOf)); // fixed to the as-of's FY, once
        $cursor = $asOf->copy()->endOfMonth();
        $months = [];
        for ($i = 0; $i < $periods; $i++) {
            $monthEnd = $cursor->copy();
            if ($monthEnd->gt($asOf)) {
                $monthEnd = $asOf->copy(); // the newest point honours the as-of cutoff, not month-end
            }
            if ($monthEnd->lt($fyOpen)) {
                break; // stay within the current fiscal year
            }
            array_unshift($months, ['label' => $monthEnd->format('M y'), 'f' => $this->figures($fyOpen, $monthEnd)]);
            $cursor = $cursor->copy()->subMonthNoOverflow()->endOfMonth();
        }

        $grid = [];
        foreach (self::RATIOS as $key => $meta) {
            if ($meta[1] === 'budget') {
                continue; // budget ratios are not trended here
            }
            $grid[$key] = array_map(fn ($m) => ['label' => $m['label'], 'value' => $this->compute($key, $m['f'])[0]], $months);
        }

        return $grid;
    }

    // ── computation ─────────────────────────────────────────────────────────────

    /** Build the requested ratio keys for [from,to] with a one-month-earlier prior for the delta. */
    private function section(array $keys, Carbon $from, Carbon $to, bool $withPrior = true): array
    {
        $cur = $this->figures($from, $to);
        $prior = null;
        if ($withPrior) {
            $priorTo = $to->copy()->subMonthNoOverflow()->endOfMonth();
            if ($priorTo->gte($from)) {
                $prior = $this->figures($from, $priorTo);
            }
        }

        $out = [];
        foreach ($keys as $key) {
            [$val, $reason, $inputs] = $this->compute($key, $cur);
            $priorVal = $prior ? $this->compute($key, $prior)[0] : null;
            $row = $this->ratioRow($key, $val, $reason, $inputs, $priorVal);

            // Negative-equity distress: a company with negative owners' funds produces a
            // sign-inverted Debt-to-Equity / ROE (two negatives cancel to a healthy-looking number).
            // The value still shows, but health is forced RED and the delta favourability suppressed
            // — deepening insolvency must never read as favourable.
            if (in_array($key, ['debt_to_equity', 'roe'], true) && $val !== null && $cur['equity'] < 0) {
                $row['health'] = 'red';
                $row['delta_favorable'] = null;
                $row['reason'] = 'Equity is negative — the company is in a deep-loss / insolvent position.';
            }

            $out[] = $row;
        }

        return $out;
    }

    /** Compose one ratio from a figures() bundle → [value|null, reason|null, inputs]. */
    private function compute(string $key, array $f): array
    {
        return match ($key) {
            'current_ratio' => $this->plain($f['cl'], $f['ca'], $f['cl'], 'Current Liabilities are zero — ratio not computable.',
                [$this->in('Current Assets', $f['ca'], 'group: Current Assets'), $this->in('Current Liabilities', $f['cl'], 'group: Current Liabilities')]),
            'quick_ratio' => $this->plain($f['cl'], $f['ca'] - $f['inv'], $f['cl'], 'Current Liabilities are zero — ratio not computable.',
                [$this->in('Current Assets − Inventory', $f['ca'] - $f['inv'], 'Current Assets less Stock-in-Hand'), $this->in('Current Liabilities', $f['cl'], 'group: Current Liabilities')]),
            'cash_ratio' => $this->plain($f['cl'], $f['cash'] + $f['bank'], $f['cl'], 'Current Liabilities are zero — ratio not computable.',
                [$this->in('Cash + Bank', $f['cash'] + $f['bank'], 'Cash-in-Hand + Bank Accounts'), $this->in('Current Liabilities', $f['cl'], 'group: Current Liabilities')]),
            'debt_to_equity' => $this->plain($f['equity'], $f['debt'], $f['equity'], 'Equity is zero — ratio not computable.',
                [$this->in('Total Debt', $f['debt'], 'group: Loans (Liability)'), $this->in('Equity', $f['equity'], 'Capital Account + retained earnings')]),
            'debt_to_assets' => $this->plain($f['totalAssets'], $f['debt'], $f['totalAssets'], 'Total Assets are zero — ratio not computable.',
                [$this->in('Total Debt', $f['debt'], 'group: Loans (Liability)'), $this->in('Total Assets', $f['totalAssets'], 'Balance Sheet total assets')]),
            'interest_coverage' => $this->plain($f['interest'], $f['ebit'], $f['interest'], 'No interest expense — coverage not applicable.',
                [$this->in('EBIT', $f['ebit'], 'Net profit + interest'), $this->in('Interest Expense', $f['interest'], 'ledgers named “interest”')]),
            'gross_profit_margin' => $this->pct($f['sales'], $f['grossProfit'], $f['sales'], 'No sales in the period — margin not computable.',
                [$this->in('Gross Profit', $f['grossProfit'], 'P&L gross profit'), $this->in('Sales', $f['sales'], 'group: Sales Accounts')]),
            'operating_profit_margin' => $this->pct($f['sales'], $f['ebit'], $f['sales'], 'No sales in the period — margin not computable.',
                [$this->in('Operating Profit (EBIT)', $f['ebit'], 'Net profit + interest'), $this->in('Sales', $f['sales'], 'group: Sales Accounts')]),
            'net_profit_margin' => $this->pct($f['sales'], $f['net'], $f['sales'], 'No sales in the period — margin not computable.',
                [$this->in('Net Profit', $f['net'], 'P&L net'), $this->in('Sales', $f['sales'], 'group: Sales Accounts')]),
            'roe' => $this->pct($f['equity'], $f['net'], $f['equity'], 'Equity is zero — ROE not computable.',
                [$this->in('Net Profit', $f['net'], 'P&L net'), $this->in('Equity', $f['equity'], 'Capital Account + retained earnings')]),
            'roa' => $this->pct($f['totalAssets'], $f['net'], $f['totalAssets'], 'Total Assets are zero — ROA not computable.',
                [$this->in('Net Profit', $f['net'], 'P&L net'), $this->in('Total Assets', $f['totalAssets'], 'Balance Sheet total assets')]),
            'inventory_turnover' => $this->plain($f['avgInventory'], $f['cogs'], $f['avgInventory'], 'Average inventory is zero — turnover not applicable.',
                [$this->in('COGS', $f['cogs'], 'Purchases + Direct Exp + Δ stock'), $this->in('Average Inventory', $f['avgInventory'], '(opening + closing Stock-in-Hand) / 2')]),
            'debtor_days' => $this->days($f['sales'], $f['debtors'], $f['sales'], 'No sales in the period — debtor days not computable.',
                [$this->in('Sundry Debtors', $f['debtors'], 'group: Sundry Debtors'), $this->in('Sales', $f['sales'], 'group: Sales Accounts')]),
            'creditor_days' => $this->days($f['purchases'], $f['creditors'], $f['purchases'], 'No purchases in the period — creditor days not computable.',
                [$this->in('Sundry Creditors', $f['creditors'], 'group: Sundry Creditors'), $this->in('Purchases', $f['purchases'], 'group: Purchase Accounts')]),
            default => [null, 'Unknown ratio.', []],
        };
    }

    /** value = numer / denom (rounded 2dp); null + reason when the denominator is zero. */
    private function plain(int $denom, int $numer, int $numShow, string $reason, array $inputs): array
    {
        return $denom === 0 ? [null, $reason, $inputs] : [round($numer / $denom, 2), null, $inputs];
    }

    /** value = numer / denom × 100. */
    private function pct(int $denom, int $numer, int $numShow, string $reason, array $inputs): array
    {
        return $denom === 0 ? [null, $reason, $inputs] : [round(($numer / $denom) * 100, 2), null, $inputs];
    }

    /** value = (numer / denom) × 365. */
    private function days(int $denom, int $numer, int $numShow, string $reason, array $inputs): array
    {
        return $denom === 0 ? [null, $reason, $inputs] : [round(($numer / $denom) * 365, 2), null, $inputs];
    }

    private function in(string $label, int $paise, string $source): array
    {
        return ['label' => $label, 'value_paise' => $paise, 'source' => $source];
    }

    /** Finalise a ratio row: health, prior, delta, delta favourability. */
    private function ratioRow(string $key, ?float $value, ?string $reason, array $inputs, ?float $priorValue): array
    {
        [$label, $group, $direction, $unit] = self::RATIOS[$key];
        $delta = ($value === null || $priorValue === null) ? null : round($value - $priorValue, 2);
        $deltaFav = $delta === null ? null : ($direction === 'higher_better' ? $delta >= 0 : $delta <= 0);

        return [
            'key' => $key,
            'label' => $label,
            'group' => $group,
            'direction' => $direction,
            'unit' => $unit,
            'value' => $value,
            'prior_value' => $priorValue,
            'delta' => $delta,
            'delta_favorable' => $deltaFav,
            'health' => $this->health($key, $value),
            'reason' => $reason,
            'inputs' => $inputs,
        ];
    }

    /** green / amber / red / na from the company thresholds + the ratio's direction. */
    public function health(string $key, ?float $value): string
    {
        if ($value === null) {
            return 'na';
        }
        $t = $this->thresholds()[$key] ?? null;
        if (! $t) {
            return 'na';
        }
        $g = $t->green_min;
        $a = $t->amber_min;
        if (self::RATIOS[$key][2] === 'higher_better') {
            if ($g !== null && $value >= $g) {
                return 'green';
            }
            if ($a !== null && $value >= $a) {
                return 'amber';
            }

            return 'red';
        }
        // lower_better: green_min / amber_min are ceilings.
        if ($g !== null && $value <= $g) {
            return 'green';
        }
        if ($a !== null && $value <= $a) {
            return 'amber';
        }

        return 'red';
    }

    /** @return array<string, RatioThreshold> */
    public function thresholds(): array
    {
        return $this->thresholdMemo ??= RatioThreshold::mapForCompany(self::DEFAULT_THRESHOLDS);
    }

    // ── figure extraction (the ONLY place BalanceService is read) ──────────────────

    /** All ratio inputs in paise (sign-normalised to positive magnitudes) for one [from,to] window. */
    private function figures(Carbon $from, Carbon $to): array
    {
        $bs = $this->balances->balanceSheet($from, $to);
        $pl = $this->balances->profitAndLoss($from, $to);

        // Balance-sheet named-group closings. Assets positive as-is; Liabilities/Capital negated.
        $ca = (int) ($this->node($bs['asset_roots'], 'Current Assets')['closing'] ?? 0);
        $cl = -(int) ($this->node($bs['liability_roots'], 'Current Liabilities')['closing'] ?? 0);
        $stockNode = $this->node($bs['asset_roots'], 'Stock-in-Hand');
        // Closing already includes the StockService item valuation (injected by balanceSheet); the
        // opening must include the StockService OPENING the same way, or average inventory averages a
        // stock-basis closing against a ledger-basis opening and inflates inventory turnover.
        $inv = (int) ($stockNode['closing'] ?? 0);
        $invOpen = (int) ($stockNode['opening'] ?? 0) + (int) $pl['opening_stock'];
        $cash = (int) ($this->node($bs['asset_roots'], 'Cash-in-Hand')['closing'] ?? 0);
        $bank = (int) ($this->node($bs['asset_roots'], 'Bank Accounts')['closing'] ?? 0);
        $debtors = (int) ($this->node($bs['asset_roots'], 'Sundry Debtors')['closing'] ?? 0);
        $creditors = -(int) ($this->node($bs['liability_roots'], 'Sundry Creditors')['closing'] ?? 0);
        $debt = -(int) ($this->node($bs['liability_roots'], 'Loans (Liability)')['closing'] ?? 0);
        $capital = -(int) ($this->node($bs['liability_roots'], 'Capital Account')['closing'] ?? 0);
        $totalAssets = (int) $bs['total_assets'];
        $net = (int) $bs['net'];
        $equity = $capital + $net; // owners' funds incl. current retained earnings

        // P&L figures.
        $sales = -(int) ($this->node($pl['income_roots'], 'Sales Accounts')['closing'] ?? 0);
        $purchases = (int) ($this->node($pl['expense_roots'], 'Purchase Accounts')['closing'] ?? 0);
        $cogs = (int) $pl['trading_expense'] + (int) $pl['opening_stock'] - (int) $pl['closing_stock'];
        $grossProfit = (int) $pl['gross_profit'];
        $interest = $this->interestExpense($from, $to);
        $ebit = $net + $interest;

        return [
            'ca' => $ca, 'cl' => $cl, 'inv' => $inv, 'cash' => $cash, 'bank' => $bank,
            'debtors' => $debtors, 'creditors' => $creditors, 'debt' => $debt, 'capital' => $capital,
            'totalAssets' => $totalAssets, 'net' => $net, 'equity' => $equity,
            'sales' => $sales, 'purchases' => $purchases, 'cogs' => $cogs, 'grossProfit' => $grossProfit,
            'interest' => $interest, 'ebit' => $ebit,
            'avgInventory' => intdiv($invOpen + $inv, 2),
        ];
    }

    /**
     * Interest expense: no group is reserved for it, so ratios read expense-nature ledgers whose
     * NAME contains "interest" (the SME convention — a business books interest to an "Interest …"
     * ledger). Traces to BalanceService via each ledger's closing. Interest INCOME (income nature)
     * is excluded, so it never contaminates the figure.
     */
    private function interestExpense(Carbon $from, Carbon $to): int
    {
        $natureByGroup = AccountGroup::pluck('nature', 'id')->all();
        $sum = 0;
        foreach ($this->balances->ledgerBalances($from, $to) as $l) {
            // Whole-word "interest" so "Disinterested"/"Uninterested" expense ledgers don't false-match.
            if (($natureByGroup[$l['group_id']] ?? null) === 'Expenses' && preg_match('/\binterest\b/i', (string) $l['name'])) {
                $sum += (int) $l['closing'];
            }
        }

        return $sum;
    }

    /** Find a named group node anywhere in a BalanceService roots forest (recurses children). */
    private function node(array $roots, string $name): ?array
    {
        foreach ($roots as $n) {
            if (($n['name'] ?? null) === $name) {
                return $n;
            }
            if (! empty($n['children'])) {
                $hit = $this->node($n['children'], $name);
                if ($hit !== null) {
                    return $hit;
                }
            }
        }

        return null;
    }

    private function keysOf(string $group): array
    {
        return array_keys(array_filter(self::RATIOS, fn ($r) => $r[1] === $group));
    }
}
