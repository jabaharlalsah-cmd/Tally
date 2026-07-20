<?php

namespace App\Console\Commands;

use App\Console\Concerns\ResolvesActiveCompany;
use App\Http\Controllers\ReportsController;
use App\Livewire\VoucherScreen;
use App\Models\AccountGroup;
use App\Models\Budget;
use App\Models\BudgetLinePeriod;
use App\Models\CompanyFeature;
use App\Models\Ledger;
use App\Models\Voucher;
use App\Models\VoucherEntry;
use App\Services\BalanceService;
use App\Services\BudgetService;
use App\Services\CompanyProvisioner;
use App\Support\ActiveCompany;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Phase 15A — THE Budgets proof. Seeds a small deterministic dataset and walks every rule:
 * allocation methods, actual-vs-target variance (actuals from BalanceService), the
 * revision-doesn't-change-history invariant, group aggregation, "No target" vs zero, primary
 * exclusivity, per-company isolation, the drill target, and the F11 gate.
 *
 * Run against a provisioned tenant DB, like the other single-company proofs:
 *   DB_DATABASE=tenant<slug> php artisan zerobook:prove-budgets
 */
class ProveBudgetsCommand extends Command
{
    use ResolvesActiveCompany;

    protected $signature = 'zerobook:prove-budgets {--keep : keep the seeded scenario in the DB} {--company= : run in this company (slug or id) instead of a fresh throwaway one}';

    protected $description = 'Prove Phase 15A: budget allocation, actual-vs-budget variance, non-retroactive revision, group aggregation, primary exclusivity, company isolation, and the F11 gate';

    private bool $ok = true;

