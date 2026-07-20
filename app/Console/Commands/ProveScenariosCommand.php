<?php

namespace App\Console\Commands;

use App\Console\Concerns\ResolvesActiveCompany;
use App\Exceptions\ScenarioPromotionException;
use App\Models\AccountGroup;
use App\Models\CompanyFeature;
use App\Models\Godown;
use App\Models\Ledger;
use App\Models\Scenario;
use App\Models\ScenarioPromotion;
use App\Models\StockEntry;
use App\Models\StockGroup;
use App\Models\StockItem;
use App\Models\TdsDeducteeYtd;
use App\Models\TdsDeduction;
use App\Models\TdsSection;
use App\Models\Unit;
use App\Models\Voucher;
use App\Models\VoucherEntry;
use App\Services\BalanceService;
use App\Services\BillService;
use App\Services\CompanyProvisioner;
use App\Services\ScenarioService;
use App\Services\StockService;
use App\Services\Sync\SyncService;
use App\Services\TdsService;
use App\Support\ActiveCompany;
use App\Support\ScenarioContext;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Phase 15C — THE Scenarios proof.
 *
 * A scenario is a named container of PROVISIONAL vouchers (vouchers.scenario_id set) that are
 * excluded from the real books until promoted. This proves the whole contract:
 *   • the F11 gate;
 *   • BYTE-IDENTICAL default — every report reads real books only until a scenario is selected;
 *   • view-with, multiple scenarios, and the impact delta;
 *   • bill-wise + inventory exclusion by default, inclusion under context;
 *   • return files (GSTR-1 / 26Q) REFUSE when a provisional voucher sits in the period;
 *   • TDS: a provisional payment computes its deduction but NEVER rolls real ytd forward;
 *   • promotion is one-way + transactional (rolls back wholesale on any failure) and re-runs TDS;
 *   • sync excludes provisional vouchers;
 *   • scenarios are company-scoped.
 *
 * Run against a provisioned tenant DB, like the other single-company proofs:
 *   DB_DATABASE=tenant<slug> php artisan zerobook:prove-scenarios
 */
class ProveScenariosCommand extends Command
{
    use ResolvesActiveCompany;

    protected $signature = 'zerobook:prove-scenarios {--keep} {--company=}';

    protected $description = 'Prove Phase 15C: provisional scenarios — byte-identical default, view-with, impact, bill-wise/inventory/TDS/return-file/sync interactions, one-way transactional promotion, company scoping';

    private bool $ok = true;

    /** @var callable */
    private $expect;

    /** @var callable */
    private $section;

