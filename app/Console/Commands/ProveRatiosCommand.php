<?php

namespace App\Console\Commands;

use App\Console\Concerns\ResolvesActiveCompany;
use App\Http\Controllers\ReportsController;
use App\Models\AccountGroup;
use App\Models\Budget;
use App\Models\CompanyFeature;
use App\Models\Ledger;
use App\Models\RatioThreshold;
use App\Models\Voucher;
use App\Models\VoucherEntry;
use App\Services\BudgetService;
use App\Services\CompanyProvisioner;
use App\Services\RatioService;
use App\Support\ActiveCompany;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Phase 15B — THE Ratio Analysis proof. Seeds a realistic small-business Balance Sheet + P&L and
 * asserts every ratio value, the divide-by-zero and negative-income handling, the period delta with
 * direction-aware favourability, health thresholds, the sparkline, the drill inputs, budget ratios,
 * the F11 gate, and per-company scoping.
 *
 * Run against a provisioned tenant DB, like the other single-company proofs:
 *   DB_DATABASE=tenant<slug> php artisan zerobook:prove-ratios
 */
class ProveRatiosCommand extends Command
{
    use ResolvesActiveCompany;

    protected $signature = 'zerobook:prove-ratios {--keep} {--company=}';

    protected $description = 'Prove Phase 15B: the 15 financial ratios computed from BalanceService, divide-by-zero + negative handling, thresholds, sparkline, drill, budget ratios, F11 gate, company scoping';

    private bool $ok = true;

