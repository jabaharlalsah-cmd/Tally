<?php

namespace App\Console\Commands;

use App\Console\Concerns\ResolvesActiveCompany;
use App\Exceptions\CostingMethodLockedException;
use App\Models\AccountGroup;
use App\Models\CompanyFeature;
use App\Models\Godown;
use App\Models\Ledger;
use App\Models\Scenario;
use App\Models\StockEntry;
use App\Models\StockGroup;
use App\Models\StockItem;
use App\Models\StockLot;
use App\Models\Unit;
use App\Models\Voucher;
use App\Models\VoucherEntry;
use App\Services\BalanceService;
use App\Services\CompanyProvisioner;
use App\Services\StockLotService;
use App\Services\StockService;
use App\Support\ActiveCompany;
use App\Support\ScenarioContext;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Phase 13 — THE FIFO / LIFO general-costing proof.
 *
 * Seeds three items — WidgetA (FIFO), WidgetB (weighted-average), WidgetC (LIFO) — runs the worked
 * purchase/sale scenarios and asserts every OUT cost, every lot remaining_qty, the Balance-Sheet
 * Stock-in-Hand tie-out per method and in total, the costing-method lock, alter/cancel replay, the
 * godown-transfer lot-order preservation, and the 15C scenario isolation of provisional lots.
 *
 * Run against a provisioned tenant DB, like the other single-company proofs:
 *   DB_DATABASE=tenant<slug> php artisan zerobook:prove-fifo-lifo
 */
class ProveFifoLifoCommand extends Command
{
    use ResolvesActiveCompany;

    protected $signature = 'zerobook:prove-fifo-lifo {--keep} {--company=}';

    protected $description = 'Prove Phase 13: FIFO/LIFO general costing — per-method OUT cost, lot depletion, Stock-in-Hand tie-out, costing-method lock, alter/cancel replay, godown-transfer order, scenario isolation';

    private bool $ok = true;

    private int $purchasesLedger;

    private int $salesLedger;

    private int $supplier;

    private int $debtor;