    public function handle(BalanceService $balances, ScenarioService $scenarios, TdsService $tds): int
    {
        DB::beginTransaction();

        if (! $this->resolveActiveCompany(fresh: empty(trim((string) $this->option('company'))))) {
            DB::rollBack();

            return self::FAILURE;
        }

        $this->expect = function (string $label, $actual, $expected) {
            $pass = $actual === $expected;
            $this->line(sprintf('   [%s] %s = %s%s', $pass ? 'PASS' : 'FAIL', $label, json_encode($actual), $pass ? '' : ' (expected '.json_encode($expected).')'));
            $this->ok = $this->ok && $pass;
        };
        $this->section = fn (string $t) => $this->line("\n── {$t} ".str_repeat('─', max(1, 64 - mb_strlen($t))));
        $expect = $this->expect;
        $section = $this->section;

        try {
            $companyA = ActiveCompany::id();
            CompanyFeature::current()->update(['gst' => false, 'vat' => false, 'bill_by_bill' => true, 'scenarios' => true]);

            $from = Carbon::parse('2026-04-01');
            $asOf = Carbon::parse('2026-06-30');

            // Real books baseline — a tiny but complete set of real vouchers.
            $this->seedRealBooks();
            $realNet = $balances->profitAndLoss($from, $asOf)['net'];
            $realAssets = $balances->balanceSheet($from, $asOf)['total_assets'];

            // ═══ 0 · F11 gate ═══════════════════════════════════════════════════════
            $section('0 · F11 scenarios gate');
            $expect('scenarios flag ON → ScenarioService::enabled() true', $scenarios->enabled(), true);
            CompanyFeature::current()->update(['scenarios' => false]);
            $expect('scenarios flag OFF → enabled() false', app(ScenarioService::class)->enabled(), false);
            CompanyFeature::current()->update(['scenarios' => true]);

            // ═══ 1 · Byte-identical default ═════════════════════════════════════════
            $section('1 · Byte-identical default (provisional excluded from real books)');
            $s1 = $scenarios->create('Big Order', 'A hypothetical large sale', null);
            $sales = Ledger::where('name', 'Sales')->value('id');
            $cust = Ledger::where('name', 'A Customer')->value('id');
            // A provisional sale of ₹1,000,000 tagged to S1.
            $this->mk('sales', '2026-06-10', [[$cust, 'Dr', 1000000], [$sales, 'Cr', 1000000]], $s1->id);

            $expect('default selection is empty (real books)', ScenarioContext::selected(), []);
            $expect('isViewingScenarios() false by default', ScenarioContext::isViewingScenarios(), false);
            $expect('Net Profit UNCHANGED by the provisional sale (byte-identical)',
                $balances->profitAndLoss($from, $asOf)['net'], $realNet);
            $expect('Total Assets UNCHANGED by the provisional sale',
                $balances->balanceSheet($from, $asOf)['total_assets'], $realAssets);
            $expect('the provisional voucher IS in the DB (just tagged)',
                Voucher::whereNotNull('scenario_id')->count(), 1);

            // ═══ 2 · View WITH scenario ═════════════════════════════════════════════
            $section('2 · View with scenario (context widens the read)');
            $scenNet = ScenarioContext::runWith([$s1->id], fn () => $balances->profitAndLoss($from, $asOf)['net']);
            $expect('Net Profit rises by the ₹1,000,000 sale under [S1]', $scenNet, $realNet + 100_000_000);
            $expect('…and reverts to real books once the context closes',
                $balances->profitAndLoss($from, $asOf)['net'], $realNet);

            // ═══ 3 · Multiple scenarios ═════════════════════════════════════════════
            $section('3 · Multiple scenarios combine additively');
            $s2 = $scenarios->create('Cut Costs', 'A hypothetical rent saving', null);
            $rent = Ledger::where('name', 'Office Rent')->value('id');
            $cashId = Ledger::where('name', 'Cash')->value('id');
            // A provisional expense of ₹200,000 tagged to S2 (lowers net).
            $this->mk('payment', '2026-06-11', [[$rent, 'Dr', 200000], [$cashId, 'Cr', 200000]], $s2->id);

            $onlyS1 = ScenarioContext::runWith([$s1->id], fn () => $balances->profitAndLoss($from, $asOf)['net']);
            $onlyS2 = ScenarioContext::runWith([$s2->id], fn () => $balances->profitAndLoss($from, $asOf)['net']);
            $both = ScenarioContext::runWith([$s1->id, $s2->id], fn () => $balances->profitAndLoss($from, $asOf)['net']);
            $expect('[S1] only → +1,000,000', $onlyS1, $realNet + 100_000_000);
            $expect('[S2] only → −200,000', $onlyS2, $realNet - 20_000_000);
            $expect('[S1,S2] → +800,000 (both fold in)', $both, $realNet + 80_000_000);

            // ═══ 4 · Impact report ══════════════════════════════════════════════════
            $section('4 · Impact report (real vs +scenario delta, drillable)');
            $impact = $scenarios->impactReport($s1, $asOf);
            $expect('impact net_profit delta = +₹1,000,000', $impact['headline']['net_profit']['delta'], 100_000_000);
            $expect('impact real column == real net', $impact['headline']['net_profit']['real'], $realNet);
            $expect('impact voucher_count = 1', $impact['voucher_count'], 1);
            $expect('impact ledger_delta names the Sales ledger',
                collect($impact['ledger_delta'])->pluck('id')->contains($sales), true);

            // ═══ 5 · Bill-wise exclusion ════════════════════════════════════════════
            $section('5 · Bill-wise: provisional bills excluded by default');
            $s3 = $scenarios->create('Provisional Bill', null, null);
            $party = Ledger::where('name', 'A Customer')->value('id');
            $pv = $this->mk('sales', '2026-06-12', [[$party, 'Dr', 500000], [$sales, 'Cr', 500000]], $s3->id);
            $partyEntry = VoucherEntry::where('voucher_id', $pv->id)->where('ledger_id', $party)->value('id');
            \App\Models\BillAllocation::create([
                'voucher_id' => $pv->id, 'ledger_id' => $party, 'voucher_entry_id' => $partyEntry,
                'ref_type' => 'new', 'ref_name' => 'WHATIF/001', 'amount' => 500000, 'due_date' => null,
            ]);
            $billsDefault = collect($scenarios ? app(BillService::class)->bills([$party]) : [])->pluck('ref_name')->all();
            $billsWith = ScenarioContext::runWith([$s3->id], fn () => collect(app(BillService::class)->bills([$party]))->pluck('ref_name')->all());
            $expect('provisional bill WHATIF/001 hidden by default', in_array('WHATIF/001', $billsDefault, true), false);
            $expect('provisional bill WHATIF/001 shown under [S3]', in_array('WHATIF/001', $billsWith, true), true);

            // ═══ 6 · Inventory exclusion ════════════════════════════════════════════
            $section('6 · Inventory: provisional stock movement excluded by default');
            $stock = app(StockService::class);
            $item = $this->seedStockItem('Widget');
            $godownId = Godown::where('name', 'Main Location')->value('id') ?? Godown::orderBy('id')->value('id');
            $s4 = $scenarios->create('Stock What-if', null, null);
            $sv = $this->mk('receipt', '2026-06-13', [[$cashId, 'Dr', 1000], [Ledger::where('name', 'Sales')->value('id'), 'Cr', 1000]], $s4->id);
            StockEntry::create([
                'voucher_id' => $sv->id, 'stock_item_id' => $item->id, 'godown_id' => $godownId,
                'direction' => 'in', 'movement_type' => 'purchase', 'quantity' => 10, 'rate' => 100, 'value' => 1000, 'line_no' => 1,
            ]);
            $qtyDefault = $stock->godownQuantity($item->id, $godownId, $asOf);
            $qtyWith = ScenarioContext::runWith([$s4->id], fn () => $stock->godownQuantity($item->id, $godownId, $asOf));
            $expect('closing qty 0 by default (provisional movement excluded)', $qtyDefault, 0.0);
            $expect('closing qty 10 under [S4]', $qtyWith, 10.0);

            // ═══ 7 · Return-file guards refuse on a provisional voucher in-period ════
            $section('7 · Return files refuse when a provisional voucher sits in the period');
            $s5 = $scenarios->create('April What-if', null, null);
            $this->mk('sales', '2026-04-15', [[$cust, 'Dr', 100000], [$sales, 'Cr', 100000]], $s5->id);
            $gstRefused = false;
            try {
                app(\App\Services\Gst\GstReturnService::class)->gstr1('042026');
            } catch (\RuntimeException $e) {
                $gstRefused = str_contains($e->getMessage(), 'Scenario vouchers exist');
            }
            $expect('GSTR-1 REFUSES for April (provisional voucher present)', $gstRefused, true);

            $exporter = app(\App\Services\Tds\Form26qExporter::class);
            $gather = new \ReflectionMethod($exporter, 'gather');
            $gather->setAccessible(true);
            $q1 = $gather->invoke($exporter, 2026, 1);
            $expect('26Q gather() returns [] (refused) with a provisional in Q1', $q1, []);

            // ═══ 8 · TDS: provisional computes but never rolls real ytd ═════════════
            $section('8 · TDS — provisional deduction does NOT touch real year-to-date');
            CompanyFeature::current()->update(['tds' => true]);
            $tdsResult = $this->proveTdsProvisionalAndPromote($scenarios, $tds, $expect);

            // ═══ 9 · Promotion — one-way, real books updated ════════════════════════
            $section('9 · Promotion folds the scenario into the real books (one-way)');
            $s6 = $scenarios->create('To Promote', null, null);
            $this->mk('sales', '2026-06-18', [[$cust, 'Dr', 500000], [$sales, 'Cr', 500000]], $s6->id);
            $preNet = $balances->profitAndLoss($from, $asOf)['net'];
            $res = $scenarios->promote($s6->fresh(), null);
            $expect('promote() reports 1 voucher promoted', $res['count'], 1);
            $expect('promoted voucher is now REAL (scenario_id null)',
                Voucher::where('promoted_from_scenario_id', $s6->id)->whereNull('scenario_id')->count(), 1);
            $expect('promoted_from stamp records the origin scenario',
                (int) Voucher::whereNotNull('promoted_from_scenario_id')->where('promoted_from_scenario_id', $s6->id)->value('promoted_from_scenario_id'), $s6->id);
            $expect('scenario_promotions logged the promotion (name + count)',
                ScenarioPromotion::where('scenario_id', $s6->id)->where('voucher_count', 1)->exists(), true);
            $expect('a fully-promoted scenario is deactivated', (bool) $s6->fresh()->is_active, false);
            $expect('real Net Profit now INCLUDES the promoted ₹500,000', $balances->profitAndLoss($from, $asOf)['net'], $preNet + 50_000_000);

            // ═══ 10 · Promotion is transactional — rolls back wholesale ═════════════
            $section('10 · Promotion rolls back entirely on any validation failure');
            $s7 = $scenarios->create('Broken', null, null);
            $good = $this->mk('sales', '2026-06-19', [[$cust, 'Dr', 300000], [$sales, 'Cr', 300000]], $s7->id);
            $bad = $this->mk('journal', '2026-06-19', [[$rent, 'Dr', 100000], [$cashId, 'Cr', 100000]], $s7->id);
            // Corrupt the second voucher so it no longer balances (Dr 100000 ≠ Cr 60000).
            VoucherEntry::where('voucher_id', $bad->id)->where('dr_cr', 'Cr')->update(['amount' => 60000]);
            $promotionsBefore = ScenarioPromotion::count();
            $threw = false;
            try {
                $scenarios->promote($s7->fresh(), null);
            } catch (ScenarioPromotionException $e) {
                $threw = true;
            }
            $expect('promote() throws ScenarioPromotionException on the broken voucher', $threw, true);
            $expect('the GOOD voucher is STILL provisional (whole promotion rolled back)',
                (int) Voucher::whereKey($good->id)->value('scenario_id'), $s7->id);
            $expect('no scenario_promotions row was written', ScenarioPromotion::count(), $promotionsBefore);
            $expect('the broken scenario stays active (nothing changed)', (bool) $s7->fresh()->is_active, true);

            // ═══ 11 · Sync excludes provisional vouchers (snapshot AND incremental pull) ═════
            $section('11 · Sync excludes provisional vouchers');
            $snapMethod = new \ReflectionMethod(SyncService::class, 'snapshot');
            $snapMethod->setAccessible(true);
            $snapshot = $snapMethod->invoke(app(SyncService::class));
            $snapshotIds = collect($snapshot['vouchers'] ?? [])->pluck('id')->all();
            $stillProvisional = Voucher::whereNotNull('scenario_id')->pluck('id')->all();
            $overlap = array_values(array_intersect($snapshotIds, $stillProvisional));
            $expect('no provisional voucher appears in the sync snapshot', $overlap, []);
            $expect('every real voucher IS in the snapshot',
                count($snapshotIds) === Voucher::whereNull('scenario_id')->count(), true);

            // The INCREMENTAL pull path (a provisional save still logs a sync_changes row) must
            // also skip it — else it would leak into a desktop mirror as a real voucher.
            $cursorBefore = app(SyncService::class)->currentCursor();
            $sSync = $scenarios->create('Sync What-if', null, null);
            $provSyncVoucher = $this->mk('sales', '2026-06-24', [[$cust, 'Dr', 100000], [$sales, 'Cr', 100000]], $sSync->id);
            $realSyncVoucher = $this->mk('sales', '2026-06-24', [[$cust, 'Dr', 50000], [$sales, 'Cr', 50000]]);
            $pull = app(SyncService::class)->pull($cursorBefore);
            $pulledIds = collect($pull['vouchers'] ?? [])->pluck('voucher.id')->filter()->all();
            $expect('incremental pull EXCLUDES the provisional voucher', in_array($provSyncVoucher->id, $pulledIds, true), false);
            $expect('incremental pull INCLUDES the real voucher', in_array($realSyncVoucher->id, $pulledIds, true), true);

            // ═══ 12 · Company scoping ═══════════════════════════════════════════════
            $section('12 · Scenarios are company-scoped');
            $companyB = app(CompanyProvisioner::class)->create('Prove Scenarios B');
            ActiveCompany::set($companyB->id);
            CompanyFeature::current()->update(['scenarios' => true]);
            $bScenario = app(ScenarioService::class)->create('B-only Scenario', null, null);
            $bActiveIds = app(ScenarioService::class)->activeScenarios()->pluck('id')->all();
            $expect('company B sees ONLY its own scenario', $bActiveIds, [$bScenario->id]);
            ActiveCompany::set($companyA);
            $aActiveIds = app(ScenarioService::class)->activeScenarios()->pluck('id')->all();
            $expect('company A does NOT see company B\'s scenario', in_array($bScenario->id, $aActiveIds, true), false);
            $expect('company A still sees its own active scenarios', count($aActiveIds) > 0, true);

            // ═══ 13 · Report picker never honours a stale/inactive selection ════════
            $section('13 · Report picker filters stale / inactive / cross-company selections');
            $svcA = app(ScenarioService::class);
            $stale = $svcA->create('Stale Pick', null, null);
            $svcA->setActive($stale, false);
            $expect('setSessionSelected drops a deactivated scenario id', $svcA->setSessionSelected([$stale->id]), []);
            $expect('setSessionSelected drops company B\'s scenario id (cross-company)', $svcA->setSessionSelected([$bScenario->id]), []);
            $expect('sessionSelected resolves to real books after invalid picks', $svcA->sessionSelected(), []);

            $this->line('');
            $this->line($this->ok ? '✅ ALL SCENARIO ASSERTIONS PASSED' : '❌ SOME ASSERTIONS FAILED');
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error('EXCEPTION: '.$e->getMessage());
            $this->line($e->getFile().':'.$e->getLine());
            $this->line($e->getTraceAsString());

            return self::FAILURE;
        }

        if ($this->option('keep')) {
            DB::commit();
            $this->warn('--keep: data COMMITTED to this tenant DB.');
        } else {
            DB::rollBack();
        }

        return $this->ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * TDS sub-proof: a provisional Payment computes its deduction (a TdsDeduction row IS created)
     * but never rolls tds_deductee_ytd forward; promoting it recomputes against real state and
     * rolls ytd forward exactly once.
     */
    private function proveTdsProvisionalAndPromote(ScenarioService $scenarios, TdsService $tds, callable $expect): void
    {
        $gid = fn ($n) => AccountGroup::where('name', $n)->value('id');
        $payableId = $tds->payableLedgerId();
        if (! $payableId) {
            $expect('TDS Payable ledger exists (provisioned)', false, true);

            return;
        }
        $section = TdsSection::where('code', '393-194J')->first();
        if (! $section) {
            $expect('393-194J section exists (provisioned)', false, true);

            return;
        }

        $deducteeId = Ledger::create([
            'name' => 'Consultant Pvt Ltd', 'group_id' => $gid('Sundry Creditors'),
            'deductee_pan' => 'AAACC1234C', 'deductee_type' => 'company_firm_llp',
            'default_tds_section_id' => $section->id, 'country' => 'India',
        ])->id;
        $feesId = Ledger::create(['name' => 'Consultancy Fees', 'group_id' => $gid('Indirect Expenses'), 'country' => 'India'])->id;

        $st = $scenarios->create('TDS What-if', null, null);
        // Provisional payment: base ₹100,000 crosses the ₹50,000 threshold → 10% = ₹10,000 withheld.
        $v = $this->mk('payment', '2026-06-15', [
            [$feesId, 'Dr', 100000],
            [$deducteeId, 'Cr', 90000],
            [$payableId, 'Cr', 10000],
        ], $st->id);
        $lineEntryIds = [];
        foreach (VoucherEntry::where('voucher_id', $v->id)->orderBy('line_no')->get()->values() as $i => $e) {
            $lineEntryIds[$i] = $e->id;
        }
        $payload = [
            'lines' => [
                ['ledger_id' => $feesId, 'dr_cr' => 'Dr', 'amount' => 100000],
                ['ledger_id' => $deducteeId, 'dr_cr' => 'Cr', 'amount' => 90000],
                ['ledger_id' => $payableId, 'dr_cr' => 'Cr', 'amount' => 10000],
            ],
            'tds_deduction' => ['deductee_ledger_id' => $deducteeId, 'tds_section_id' => $section->id],
        ];
        $tds->persist($v, $payload, $lineEntryIds);

        $fy = Voucher::statutoryFyStartFor(Carbon::parse('2026-06-15'));
        $expect('provisional voucher DID create a TdsDeduction row (₹10,000)',
            (float) (TdsDeduction::where('voucher_id', $v->id)->value('deducted_amount') ?? 0), 10000.0);
        $expect('provisional TDS did NOT roll real ytd forward (no ytd row)',
            TdsDeducteeYtd::where('deductee_ledger_id', $deducteeId)->where('tds_section_id', $section->id)->where('fy_start', $fy)->exists(), false);

        // Capture the deduction (= the posted Cr TDS Payable line) BEFORE promotion.
        $dedBefore = (float) TdsDeduction::where('voucher_id', $v->id)->value('deducted_amount');

        // Promote → roll the EXISTING (frozen, GL-consistent) deduction into real ytd (no recompute).
        $scenarios->promote($st->fresh(), null);

        $expect('promotion did NOT recompute the deduction (stays GL-consistent)',
            (float) TdsDeduction::where('voucher_id', $v->id)->value('deducted_amount'), $dedBefore);
        $ytd = TdsDeducteeYtd::where('deductee_ledger_id', $deducteeId)->where('tds_section_id', $section->id)->where('fy_start', $fy)->first();
        $expect('after promotion the ytd row EXISTS', $ytd !== null, true);
        $expect('promoted ytd paid_amount = ₹100,000', $ytd ? (float) $ytd->paid_amount : null, 100000.0);
        $expect('promoted ytd deducted_amount = ₹10,000', $ytd ? (float) $ytd->deducted_amount : null, 10000.0);
        $expect('promoted TDS voucher is now real (scenario_id null)', Voucher::whereKey($v->id)->value('scenario_id') === null, true);
        $expect('exactly one TdsDeduction row remains for the voucher (re-persisted, not duplicated)',
            TdsDeduction::where('voucher_id', $v->id)->count(), 1);
    }

    /** The real-books baseline: capital, a sale, a purchase, and some indirect expenses. */
    private function seedRealBooks(): void
    {
        $gid = fn ($n) => AccountGroup::where('name', $n)->value('id');
        $cash = Ledger::where('name', 'Cash')->first();
        $cash->update(['opening_balance' => 0, 'opening_balance_type' => 'Dr']);
        $l = fn ($name, $grp, $ob = 0, $obt = null) => Ledger::firstOrCreate(
            ['name' => $name],
            ['group_id' => $gid($grp), 'opening_balance' => $ob, 'opening_balance_type' => $obt, 'country' => 'India']
        );

        $l('Capital A/c', 'Capital Account', 500000, 'Cr');
        $cust = $l('A Customer', 'Sundry Debtors');
        $supp = $l('A Supplier', 'Sundry Creditors');
        $sales = $l('Sales', 'Sales Accounts');
        $purch = $l('Purchases', 'Purchase Accounts');
        $rent = $l('Office Rent', 'Indirect Expenses');
        $cashId = $cash->id;

        // Real: sales 300,000; purchase 100,000; rent 50,000 → real net = 300k − 100k − 50k = 150,000.
        $this->mk('sales', '2026-06-05', [[$cust->id, 'Dr', 300000], [$sales->id, 'Cr', 300000]]);
        $this->mk('purchase', '2026-06-06', [[$purch->id, 'Dr', 100000], [$supp->id, 'Cr', 100000]]);
        $this->mk('payment', '2026-06-07', [[$rent->id, 'Dr', 50000], [$cashId, 'Cr', 50000]]);
    }

    private function seedStockItem(string $name): StockItem
    {
        $unit = Unit::firstOrCreate(['name' => 'Nos'], ['decimals' => 0]);
        $group = StockGroup::firstOrCreate(['name' => 'General'], []);

        return StockItem::firstOrCreate(
            ['name' => $name],
            ['stock_group_id' => $group->id, 'unit_id' => $unit->id, 'opening_quantity' => 0, 'opening_value' => 0]
        );
    }

    /** Create a voucher (+ entries) with an OPTIONAL scenario tag. Amounts are in rupees. */
    private function mk(string $type, string $date, array $lines, ?int $scenarioId = null): Voucher
    {
        $fy = Voucher::fyStartFor(Carbon::parse($date));
        $v = Voucher::create([
            'type' => $type,
            'number' => Voucher::nextNumber($type, $fy),
            'fy_start' => $fy,
            'date' => $date,
            'narration' => 'scenario proof',
            'scenario_id' => $scenarioId,
        ]);
        foreach ($lines as $i => [$lid, $side, $amt]) {
            VoucherEntry::create(['voucher_id' => $v->id, 'ledger_id' => $lid, 'dr_cr' => $side, 'amount' => $amt, 'line_no' => $i + 1]);
        }

        return $v;
    }
}