    public function handle(RatioService $svc): int
    {
        DB::beginTransaction();

        if (! $this->resolveActiveCompany(fresh: empty(trim((string) $this->option('company'))))) {
            DB::rollBack();

            return self::FAILURE;
        }

        $ok = true;
        $expect = function (string $label, $actual, $expected) use (&$ok) {
            $pass = $actual === $expected;
            $this->line(sprintf('   [%s] %s = %s%s', $pass ? 'PASS' : 'FAIL', $label, json_encode($actual), $pass ? '' : ' (expected '.json_encode($expected).')'));
            $ok = $ok && $pass;
        };
        $section = fn (string $t) => $this->line("\n── {$t} ".str_repeat('─', max(1, 64 - mb_strlen($t))));
        $val = fn (array $rows, string $key) => collect($rows)->firstWhere('key', $key);

        try {
            $companyA = ActiveCompany::id();
            CompanyFeature::current()->update(['gst' => false, 'vat' => false, 'ratio_analysis' => true]);
            $this->seedWorked();

            $from = Carbon::parse('2026-04-01');
            $asOf = Carbon::parse('2026-06-30');
            $liq = $svc->liquidity($asOf);
            $sol = $svc->solvency($asOf);
            $pro = $svc->profitability($from, $asOf);
            $eff = $svc->efficiency($from, $asOf);

            // ── 1. Worked-scenario values ─────────────────────────────────────────
            $section('1 · Worked scenario — every ratio value (rounded 2dp)');
            $expect('Current Ratio = 2.33', $val($liq, 'current_ratio')['value'], 2.33);
            $expect('Quick Ratio = 1.5', $val($liq, 'quick_ratio')['value'], 1.5);
            $expect('Cash Ratio = 1.0', $val($liq, 'cash_ratio')['value'], 1.0);
            $expect('Debt-to-Equity = 0.8', $val($sol, 'debt_to_equity')['value'], 0.8);
            $expect('Debt-to-Assets = 0.33', $val($sol, 'debt_to_assets')['value'], 0.33);
            $expect('Interest Coverage = 3.5', $val($sol, 'interest_coverage')['value'], 3.5);
            $expect('Gross Profit Margin = 40%', $val($pro, 'gross_profit_margin')['value'], 40.0);
            $expect('Net Profit Margin = 10%', $val($pro, 'net_profit_margin')['value'], 10.0);
            $expect('ROE = 20%', $val($pro, 'roe')['value'], 20.0);
            $expect('ROA = 8.33%', $val($pro, 'roa')['value'], 8.33);
            $expect('Debtor Days = 54.75', $val($eff, 'debtor_days')['value'], 54.75);
            $expect('Operating Profit Margin = 14% (EBIT 140k / sales 1M)', $val($pro, 'operating_profit_margin')['value'], 14.0);
            $expect('Inventory Turnover = 2.4 (COGS 600k / avg stock 250k)', $val($eff, 'inventory_turnover')['value'], 2.4);
            $expect('Creditor Days = 121.67 (creditors 200k / purchases 600k × 365)', $val($eff, 'creditor_days')['value'], 121.67);

            // ── 2. Health colours from thresholds ─────────────────────────────────
            $section('2 · Default health thresholds colour each ratio');
            $expect('Current Ratio 2.33 → green (≥1.5)', $val($liq, 'current_ratio')['health'], 'green');
            $expect('Debt-to-Equity 0.8 → green (≤1.0)', $val($sol, 'debt_to_equity')['health'], 'green');

            // ── 3. Divide-by-zero (zero equity) ───────────────────────────────────
            $section('3 · Divide-by-zero — zero equity → N/A with a reason (no inf, no crash)');
            $companyB = app(CompanyProvisioner::class)->create('Zero Equity Co')->id;
            ActiveCompany::runAs($companyB, function () use ($svc, $expect, $val, $asOf) {
                CompanyFeature::current()->update(['ratio_analysis' => true]);
                $gid = fn ($n) => AccountGroup::where('name', $n)->value('id');
                Ledger::create(['name' => 'Bank Loan', 'group_id' => $gid('Secured Loans'), 'opening_balance' => 100000, 'opening_balance_type' => 'Cr', 'country' => 'India']);
                // No capital, no profit → equity = 0.
                $sol = $svc->solvency($asOf);
                $de = $val($sol, 'debt_to_equity');
                $expect('Debt-to-Equity value is NULL (N/A)', $de['value'], null);
                $expect('… with a reason string', is_string($de['reason']) && str_contains($de['reason'], 'Equity'), true);
                $expect('… health rendered N/A', $de['health'], 'na');
                $expect('Interest Coverage (no interest) is N/A', $val($sol, 'interest_coverage')['value'], null);
            });

            // Negative equity (deep loss) — the ratio COMPUTES but flags red, not a misleading green.
            $companyBn = app(CompanyProvisioner::class)->create('Negative Equity Co')->id;
            ActiveCompany::runAs($companyBn, function () use ($svc, $expect, $val, $asOf) {
                CompanyFeature::current()->update(['gst' => false, 'ratio_analysis' => true]);
                $gid = fn ($n) => AccountGroup::where('name', $n)->value('id');
                Ledger::create(['name' => 'Bank Loan', 'group_id' => $gid('Secured Loans'), 'opening_balance' => 400000, 'opening_balance_type' => 'Cr', 'country' => 'India']);
                Ledger::create(['name' => 'Capital A/c', 'group_id' => $gid('Capital Account'), 'opening_balance' => 100000, 'opening_balance_type' => 'Cr', 'country' => 'India']);
                $exp = Ledger::create(['name' => 'Loss', 'group_id' => $gid('Indirect Expenses'), 'country' => 'India']);
                $cash = Ledger::where('name', 'Cash')->value('id');
                $this->mk('payment', '2026-06-10', [[$exp->id, 'Dr', 300000], [$cash, 'Cr', 300000]]); // ₹300k loss → equity −200k
                $sol = $svc->solvency($asOf);
                $de = $val($sol, 'debt_to_equity');
                $expect('Negative equity → Debt-to-Equity COMPUTES (−2.0), not N/A', $de['value'], -2.0);
                $expect('… flagged RED, not a misleading green', $de['health'], 'red');
                $expect('… delta favourability suppressed', $de['delta_favorable'], null);
            });

            // ── 4. Negative income (not masked) ───────────────────────────────────
            $section('4 · Negative net income → valid negative margin, red health (not masked)');
            $companyC = app(CompanyProvisioner::class)->create('Loss Making Co')->id;
            ActiveCompany::runAs($companyC, function () use ($svc, $expect, $val, $from, $asOf) {
                CompanyFeature::current()->update(['gst' => false, 'ratio_analysis' => true]);
                $gid = fn ($n) => AccountGroup::where('name', $n)->value('id');
                $sales = Ledger::create(['name' => 'Sales', 'group_id' => $gid('Sales Accounts'), 'country' => 'India']);
                $exp = Ledger::create(['name' => 'Expenses', 'group_id' => $gid('Indirect Expenses'), 'country' => 'India']);
                $cust = Ledger::create(['name' => 'Cust', 'group_id' => $gid('Sundry Debtors'), 'country' => 'India']);
                $cash = Ledger::where('name', 'Cash')->value('id');
                $this->mk('sales', '2026-06-10', [[$cust->id, 'Dr', 1000000], [$sales->id, 'Cr', 1000000]]);
                $this->mk('payment', '2026-06-11', [[$exp->id, 'Dr', 1050000], [$cash, 'Cr', 1050000]]);
                $pro = $svc->profitability($from, $asOf);
                $nm = $val($pro, 'net_profit_margin');
                $expect('Net loss ₹50,000 → Net Margin = −5%', $nm['value'], -5.0);
                $expect('… flagged red (below amber)', $nm['health'], 'red');
            });

            // ── 5. Period comparison — direction-aware favourability ──────────────
            $section('5 · Period delta respects each ratio\'s direction');
            $cr = $val($liq, 'current_ratio');
            $de = $val($sol, 'debt_to_equity');
            $expect('Current Ratio has a prior value (May-end 4.0)', $cr['prior_value'], 4.0);
            $expect('Current Ratio fell 4.0→2.33 → delta −1.67', $cr['delta'], -1.67);
            $expect('… a DROP in a higher-is-better ratio is UNfavourable', $cr['delta_favorable'], false);
            $expect('Debt-to-Equity fell 1.0→0.8 → delta −0.2', $de['delta'], -0.2);
            $expect('… a DROP in a lower-is-better ratio is FAVOURABLE', $de['delta_favorable'], true);

            // ── 6. Editable thresholds re-colour ──────────────────────────────────
            $section('6 · Editing a threshold re-colours the ratio');
            ActiveCompany::set($companyA);
            RatioThreshold::updateOrCreate(['ratio_key' => 'current_ratio'], ['green_min' => 3.0, 'amber_min' => 1.0]);
            $liq2 = app(RatioService::class)->liquidity($asOf); // fresh instance → fresh threshold memo
            $expect('Current Ratio 2.33 now AMBER (green raised to 3.0)', $val($liq2, 'current_ratio')['health'], 'amber');
            RatioThreshold::where('ratio_key', 'current_ratio')->update(['green_min' => 1.5]); // restore

            // ── 7. Sparkline trend data ───────────────────────────────────────────
            $section('7 · Sparkline — up to 12 month-ends of trend data');
            $trend = app(RatioService::class)->trend('current_ratio', $asOf, 12);
            $expect('trend stays within the FY (Apr, May, Jun = 3 points)', count($trend), 3);
            $expect('newest trend point equals the headline value (2.33, capped at as-of)', end($trend)['value'], 2.33);
            $grid = app(RatioService::class)->trendGrid($asOf);
            $expect('trendGrid covers every non-budget ratio', count($grid), 14);

            // ── 8. Drill to inputs + contributing ledgers ─────────────────────────
            $section('8 · Drill — a ratio exposes its inputs + contributing ledgers');
            $detail = app(RatioService::class)->detail('current_ratio', $asOf);
            $expect('detail returns the ratio inputs', count($detail['inputs']), 2);
            $contrib = app(RatioService::class)->contributingLedgers('current_ratio', $asOf);
            $ledgerNames = collect($contrib)->flatMap(fn ($g) => collect($g['ledgers'])->pluck('name'))->all();
            $expect('contributing ledgers include the Bank ledger (drillable)', in_array('HDFC Bank', $ledgerNames, true), true);
            $expect('each contributing ledger carries a drill id', collect($contrib)->flatMap(fn ($g) => $g['ledgers'])->every(fn ($l) => $l['ledger_id'] > 0), true);

            // ── 9. Budget ratios (gated on 15A) ───────────────────────────────────
            $section('9 · Budget ratios appear only when Budgets (15A) is on');
            CompanyFeature::current()->update(['budgets' => false]);
            $expect('Budgets OFF → no budget ratios', count(app(RatioService::class)->budgetRatios($from, $asOf)), 0);
            CompanyFeature::current()->update(['budgets' => true]);
            $salesId = Ledger::where('name', 'Sales')->value('id');
            $rentId = Ledger::where('name', 'Office Rent')->value('id');
            app(BudgetService::class)->createBudget('FY Budget', 2026, [
                ['ledger_id' => $salesId, 'annual_target' => 1200000, 'allocation_method' => 'even'],
                ['ledger_id' => $rentId, 'annual_target' => 240000, 'allocation_method' => 'even'], // expense line → burn rate meaningful
            ], null, true);
            $bud = app(RatioService::class)->budgetRatios($from, $asOf);
            $expect('Budgets ON + primary budget → budget ratios present', count($bud), 2);
            $expect('budget_variance_pct is computed', $val($bud, 'budget_variance_pct')['value'] !== null, true);
            $expect('burn_rate is computed', $val($bud, 'burn_rate')['value'] !== null, true);

            // ── 10. F11 gate ──────────────────────────────────────────────────────
            $section('10 · F11 gate — menu + screens hidden when off, shown when on');
            CompanyFeature::current()->update(['ratio_analysis' => false]);
            $navOff = collect(\App\Support\Shell::nav())->where('sub', 'Ratios')->count();
            $ctrl = new ReportsController();
            $expect('flag OFF → no Ratio menu items', $navOff, 0);
            $expect('flag OFF → ratio screen redirects (inaccessible)', $ctrl->ratioDashboard() instanceof RedirectResponse, true);
            CompanyFeature::current()->update(['ratio_analysis' => true]);
            $expect('flag ON → 2 Ratio menu items', collect(\App\Support\Shell::nav())->where('sub', 'Ratios')->count(), 2);
            $expect('flag ON → ratio screen renders (a View)', $ctrl->ratioDashboard() instanceof \Illuminate\View\View, true);

            // ── 11. Company scoping ───────────────────────────────────────────────
            $section('11 · Per-company scoping — Company A ≠ Company B');
            $aCr = $val(app(RatioService::class)->liquidity($asOf), 'current_ratio')['value'];
            $bCr = ActiveCompany::runAs($companyB, fn () => $val(app(RatioService::class)->liquidity($asOf), 'current_ratio')['value']);
            $expect("Company A current ratio (2.33) ≠ Company B's", $aCr !== $bCr, true);
            $expect('Company A current ratio is its own (2.33)', $aCr, 2.33);
            // Company A wrote a custom current_ratio threshold earlier (§6); B must NOT see it.
            RatioThreshold::updateOrCreate(['ratio_key' => 'debt_to_equity'], ['green_min' => 0.42]);
            $bSeesAsThreshold = ActiveCompany::runAs($companyB, fn () => RatioThreshold::where('ratio_key', 'debt_to_equity')->where('green_min', 0.42)->exists());
            $expect("Company B does NOT see Company A's custom threshold row", $bSeesAsThreshold, false);

            $this->ok = $ok;
        } catch (Throwable $e) {
            $this->ok = false;
            $this->error('Fatal: '.$e->getMessage());
            $this->line($e->getFile().':'.$e->getLine());
        } finally {
            if ($this->option('keep')) {
                DB::commit();
            } else {
                DB::rollBack();
            }
        }

        $this->line('');
        if ($this->ok) {
            $this->info('ALL ASSERTIONS PASSED — Phase 15B ratio analysis verified.');

            return self::SUCCESS;
        }
        $this->error('SOME ASSERTIONS FAILED.');

        return self::FAILURE;
    }