    public function handle(StockService $stock, StockLotService $lots): int
    {
        DB::beginTransaction();

        if (! $this->resolveActiveCompany(fresh: empty(trim((string) $this->option('company'))))) {
            DB::rollBack();

            return self::FAILURE;
        }

        $expect = function (string $label, $actual, $expected) {
            $pass = $actual === $expected;
            $this->line(sprintf('   [%s] %s = %s%s', $pass ? 'PASS' : 'FAIL', $label, json_encode($actual), $pass ? '' : ' (expected '.json_encode($expected).')'));
            $this->ok = $this->ok && $pass;
        };
        $section = fn (string $t) => $this->line("\n── {$t} ".str_repeat('─', max(1, 64 - mb_strlen($t))));

        try {
            $companyA = ActiveCompany::id();
            CompanyFeature::current()->update(['gst' => false, 'vat' => false]);
            $this->seedLedgers();

            $unit = Unit::firstOrCreate(['name' => 'Nos'], ['symbol' => 'Nos', 'decimal_places' => 0]);
            $group = StockGroup::firstOrCreate(['name' => 'General'], []);
            $main = Godown::where('name', 'Main Location')->value('id') ?? Godown::create(['name' => 'Main Location'])->id;
            $wh2 = Godown::firstOrCreate(['name' => 'Warehouse-2'], [])->id;

            $mkItem = fn (string $name, string $method) => StockItem::create([
                'name' => $name, 'stock_group_id' => $group->id, 'unit_id' => $unit->id,
                'opening_qty' => 0, 'opening_rate' => 0, 'opening_value' => 0, 'costing_method' => $method,
            ]);
            $A = $mkItem('WidgetA', 'fifo');
            $B = $mkItem('WidgetB', 'weighted_average');
            $C = $mkItem('WidgetC', 'lifo');

            $asOf = Carbon::parse('2026-06-30');
            $outRate = fn (Voucher $v) => (float) StockEntry::where('voucher_id', $v->id)->where('direction', 'out')->value('rate');
            $outValue = fn (Voucher $v) => (float) StockEntry::where('voucher_id', $v->id)->where('direction', 'out')->value('value');
            $lotRemaining = fn (int $itemId) => StockLot::where('stock_item_id', $itemId)->orderBy('id')->pluck('remaining_qty')->map(fn ($q) => (float) $q)->all();

            // ═══ 1 · FIFO worked scenario (WidgetA) ═════════════════════════════════
            $section('1 · FIFO — WidgetA');
            $this->purchase($stock, '2026-06-01', $A->id, $main, 100, 50);
            $this->purchase($stock, '2026-06-02', $A->id, $main, 100, 70);
            $sA1 = $this->sale($stock, '2026-06-05', $A->id, $main, 50, 120);
            $expect('sell 50 → OUT rate 50 (oldest lot)', $outRate($sA1), 50.0);
            $expect('sell 50 → OUT value 2500', $outValue($sA1), 2500.0);
            $expect('lot #1 remaining 50, lot #2 remaining 100', $lotRemaining($A->id), [50.0, 100.0]);
            $sA2 = $this->sale($stock, '2026-06-06', $A->id, $main, 100, 120);
            $expect('sell 100 → OUT rate 60 (50@50 + 50@70)/100', $outRate($sA2), 60.0);
            $expect('sell 100 → OUT value 6000', $outValue($sA2), 6000.0);
            $expect('lot #1 depleted to 0, lot #2 remaining 50', $lotRemaining($A->id), [0.0, 50.0]);
            $closeA = $stock->closingBalance($A->id, $asOf);
            $expect('WidgetA closing qty 50', $closeA['qty'], 50.0);
            $expect('WidgetA Stock-in-Hand = 3500 (50 × 70)', $closeA['value'], 3500.0);

            // ═══ 2 · LIFO worked scenario (WidgetC) ═════════════════════════════════
            $section('2 · LIFO — WidgetC');
            $this->purchase($stock, '2026-06-01', $C->id, $main, 100, 50);
            $this->purchase($stock, '2026-06-02', $C->id, $main, 100, 70);
            $sC1 = $this->sale($stock, '2026-06-05', $C->id, $main, 50, 120);
            $expect('sell 50 → OUT rate 70 (newest lot)', $outRate($sC1), 70.0);
            $expect('sell 50 → OUT value 3500', $outValue($sC1), 3500.0);
            $expect('lot #1 remaining 100, lot #2 remaining 50', $lotRemaining($C->id), [100.0, 50.0]);
            $sC2 = $this->sale($stock, '2026-06-06', $C->id, $main, 100, 120);
            $expect('sell 100 → OUT rate 60 (50@70 + 50@50)/100', $outRate($sC2), 60.0);
            $expect('sell 100 → OUT value 6000', $outValue($sC2), 6000.0);
            $expect('lot #2 depleted to 0, lot #1 remaining 50', $lotRemaining($C->id), [50.0, 0.0]);
            $closeC = $stock->closingBalance($C->id, $asOf);
            $expect('WidgetC Stock-in-Hand = 2500 (50 × 50)', $closeC['value'], 2500.0);

            // ═══ 3 · Weighted-average baseline (WidgetB) ════════════════════════════
            $section('3 · Weighted-average — WidgetB (unchanged path)');
            $this->purchase($stock, '2026-06-01', $B->id, $main, 100, 50);
            $this->purchase($stock, '2026-06-02', $B->id, $main, 100, 70);
            $sB = $this->sale($stock, '2026-06-05', $B->id, $main, 150, 120);
            $expect('sell 150 → OUT rate 60 (running average)', $outRate($sB), 60.0);
            $expect('sell 150 → OUT value 9000', $outValue($sB), 9000.0);
            $closeB = $stock->closingBalance($B->id, $asOf);
            $expect('WidgetB Stock-in-Hand = 3000 (50 × 60)', $closeB['value'], 3000.0);
            $expect('weighted-average item wrote NO lots', StockLot::where('stock_item_id', $B->id)->count(), 0);

            // ═══ 4 · Balance-Sheet tie-out ══════════════════════════════════════════
            $section('4 · Balance-Sheet Stock-in-Hand ties out');
            $expect('total closing = 3500 + 3000 + 2500 = 9000', $stock->totalClosingValue($asOf), 9000.0);
            $bs = app(BalanceService::class)->balanceSheet(Carbon::parse('2026-04-01'), $asOf);
            $expect('Balance Sheet balances (Assets = Liabilities + Net)', $bs['balanced'], true);

            // ═══ 5 · Costing-method lock ════════════════════════════════════════════
            $section('5 · Costing-method lock');
            $locked = false;
            try {
                $A->update(['costing_method' => 'weighted_average']);
            } catch (CostingMethodLockedException $e) {
                $locked = str_contains($e->getMessage(), 'locked once stock movements exist');
            }
            $expect('changing a live item\'s method is REFUSED', $locked, true);
            $expect('WidgetA is still FIFO (unchanged)', $A->fresh()->costing_method, 'fifo');
            // The "recreate the item" workaround: with all movements gone, the change succeeds.
            $tmp = StockItem::create(['name' => 'TmpLockCheck', 'stock_group_id' => $group->id, 'unit_id' => $unit->id, 'opening_qty' => 0, 'opening_rate' => 0, 'opening_value' => 0, 'costing_method' => 'fifo']);
            $tmp->update(['costing_method' => 'lifo']); // no stock_entries yet → allowed
            $expect('a movement-free item CAN change method', $tmp->fresh()->costing_method, 'lifo');

            // ═══ 6 · Alter a FIFO purchase (qty 100 → 80) ═══════════════════════════
            $section('6 · Alter a FIFO purchase — replay re-derives');
            // Re-seed a clean FIFO item for the alter/cancel/transfer/scenario scenarios.
            $D = $mkItem('WidgetD', 'fifo');
            $pD1 = $this->purchase($stock, '2026-06-01', $D->id, $main, 100, 50);
            $pD2 = $this->purchase($stock, '2026-06-02', $D->id, $main, 100, 70);
            $this->sale($stock, '2026-06-05', $D->id, $main, 120, 120); // depletes 100@50 + 20@70
            $expect('before alter: purchase#1 lot = 0, purchase#2 lot = 80',
                [(float) StockLot::where('voucher_id', $pD1->id)->value('remaining_qty'), (float) StockLot::where('voucher_id', $pD2->id)->value('remaining_qty')], [0.0, 80.0]);
            $this->alterPurchaseQty($stock, $lots, $pD1, $D->id, $main, 80, 50);
            $expect('after alter (100→80): purchase#1 lot original_qty = 80',
                (float) StockLot::where('voucher_id', $pD1->id)->value('original_qty'), 80.0);
            // Now only 80 @50 available before the 120 sale: 80@50 + 40@70 → purchase#2 lot remaining 60.
            $expect('after alter: purchase#1 lot depleted to 0', (float) StockLot::where('voucher_id', $pD1->id)->value('remaining_qty'), 0.0);
            $expect('after alter: purchase#2 lot remaining 60', (float) StockLot::where('voucher_id', $pD2->id)->value('remaining_qty'), 60.0);
            $expect('WidgetD closing after alter = 4200 (60 × 70)', $stock->closingBalance($D->id, $asOf)['value'], 4200.0);

            // ═══ 7 · Cancel a FIFO purchase ═════════════════════════════════════════
            $section('7 · Cancel a FIFO purchase — lot deleted, OUT replays');
            $E = $mkItem('WidgetE', 'fifo');
            $this->purchase($stock, '2026-06-01', $E->id, $main, 100, 50);
            $pE2 = $this->purchase($stock, '2026-06-02', $E->id, $main, 100, 70);
            $this->sale($stock, '2026-06-05', $E->id, $main, 50, 120); // depletes 50@50
            $expect('before cancel: 2 lots exist', StockLot::where('stock_item_id', $E->id)->count(), 2);
            $pE2->delete(); // cancel the 2nd purchase — cascade deletes its lot + refold replays
            $expect('after cancel: 1 lot remains', StockLot::where('stock_item_id', $E->id)->count(), 1);
            $expect('after cancel: remaining lot #1 = 50 (100 − 50 sold)',
                (float) StockLot::where('stock_item_id', $E->id)->value('remaining_qty'), 50.0);

            // Cancelling a SALE must UN-deplete the lots it drew from (the cancel-path refold must run).
            $H = $mkItem('WidgetH', 'fifo');
            $this->purchase($stock, '2026-06-01', $H->id, $main, 100, 50);
            $saleH = $this->sale($stock, '2026-06-05', $H->id, $main, 40, 120); // depletes 40 → remaining 60
            $expect('after a sale of 40: lot remaining 60', (float) StockLot::where('stock_item_id', $H->id)->value('remaining_qty'), 60.0);
            $saleH->delete(); // cancel the SALE → its refold must reset the lot it depleted back to 100
            $expect('cancelling the SALE re-derives the lot to 100 (cancel-path refold runs)',
                (float) StockLot::where('stock_item_id', $H->id)->value('remaining_qty'), 100.0);

            // ═══ 8 · Godown transfer preserves lot order ════════════════════════════
            $section('8 · Godown transfer preserves FIFO lot age');
            $F = $mkItem('WidgetF', 'fifo');
            $this->purchase($stock, '2026-06-01', $F->id, $main, 100, 50);   // oldest, at Main
            $this->purchase($stock, '2026-06-02', $F->id, $wh2, 100, 70);    // newest, at Warehouse-2
            $this->transfer($stock, '2026-06-03', $F->id, $main, $wh2, 100);  // move the old lot to Warehouse-2
            $sF = $this->sale($stock, '2026-06-05', $F->id, $wh2, 50, 120);   // sell from Warehouse-2
            $expect('sale from Warehouse-2 depletes the OLDEST lot (rate 50, not 70)', $outRate($sF), 50.0);

            // ═══ 9 · Scenario isolation of provisional lots (15C) ═══════════════════
            $section('9 · Provisional FIFO purchase does not touch the real books');
            CompanyFeature::current()->update(['scenarios' => true]);
            $G = $mkItem('WidgetG', 'fifo');
            $this->purchase($stock, '2026-06-01', $G->id, $main, 100, 50);
            $realCount = count($lots->remainingLotsFor($G->id));
            $scenario = app(\App\Services\ScenarioService::class)->create('FIFO What-if', null, null);
            // A provisional purchase under the scenario (posted under its context, like VoucherScreen).
            ScenarioContext::runWith([$scenario->id], function () use ($stock, $G, $main, $scenario) {
                $this->purchase($stock, '2026-06-02', $G->id, $main, 100, 90, $scenario->id);
            });
            $expect('default remainingLotsFor unchanged by the provisional purchase', count($lots->remainingLotsFor($G->id)), $realCount);
            $withScen = ScenarioContext::runWith([$scenario->id], fn () => count($lots->remainingLotsFor($G->id)));
            $expect('the provisional lot participates when the scenario is included', $withScen, $realCount + 1);

            // ═══ 10 · Company scoping ═══════════════════════════════════════════════
            $section('10 · Lots are company-scoped');
            $companyB = app(CompanyProvisioner::class)->create('Prove FifoLifo B');
            $bLots = ActiveCompany::runAs($companyB->id, fn () => StockLot::count());
            $expect('company B sees none of company A\'s lots', $bLots, 0);

            // ═══ 11 · A FIFO/LIFO item cannot carry a master opening balance ════════
            $section('11 · FIFO/LIFO items reject a master opening balance');
            $rejected = false;
            try {
                StockItem::create(['name' => 'BadOpening', 'stock_group_id' => $group->id, 'unit_id' => $unit->id,
                    'opening_qty' => 10, 'opening_rate' => 5, 'opening_value' => 50, 'costing_method' => 'fifo']);
            } catch (CostingMethodLockedException $e) {
                $rejected = str_contains($e->getMessage(), 'zero opening stock');
            }
            $expect('a FIFO item with opening stock is REFUSED (no silent drop)', $rejected, true);
            $waOpening = StockItem::create(['name' => 'WAOpening', 'stock_group_id' => $group->id, 'unit_id' => $unit->id,
                'opening_qty' => 10, 'opening_rate' => 5, 'opening_value' => 50, 'costing_method' => 'weighted_average']);
            $expect('a weighted-average item CAN carry opening stock', (float) $waOpening->fresh()->opening_qty, 10.0);

            $this->line('');
            $this->line($this->ok ? '✅ ALL FIFO/LIFO ASSERTIONS PASSED' : '❌ SOME ASSERTIONS FAILED');
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

    private function seedLedgers(): void
    {
        $gid = fn ($n) => AccountGroup::where('name', $n)->value('id');
        $l = fn ($name, $grp) => Ledger::firstOrCreate(['name' => $name], ['group_id' => $gid($grp), 'opening_balance' => 0, 'opening_balance_type' => null, 'country' => 'India']);
        $this->purchasesLedger = $l('Purchases', 'Purchase Accounts')->id;
        $this->salesLedger = $l('Sales', 'Sales Accounts')->id;
        $this->supplier = $l('A Supplier', 'Sundry Creditors')->id;
        $this->debtor = $l('A Customer', 'Sundry Debtors')->id;
    }

    /** A purchase: Dr Purchases / Cr Supplier + a stock IN of $qty @ $rate. */
    private function purchase(StockService $stock, string $date, int $itemId, int $godownId, float $qty, float $rate, ?int $scenarioId = null): Voucher
    {
        $amount = round($qty * $rate, 2);
        $v = $this->mkVoucher('purchase', $date, [[$this->purchasesLedger, 'Dr', $amount], [$this->supplier, 'Cr', $amount]], $scenarioId);
        $stock->persistItems($v, [['stock_item_id' => $itemId, 'godown_id' => $godownId, 'qty' => $qty, 'rate' => $rate, 'amount' => $amount, 'direction' => 'in']]);

        return $v;
    }

    /** A sale: Dr Debtor / Cr Sales + a stock OUT of $qty (cost derived server-side). */
    private function sale(StockService $stock, string $date, int $itemId, int $godownId, float $qty, float $saleRate, ?int $scenarioId = null): Voucher
    {
        $amount = round($qty * $saleRate, 2);
        $v = $this->mkVoucher('sales', $date, [[$this->debtor, 'Dr', $amount], [$this->salesLedger, 'Cr', $amount]], $scenarioId);
        $stock->persistItems($v, [['stock_item_id' => $itemId, 'godown_id' => $godownId, 'qty' => $qty, 'rate' => $saleRate, 'amount' => $amount, 'direction' => 'out']]);

        return $v;
    }

    /** A Stock Journal godown transfer (value-neutral) of $qty from → to. */
    private function transfer(StockService $stock, string $date, int $itemId, int $fromGodownId, int $toGodownId, float $qty): Voucher
    {
        $v = $this->mkVoucher('stock_journal', $date, []);
        $stock->persistTransfer($v, $itemId, $fromGodownId, $toGodownId, $qty);

        return $v;
    }

    /**
     * Simulate the VoucherScreen alter path for a purchase's quantity: delete the old stock rows (their
     * lots cascade), re-persist the IN at the new qty, then refold the item to replay dependent OUTs.
     */
    private function alterPurchaseQty(StockService $stock, StockLotService $lots, Voucher $purchase, int $itemId, int $godownId, float $newQty, float $rate): void
    {
        StockEntry::where('voucher_id', $purchase->id)->delete(); // cascade-deletes the old lot
        $amount = round($newQty * $rate, 2);
        VoucherEntry::where('voucher_id', $purchase->id)->where('dr_cr', 'Dr')->update(['amount' => $amount]);
        VoucherEntry::where('voucher_id', $purchase->id)->where('dr_cr', 'Cr')->update(['amount' => $amount]);
        $stock->persistItems($purchase, [['stock_item_id' => $itemId, 'godown_id' => $godownId, 'qty' => $newQty, 'rate' => $rate, 'amount' => $amount, 'direction' => 'in']]);
        $lots->refoldItems([$itemId]);
    }

    /** Create a voucher (+ balanced ledger entries). Amounts in rupees. */
    private function mkVoucher(string $type, string $date, array $lines, ?int $scenarioId = null): Voucher
    {
        $fy = Voucher::fyStartFor(Carbon::parse($date));
        $v = Voucher::create([
            'type' => $type, 'number' => Voucher::nextNumber($type, $fy), 'fy_start' => $fy,
            'date' => $date, 'narration' => 'fifo/lifo proof', 'scenario_id' => $scenarioId,
        ]);
        foreach ($lines as $i => [$lid, $side, $amt]) {
            VoucherEntry::create(['voucher_id' => $v->id, 'ledger_id' => $lid, 'dr_cr' => $side, 'amount' => $amt, 'line_no' => $i + 1]);
        }

        return $v;
    }
}