    public function handle(BudgetService $svc, BalanceService $balances): int
    {
        DB::beginTransaction();

        if (! $this->resolveActiveCompany(fresh: empty(trim((string) $this->option('company'))))) {
            DB::rollBack();

            return self::FAILURE;
        }

        try {
            // Clean slate + GST off (deterministic amounts, no tax legs).
            VoucherEntry::query()->delete();
            Voucher::query()->delete();
            Budget::query()->delete();
            Ledger::where('is_reserved', false)->delete();
            CompanyFeature::current()->update(['gst' => false, 'vat' => false, 'budgets' => true]);

            $gid = fn (string $n) => AccountGroup::where('name', $n)->value('id');
            $cash = Ledger::where('name', 'Cash')->value('id');

            $sales = Ledger::create(['name' => 'Sales', 'group_id' => $gid('Sales Accounts'), 'opening_balance' => 0, 'country' => 'India']);
            $salesExport = Ledger::create(['name' => 'Sales Export', 'group_id' => $gid('Sales Accounts'), 'opening_balance' => 0, 'country' => 'India']);
            $mkt = Ledger::create(['name' => 'Marketing Expense', 'group_id' => $gid('Indirect Expenses'), 'opening_balance' => 0, 'country' => 'India']);
            $rent = Ledger::create(['name' => 'Rent', 'group_id' => $gid('Direct Expenses'), 'opening_balance' => 0, 'country' => 'India']);
            $power = Ledger::create(['name' => 'Electricity', 'group_id' => $gid('Direct Expenses'), 'opening_balance' => 0, 'country' => 'India']);
            $cust = Ledger::create(['name' => 'A Customer', 'group_id' => $gid('Sundry Debtors'), 'opening_balance' => 0, 'country' => 'India']);

            $screen = new VoucherScreen();
            $sale = fn ($amt, $date, $ref, $led) => $screen->post(['type' => 'sales', 'date' => $date, 'party_ledger_id' => $cust->id, 'reference_no' => $ref, 'lines' => [
                ['ledger_id' => $cust->id, 'dr_cr' => 'Dr', 'amount' => $amt],
                ['ledger_id' => $led->id, 'dr_cr' => 'Cr', 'amount' => $amt],
            ]]);
            $pay = fn ($amt, $date, $led) => $screen->post(['type' => 'payment', 'date' => $date, 'lines' => [
                ['ledger_id' => $led->id, 'dr_cr' => 'Dr', 'amount' => $amt],
                ['ledger_id' => $cash, 'dr_cr' => 'Cr', 'amount' => $amt],
            ]]);

            // Actuals: Sales 250k (150k Apr + 100k May); Marketing 40k Apr + 60k May;
            // Rent 120k Apr + Electricity 30k Apr (Direct Expenses group); Sales Export 30k (untracked).
            $sale(150000, '2026-04-15', 'INV-1', $sales);
            $sale(100000, '2026-05-10', 'INV-2', $sales);
            $sale(30000, '2026-04-18', 'EXP-1', $salesExport);
            $pay(40000, '2026-04-20', $mkt);
            $pay(60000, '2026-05-20', $mkt);
            $pay(120000, '2026-04-05', $rent);
            $pay(30000, '2026-04-06', $power);

            $ok = true;
            $expect = function (string $label, $actual, $expected) use (&$ok) {
                $pass = $actual === $expected;
                $this->line(sprintf('   [%s] %s = %s%s', $pass ? 'PASS' : 'FAIL', $label, json_encode($actual), $pass ? '' : ' (expected '.json_encode($expected).')'));
                $ok = $ok && $pass;
            };
            $section = fn (string $t) => $this->line("\n── {$t} ".str_repeat('─', max(1, 66 - mb_strlen($t))));

            // ── 1. Create a budget with even + custom allocation ─────────────────
            $section('1 · Create budget — Sales even (100k/mo), Marketing custom (50k Apr-Sep)');
            $budget = $svc->createBudget('FY 2026-27 Budget', 2026, [
                ['ledger_id' => $sales->id, 'annual_target' => 1200000, 'allocation_method' => 'even'],
                ['ledger_id' => $mkt->id, 'annual_target' => 300000, 'allocation_method' => 'custom',
                    'months' => [50000, 50000, 50000, 50000, 50000, 50000, 0, 0, 0, 0, 0, 0]],
                ['account_group_id' => $gid('Direct Expenses'), 'annual_target' => 500000, 'allocation_method' => 'even'],
            ], null, true);

            $salesLine = $budget->lines()->where('ledger_id', $sales->id)->first();
            $mktLine = $budget->lines()->where('ledger_id', $mkt->id)->first();
            $grpLine = $budget->lines()->where('account_group_id', $gid('Direct Expenses'))->first();

            $expect('budget persisted with 3 lines', $budget->lines()->count(), 3);
            $expect('even Sales → 12 period rows of 100,000', BudgetLinePeriod::where('budget_line_id', $salesLine->id)->where('target_amount', 100000)->count(), 12);
            $expect('custom Marketing → April target 50,000', (float) BudgetLinePeriod::where('budget_line_id', $mktLine->id)->where('month', 1)->value('target_amount'), 50000.0);
            $expect('custom Marketing → October target 0', (float) BudgetLinePeriod::where('budget_line_id', $mktLine->id)->where('month', 7)->value('target_amount'), 0.0);
            $expect('custom Marketing → annual = 300,000', (float) $mktLine->fresh()->annual_target, 300000.0);

            // ── 2. Variance April–May ─────────────────────────────────────────────
            $section('2 · Variance April–May — Sales +50k (+25%, favorable), Marketing 0');
            $apr = Carbon::parse('2026-04-01');
            $may = Carbon::parse('2026-05-31');
            $v = $svc->variance($budget, $apr, $may, true);
            $rows = collect($v['lines'])->keyBy('name');

            $expect('Sales target = 200,000 paise-lakh', $rows['Sales']['target'], 20000000);
            $expect('Sales actual = 250,000', $rows['Sales']['actual'], 25000000);
            $expect('Sales variance = +50,000', $rows['Sales']['variance'], 5000000);
            $expect('Sales variance% = +25', $rows['Sales']['variance_pct'], 25.0);
            $expect('Sales favorable (income up)', $rows['Sales']['is_favorable'], true);
            $expect('Marketing target = 100,000', $rows['Marketing Expense']['target'], 10000000);
            $expect('Marketing actual = 100,000', $rows['Marketing Expense']['actual'], 10000000);
            $expect('Marketing variance = 0', $rows['Marketing Expense']['variance'], 0);
            $expect('Marketing favorable (0 ≤ 0)', $rows['Marketing Expense']['is_favorable'], true);

            // ── 3. Group aggregation ──────────────────────────────────────────────
            $section('3 · Group budget — Direct Expenses actual aggregates Rent + Electricity');
            $rentNet = $balances->ledgerBalances($apr, $may)[$rent->id]['net'];
            $powerNet = $balances->ledgerBalances($apr, $may)[$power->id]['net'];
            $expect('Direct Expenses group actual = Rent + Electricity (150,000)', $rows['Direct Expenses']['actual'], (int) ($rentNet + $powerNet));
            $expect('… equals 150,000 paise-lakh', $rows['Direct Expenses']['actual'], 15000000);
            $expect('group line is kind=group', $rows['Direct Expenses']['kind'], 'group');

            // ── 4. No-target ledger ───────────────────────────────────────────────
            $section('4 · Untracked ledger shows "No target" (null), not 0');
            $expect('Sales Export appears', $rows->has('Sales Export'), true);
            $expect('Sales Export target IS NULL (No target)', $rows['Sales Export']['target'], null);
            $expect('Sales Export flagged no_target', $rows['Sales Export']['no_target'], true);
            $expect('Sales Export actual = 30,000 (still shows the actual)', $rows['Sales Export']['actual'], 3000000);

            // ── 5. Drill target ───────────────────────────────────────────────────
            $section('5 · Drill from a variance figure to the underlying vouchers');
            $expect('Sales row carries a drill ledger id', $rows['Sales']['drill_ledger_id'], $sales->id);
            $drill = $balances->ledgerVouchers($sales->id, $apr, $may);
            $expect('ledger drill returns the 2 sales vouchers', count($drill['rows']), 2);

            // ── 6. Revision does NOT change history ───────────────────────────────
            $section('6 · Revise Marketing to 40k/mo from June — history stays locked');
            $svc->reviseBudget($budget, [
                $mktLine->id => ['allocation_method' => 'even', 'annual_target' => 480000],
            ], Carbon::parse('2026-06-01'), null, 'Trim marketing');
            $pay(45000, '2026-06-15', $mkt);

            $expect('a revision row was logged', $budget->revisions()->count(), 1);

            $vJun = $svc->variance($budget->fresh(), Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'));
            $mJun = collect($vJun['lines'])->firstWhere('name', 'Marketing Expense');
            $expect('June Marketing target = 40,000 (revised)', $mJun['target'], 4000000);
            $expect('June Marketing original = 50,000 (pre-revision)', $mJun['original_target'], 5000000);
            $expect('June Marketing actual = 45,000', $mJun['actual'], 4500000);
            $expect('June Marketing variance = +5,000 (unfavorable, expense up)', $mJun['variance'], 500000);
            $expect('June Marketing UNFAVORABLE', $mJun['is_favorable'], false);
            $expect('June Marketing marked revised', $mJun['revised'], true);

            $vAM2 = $svc->variance($budget->fresh(), $apr, $may);
            $mAM2 = collect($vAM2['lines'])->firstWhere('name', 'Marketing Expense');
            $expect('April–May Marketing target STILL 100,000 (NOT retroactively changed)', $mAM2['target'], 10000000);
            $expect('April–May Marketing original STILL 100,000', $mAM2['original_target'], 10000000);

            // ── 7. Seasonal allocation (non-stub) ─────────────────────────────────
            $section('7 · Seasonal allocation sums exactly to the annual target');
            $seasonal = $svc->createBudget('Seasonal Test', 2026, [
                ['ledger_id' => $sales->id, 'annual_target' => 1000000, 'allocation_method' => 'seasonal',
                    'months' => BudgetService::SEASONAL_TEMPLATE],
            ], null, false);
            $seasLine = $seasonal->lines()->first();
            $sum = (int) round((float) BudgetLinePeriod::where('budget_line_id', $seasLine->id)->sum('target_amount') * 100);
            $expect('seasonal 12 months sum EXACTLY to 1,000,000', $sum, 100000000);
            $expect('seasonal is not flat (peak month > trough month)',
                (float) BudgetLinePeriod::where('budget_line_id', $seasLine->id)->max('target_amount')
                > (float) BudgetLinePeriod::where('budget_line_id', $seasLine->id)->min('target_amount'), true);

            // ── 8. Primary flag exclusivity ───────────────────────────────────────
            $section('8 · Primary flag exclusivity — a new primary clears the old');
            $expect('before: FY budget is primary', (bool) $budget->fresh()->is_primary, true);
            $second = $svc->createBudget('Revised Plan', 2026, [
                ['ledger_id' => $sales->id, 'annual_target' => 900000, 'allocation_method' => 'even'],
            ], null, true); // primary = true
            $expect('after: new budget is primary', (bool) $second->fresh()->is_primary, true);
            $expect('after: old budget NO LONGER primary', (bool) $budget->fresh()->is_primary, false);
            $expect('exactly one primary in the company', Budget::where('is_primary', true)->count(), 1);

            // ── 9. Duplicate line rejected ────────────────────────────────────────
            $section('9 · Duplicate ledger line in one budget is rejected');
            $threw = false;
            try {
                $svc->createBudget('Dup', 2026, [
                    ['ledger_id' => $sales->id, 'annual_target' => 100, 'allocation_method' => 'even'],
                    ['ledger_id' => $sales->id, 'annual_target' => 200, 'allocation_method' => 'even'],
                ]);
            } catch (Throwable) {
                $threw = true;
            }
            $expect('duplicate ledger line throws', $threw, true);

            // ── 10. Company isolation ─────────────────────────────────────────────
            $section('10 · Per-company isolation — Company A budgets invisible in Company B');
            $companyA = ActiveCompany::id();
            $companyB = app(CompanyProvisioner::class)->create('Budget Isolation B')->id;
            $countA = Budget::count();
            $bBudgetId = ActiveCompany::runAs($companyB, function () use ($svc) {
                CompanyFeature::current()->update(['gst' => false, 'vat' => false, 'budgets' => true]);
                $bSales = Ledger::create(['name' => 'B Sales', 'group_id' => AccountGroup::where('name', 'Sales Accounts')->value('id'), 'opening_balance' => 0, 'country' => 'India']);

                return $svc->createBudget('B Budget', 2026, [
                    ['ledger_id' => $bSales->id, 'annual_target' => 600000, 'allocation_method' => 'even'],
                ])->id;
            });
            $expect('Company A budget count unchanged by B', Budget::count(), $countA);
            $expect('Company B sees only its own 1 budget', ActiveCompany::runAs($companyB, fn () => Budget::count()), 1);
            $expect("A cannot load B's budget (scoped out)", Budget::query()->whereKey($bBudgetId)->exists(), false);
            $expect('row-level: B budget stamped company B', (int) DB::table('budgets')->where('id', $bBudgetId)->value('company_id'), $companyB);

            // ── 11. F11 gate ──────────────────────────────────────────────────────
            $section('11 · F11 gate — menu + screens hidden when off, shown when on');
            ActiveCompany::set($companyA);
            CompanyFeature::current()->update(['budgets' => false]);
            $navOff = collect(\App\Support\Shell::nav())->where('sub', 'Budgets')->count();
            $ctrl = new ReportsController();
            $offResp = $ctrl->budgetList();
            $expect('flag OFF → no Budgets menu items', $navOff, 0);
            $expect('flag OFF → budget screen redirects (inaccessible)', $offResp instanceof RedirectResponse, true);

            CompanyFeature::current()->update(['budgets' => true]);
            $navOn = collect(\App\Support\Shell::nav())->where('sub', 'Budgets')->count();
            $onResp = $ctrl->budgetList();
            $expect('flag ON → 4 Budgets menu items', $navOn, 4);
            $expect('flag ON → budget screen renders (a View)', $onResp instanceof \Illuminate\View\View, true);

            // ── 12. Summary roll-up ───────────────────────────────────────────────
            $section('12 · Summary — revenue/expense budget-vs-actual + net profit');
            $sumB = $svc->createBudget('Summary Test', 2026, [
                ['ledger_id' => $sales->id, 'annual_target' => 1200000, 'allocation_method' => 'even'],
            ], null, false);
            $sum = $svc->summary($sumB, Carbon::parse('2026-05-31'));
            $expect('revenue budget Apr-May = 200,000', $sum['revenue']['budget'], 20000000);
            $expect('revenue actual = 250,000', $sum['revenue']['actual'], 25000000);
            $expect('revenue favorable (income up)', $sum['revenue']['is_favorable'], true);
            $expect('no expense lines → expense budget 0', $sum['expense']['budget'], 0);
            $expect('net profit budget = 200,000', $sum['net_profit']['budget'], 20000000);
            $expect('net profit actual = 250,000', $sum['net_profit']['actual'], 25000000);
            $expect('net profit variance = +50,000', $sum['net_profit']['variance'], 5000000);
            $expect('net profit favorable', $sum['net_profit']['is_favorable'], true);
            $clamped = $svc->summary($sumB, Carbon::parse('2025-01-01')); // before FY open
            $expect('as-of before FY open clamps to FY open', $clamped['as_of'], '2026-04-01');

            // ── 13. Even allocation with a non-divisible annual sums exactly ───────
            $section('13 · Even allocation remainder — 12 months sum exactly to the annual');
            $rem = $svc->createBudget('Remainder Test', 2026, [
                ['ledger_id' => $sales->id, 'annual_target' => 100000, 'allocation_method' => 'even'],
            ], null, false);
            $remLine = $rem->lines()->first();
            $remSum = (int) round((float) BudgetLinePeriod::where('budget_line_id', $remLine->id)->sum('target_amount') * 100);
            $expect('100,000 / 12 spread sums EXACTLY to 100,000', $remSum, 10000000);
            $expect('remainder distributed (month 1 ≠ month 12)',
                (float) BudgetLinePeriod::where('budget_line_id', $remLine->id)->where('month', 1)->value('target_amount')
                !== (float) BudgetLinePeriod::where('budget_line_id', $remLine->id)->where('month', 12)->value('target_amount'), true);

            // ── 14. Revision guards ───────────────────────────────────────────────
            $section('14 · Revision guards — after-FY-end + non-monotonic effective dates rejected');
            $guardB = $svc->createBudget('Guard Test', 2026, [
                ['ledger_id' => $sales->id, 'annual_target' => 1200000, 'allocation_method' => 'even'],
            ], null, false);
            $gLine = $guardB->lines()->first();
            $svc->reviseBudget($guardB, [$gLine->id => ['allocation_method' => 'even', 'annual_target' => 960000]], Carbon::parse('2026-08-01'));
            $afterFy = false;
            try {
                $svc->reviseBudget($guardB, [$gLine->id => ['allocation_method' => 'even', 'annual_target' => 1]], Carbon::parse('2027-06-01'));
            } catch (Throwable) {
                $afterFy = true;
            }
            $expect('revision effective AFTER FY end is rejected', $afterFy, true);
            $backdated = false;
            try {
                $svc->reviseBudget($guardB, [$gLine->id => ['allocation_method' => 'even', 'annual_target' => 1]], Carbon::parse('2026-05-01'));
            } catch (Throwable) {
                $backdated = true;
            }
            $expect('revision pre-dating the latest revision is rejected', $backdated, true);

            // ── 15. Group + ledger overlap counted once in the roll-up ────────────
            $section('15 · Overlapping group + descendant-ledger counted ONCE in totals');
            $ovB = $svc->createBudget('Overlap Test', 2026, [
                ['account_group_id' => $gid('Direct Expenses'), 'annual_target' => 500000, 'allocation_method' => 'even'],
                ['ledger_id' => $rent->id, 'annual_target' => 120000, 'allocation_method' => 'even'],
            ], null, false);
            $ov = $svc->variance($ovB, $apr, $may);
            $rentRow = collect($ov['lines'])->firstWhere('name', 'Rent');
            $expect('Rent (inside a budgeted group) flagged redundant_in_total', $rentRow['redundant_in_total'], true);
            $ovSum = $svc->summary($ovB, Carbon::parse('2026-05-31'));
            // Direct Expenses actual = Rent 120k + Electricity 30k = 150k; Rent NOT double-counted.
            $expect('expense actual counts Rent ONCE via the group (150,000)', $ovSum['expense']['actual'], 15000000);

            // ── 16. Actuals clamped to the budget fiscal year ─────────────────────
            $section('16 · Actuals window clamped to the budget FY (no adjacent-FY leak)');
            $sale(500000, '2027-05-01', 'INV-NEXTFY', $sales); // next FY (2027-28), outside this budget's FY
            $wide = $svc->variance($sumB->fresh(), Carbon::parse('2026-04-01'), Carbon::parse('2027-12-31'), false);
            $wSales = collect($wide['lines'])->firstWhere('name', 'Sales');
            $expect('Sales actual excludes the next-FY sale (still 250,000)', $wSales['actual'], 25000000);
            $expect('report window clamped to FY end (31-Mar-2027)', $wide['to'], '2027-03-31');

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
            $this->info('ALL ASSERTIONS PASSED — Phase 15A budgets verified.');

            return self::SUCCESS;
        }
        $this->error('SOME ASSERTIONS FAILED.');

        return self::FAILURE;
    }
}