    /** The worked small-business Balance Sheet + P&L (opening balances + P&L vouchers, GST off). */
    private function seedWorked(): void
    {
        VoucherEntry::query()->delete();
        Voucher::query()->delete();
        Ledger::where('is_reserved', false)->delete();
        $gid = fn ($n) => AccountGroup::where('name', $n)->value('id');

        $cash = Ledger::where('name', 'Cash')->first();
        $cash->update(['opening_balance' => 0, 'opening_balance_type' => 'Dr']);
        $l = fn ($name, $grp, $ob = 0, $obt = null) => Ledger::create(['name' => $name, 'group_id' => $gid($grp), 'opening_balance' => $ob, 'opening_balance_type' => $obt, 'country' => 'India']);

        $bank = $l('HDFC Bank', 'Bank Accounts', 150000, 'Dr');
        $l('Closing Stock', 'Stock-in-Hand', 250000, 'Dr');
        $l('Furniture', 'Fixed Assets', 500000, 'Dr');
        $l('Outstanding Expenses', 'Provisions', 100000, 'Cr');
        $l('Term Loan', 'Secured Loans', 400000, 'Cr');
        $l('Capital A/c', 'Capital Account', 400000, 'Cr');
        $cust = $l('A Customer', 'Sundry Debtors');
        $supp = $l('A Supplier', 'Sundry Creditors');
        $sales = $l('Sales', 'Sales Accounts');
        $purch = $l('Purchases', 'Purchase Accounts');
        $rent = $l('Office Rent', 'Indirect Expenses');
        $intr = $l('Interest Expense', 'Indirect Expenses');
        $sal = $l('Salaries', 'Indirect Expenses');
        $cashId = $cash->id;

        // Sales 1,000,000; COGS 600,000; OpEx 200,000 + Interest 40,000 + Salaries 60,000 (net 100,000).
        // Balance-sheet closings land at CA 700k / CL 300k / Debt 400k / Equity 500k / Assets 1,200k.
        $this->mk('sales', '2026-06-05', [[$cust->id, 'Dr', 1000000], [$sales->id, 'Cr', 1000000]]);
        $this->mk('purchase', '2026-06-06', [[$purch->id, 'Dr', 600000], [$supp->id, 'Cr', 600000]]);
        $this->mk('receipt', '2026-06-20', [[$cashId, 'Dr', 800000], [$bank->id, 'Dr', 50000], [$cust->id, 'Cr', 850000]]);
        $this->mk('payment', '2026-06-21', [[$supp->id, 'Dr', 400000], [$cashId, 'Cr', 400000]]);
        $this->mk('payment', '2026-06-22', [[$rent->id, 'Dr', 200000], [$cashId, 'Cr', 200000]]);
        $this->mk('payment', '2026-06-23', [[$intr->id, 'Dr', 40000], [$cashId, 'Cr', 40000]]);
        $this->mk('payment', '2026-06-24', [[$sal->id, 'Dr', 60000], [$cashId, 'Cr', 60000]]);
    }

    private function mk(string $type, string $date, array $lines): void
    {
        $fy = Voucher::fyStartFor(Carbon::parse($date));
        $v = Voucher::create(['type' => $type, 'number' => Voucher::nextNumber($type, $fy), 'fy_start' => $fy, 'date' => $date, 'narration' => 'ratio proof']);
        foreach ($lines as $i => [$lid, $side, $amt]) {
            VoucherEntry::create(['voucher_id' => $v->id, 'ledger_id' => $lid, 'dr_cr' => $side, 'amount' => $amt, 'line_no' => $i + 1]);
        }
    }
}
