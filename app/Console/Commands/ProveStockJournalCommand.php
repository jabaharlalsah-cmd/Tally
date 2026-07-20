<?php

namespace App\Console\Commands;

use App\Console\Concerns\ResolvesActiveCompany;
use App\Livewire\VoucherScreen;
use App\Models\AccountGroup;
use App\Models\CompanyFeature;
use App\Models\Godown;
use App\Models\Ledger;
use App\Models\StockEntry;
use App\Models\StockGroup;
use App\Models\StockItem;
use App\Models\Unit;
use App\Models\Voucher;
use App\Models\VoucherEntry;
use App\Services\BalanceService;
use App\Services\StockService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Phase 6C numeric proof. Posts Stock Journal (transfer + consumption) and Physical
 * Stock vouchers through the SAME endpoint the UI uses — VoucherScreen::post() —
 * and asserts, to the unit/paise:
 *
 *   • Transfer: 50 @ A, move 20 to B ⇒ A holds 30, B holds 20 (godown-level), while
 *     the item's TOTAL qty and value and its weighted-average rate are UNCHANGED.
 *   • Consumption: issue 10 at the current average ⇒ qty −10, value −10×avg, and
 *     ZERO voucher_entries posted.
 *   • Physical Stock shortage / excess / no-variance, each valued at the current
 *     average so the average is undisturbed; zero variance posts no row.
 *   • A voucher with zero voucher_entries posts fine (0 = 0 is balanced).
 *   • Trial Balance is completely unaffected.
 *
 * Rolls everything back unless --keep is given.
 */
class ProveStockJournalCommand extends Command
{
    use ResolvesActiveCompany;
    protected $signature = 'zerobook:prove-stock-journal {--keep : keep the seeded scenario in the DB} {--company= : run in this company (slug or id) instead of a fresh throwaway one}';

    protected $description = 'Post Stock Journal + Physical Stock vouchers via the shared path and prove godown-level movement, weighted-average preservation, and zero-ledger posting';

    public function handle(BalanceService $bs, StockService $stock): int
    {
        $keep = $this->option('keep');
        DB::beginTransaction();

        // Phase 12A — every proof runs in its OWN fresh company (seeded chart via
        // CompanyProvisioner), created inside this transaction so it rolls back
        // with everything else unless --keep. Re-runs never collide, and each
        // green proof doubles as a per-company isolation check.
        if (! $this->resolveActiveCompany(fresh: empty(trim((string) $this->option('company'))))) {
            DB::rollBack();

            return self::FAILURE;
        }

        // Clean prior test data (keep reserved masters + the seeded Main Location).
        StockEntry::query()->delete();
        VoucherEntry::query()->delete();
        Voucher::query()->delete();
        Ledger::where('is_reserved', false)->delete();
        StockItem::query()->delete();
        StockGroup::query()->delete();
        Unit::query()->delete();
        Godown::where('is_reserved', false)->delete();

        CompanyFeature::current()->update(['gst' => false, 'vat' => false]);
        $gid = fn (string $n) => AccountGroup::where('name', $n)->value('id');

        // Masters: an item, two godowns, a supplier + purchase ledger to seed stock.
        $unit = Unit::create(['name' => 'Numbers', 'symbol' => 'Nos', 'decimal_places' => 0]);
        $grp = StockGroup::create(['name' => 'Finished Goods']);
        $godA = Godown::where('name', 'Main Location')->first() ?? Godown::create(['name' => 'Main Location', 'is_reserved' => true]);
        $godB = Godown::create(['name' => 'Warehouse B']);
        $item = StockItem::create([
            'name' => 'Widget', 'stock_group_id' => $grp->id, 'unit_id' => $unit->id,
            'opening_qty' => 0, 'opening_rate' => 0, 'opening_value' => 0,
            'costing_method' => 'weighted_average',
        ]);
        $supplier = Ledger::create(['name' => 'Metro Supply', 'group_id' => $gid('Sundry Creditors'), 'country' => 'India']);
        $purchLed = Ledger::create(['name' => 'Purchase - Goods', 'group_id' => $gid('Purchase Accounts'), 'country' => 'India']);

        $screen = new VoucherScreen;

        // Seed stock at godown A: buy 50 @ 40 (an accounting purchase item-invoice,
        // no tax) so the item averages 40 and A holds 50.
        $screen->post([
            'type' => 'purchase', 'date' => '2026-07-01', 'party_ledger_id' => $supplier->id,
            'lines' => [
                ['ledger_id' => $supplier->id, 'dr_cr' => 'Cr', 'amount' => 2000],
                ['ledger_id' => $purchLed->id, 'dr_cr' => 'Dr', 'amount' => 2000],
            ],
            'items' => [['stock_item_id' => $item->id, 'godown_id' => $godA->id, 'qty' => 50, 'rate' => 40]],
        ]);

        $itemTotalBefore = $stock->closingBalance($item->id, Carbon::parse('2026-07-10'));
        $avgBefore = $stock->weightedAverageRate($item->id, Carbon::parse('2026-07-10'));

        // --- TRANSFER 20 from A to B (2026-07-02) --------------------------------
        $tv = $screen->post([
            'type' => 'stock_journal', 'date' => '2026-07-02', 'narration' => 'A→B',
            'lines' => [],
            'movement' => ['mode' => 'transfer', 'stock_item_id' => $item->id, 'qty' => 20, 'from_godown_id' => $godA->id, 'to_godown_id' => $godB->id],
        ]);
        $tRows = StockEntry::where('voucher_id', $tv['voucher']['id'])->orderBy('line_no')->get();
        $qtyA = $stock->godownQuantity($item->id, $godA->id, Carbon::parse('2026-07-10'));
        $qtyB = $stock->godownQuantity($item->id, $godB->id, Carbon::parse('2026-07-10'));
        $itemTotalAfterT = $stock->closingBalance($item->id, Carbon::parse('2026-07-10'));
        $avgAfterT = $stock->weightedAverageRate($item->id, Carbon::parse('2026-07-10'));
        $transferLedgerRows = VoucherEntry::where('voucher_id', $tv['voucher']['id'])->count();

        // --- CONSUMPTION 10 from A (2026-07-03) ----------------------------------
        $cv = $screen->post([
            'type' => 'stock_journal', 'date' => '2026-07-03', 'narration' => 'wastage',
            'lines' => [],
            'movement' => ['mode' => 'consumption', 'stock_item_id' => $item->id, 'qty' => 10, 'godown_id' => $godA->id],
        ]);
        $cRow = StockEntry::where('voucher_id', $cv['voucher']['id'])->first();
        $consumeLedgerRows = VoucherEntry::where('voucher_id', $cv['voucher']['id'])->count();
        $itemTotalAfterC = $stock->closingBalance($item->id, Carbon::parse('2026-07-10'));

        // After transfer + consumption: A = 50-20-10 = 20, B = 20, total = 40 @ 40.
        // --- PHYSICAL STOCK at B: book 20, count 15 → shortage 5 (2026-07-04) -----
        $psShort = $screen->post([
            'type' => 'physical_stock', 'date' => '2026-07-04', 'narration' => 'stock take B',
            'lines' => [],
            'movement' => ['stock_item_id' => $item->id, 'godown_id' => $godB->id, 'counted_qty' => 15],
        ]);
        $psShortRow = StockEntry::where('voucher_id', $psShort['voucher']['id'])->first();
        $qtyBAfterShort = $stock->godownQuantity($item->id, $godB->id, Carbon::parse('2026-07-10'));

        // --- PHYSICAL STOCK at A: book 20, count 23 → excess 3 (2026-07-05) ------
        $avgBeforeExcess = $stock->weightedAverageRate($item->id, Carbon::parse('2026-07-10'));
        $psExcess = $screen->post([
            'type' => 'physical_stock', 'date' => '2026-07-05', 'narration' => 'stock take A',
            'lines' => [],
            'movement' => ['stock_item_id' => $item->id, 'godown_id' => $godA->id, 'counted_qty' => 23],
        ]);
        $psExcessRow = StockEntry::where('voucher_id', $psExcess['voucher']['id'])->first();
        $qtyAAfterExcess = $stock->godownQuantity($item->id, $godA->id, Carbon::parse('2026-07-10'));
        $avgAfterExcess = $stock->weightedAverageRate($item->id, Carbon::parse('2026-07-10'));

        // --- PHYSICAL STOCK no variance at A: book 23, count 23 → no row ---------
        $psNil = $screen->post([
            'type' => 'physical_stock', 'date' => '2026-07-06', 'narration' => 'recount A',
            'lines' => [],
            'movement' => ['stock_item_id' => $item->id, 'godown_id' => $godA->id, 'counted_qty' => 23],
        ]);
        $psNilRows = StockEntry::where('voucher_id', $psNil['voucher']['id'])->count();
        $psNilLedgerRows = VoucherEntry::where('voucher_id', $psNil['voucher']['id'])->count();

        // --- Regression (review #1): a tampered stock payload carrying ledger lines
        //     must write NONE — the ledger side of a stock voucher is forced empty. --
        $tamper = $screen->post([
            'type' => 'stock_journal', 'date' => '2026-07-07',
            'lines' => [['ledger_id' => $purchLed->id, 'dr_cr' => 'Dr', 'amount' => 999]], // injected → must be dropped
            'movement' => ['mode' => 'consumption', 'stock_item_id' => $item->id, 'qty' => 1, 'godown_id' => $godA->id],
        ]);
        $tamperLedgerRows = VoucherEntry::where('voucher_id', $tamper['voucher']['id'])->count();

        // --- Regression (review #2): a transfer stays value-neutral even after the
        //     source purchase's rate is altered — the item total & average follow the
        //     purchase, and the transfer neither creates nor destroys value. ---------
        $z = StockItem::create(['name' => 'Zeta', 'stock_group_id' => $grp->id, 'unit_id' => $unit->id,
            'opening_qty' => 0, 'opening_rate' => 0, 'opening_value' => 0, 'costing_method' => 'weighted_average']);
        $zbuy = $screen->post([
            'type' => 'purchase', 'date' => '2026-07-01', 'party_ledger_id' => $supplier->id,
            'lines' => [['ledger_id' => $supplier->id, 'dr_cr' => 'Cr', 'amount' => 100], ['ledger_id' => $purchLed->id, 'dr_cr' => 'Dr', 'amount' => 100]],
            'items' => [['stock_item_id' => $z->id, 'godown_id' => $godA->id, 'qty' => 10, 'rate' => 10]],
        ]);
        $screen->post([
            'type' => 'stock_journal', 'date' => '2026-07-02', 'lines' => [],
            'movement' => ['mode' => 'transfer', 'stock_item_id' => $z->id, 'qty' => 5, 'from_godown_id' => $godA->id, 'to_godown_id' => $godB->id],
        ]);
        $screen->post([ // alter 10@10 → 10@20
            'type' => 'purchase', 'date' => '2026-07-01', 'party_ledger_id' => $supplier->id, 'voucher_id' => $zbuy['voucher']['id'],
            'lines' => [['ledger_id' => $supplier->id, 'dr_cr' => 'Cr', 'amount' => 200], ['ledger_id' => $purchLed->id, 'dr_cr' => 'Dr', 'amount' => 200]],
            'items' => [['stock_item_id' => $z->id, 'godown_id' => $godA->id, 'qty' => 10, 'rate' => 20]],
        ]);
        $zClose = $stock->closingBalance($z->id, Carbon::parse('2026-07-10'));
        $zAvg = $stock->weightedAverageRate($z->id, Carbon::parse('2026-07-10'));
        $zGodA = $stock->godownQuantity($z->id, $godA->id, Carbon::parse('2026-07-10'));
        $zGodB = $stock->godownQuantity($z->id, $godB->id, Carbon::parse('2026-07-10'));

        // Every stock/physical voucher must have written ZERO ledger rows (covers the
        // transfer, consumption, both variance physicals, the no-variance one, AND the
        // tamper attempt). This is the direct, non-blind-spot check for review #1/#3.
        $stockVoucherLedgerRows = VoucherEntry::whereIn('voucher_id', Voucher::whereIn('type', Voucher::STOCK_TYPES)->pluck('id'))->count();

        [$from, $to] = $bs->withinFy(null, null);
        $tb = $bs->trialBalance($from, $to);

        // ---- report ----
        $this->line('');
        $this->info('GST/VAT off · weighted-average inventory · Stock Journal + Physical Stock');
        $this->line('Seed: buy 50 @ 40 into A ⇒ item 50 @ avg 40 = '.($itemTotalBefore['value']));
        $this->line('--- Transfer 20 A→B ---');
        $this->line('   rows: '.$tRows->map(fn ($r) => $r->direction.' '.$r->godown->name.' '.$r->quantity.'@'.$r->rate)->implode('  ·  '));
        $this->line('   godown A='.$qtyA.'  B='.$qtyB.'   item total qty='.$itemTotalAfterT['qty'].' value='.$itemTotalAfterT['value'].' avg='.$avgAfterT.'  ledger rows='.$transferLedgerRows);
        $this->line('--- Consumption 10 from A ---');
        $this->line('   OUT '.$cRow->quantity.'@'.$cRow->rate.' = '.$cRow->value.'   item total value='.$itemTotalAfterC['value'].'  ledger rows='.$consumeLedgerRows);
        $this->line('--- Physical B: book 20 count 15 → shortage ---');
        $this->line('   row: '.$psShortRow->direction.' '.$psShortRow->quantity.'@'.$psShortRow->rate.'   B after='.$qtyBAfterShort);
        $this->line('--- Physical A: book 20 count 23 → excess ---');
        $this->line('   row: '.$psExcessRow->direction.' '.$psExcessRow->quantity.'@'.$psExcessRow->rate.'   A after='.$qtyAAfterExcess.'  avg '.$avgBeforeExcess.'→'.$avgAfterExcess);
        $this->line('--- Trial Balance ---');
        $this->line('   Dr '.BalanceService::money($tb['total_dr']).' = Cr '.BalanceService::money($tb['total_cr']).'  balanced='.($tb['balanced'] ? 'YES' : 'NO'));

        $ok = true;
        $expect = function (string $label, $actual, $expected) use (&$ok) {
            $pass = $actual === $expected;
            $this->line(($pass ? '  [PASS] ' : '  [FAIL] ').$label.' = '.var_export($actual, true).($pass ? '' : ' (expected '.var_export($expected, true).')'));
            $ok = $ok && $pass;
        };
        $close = fn ($a, $b) => is_numeric($a) && abs((float) $a - (float) $b) < 0.005;
        $expectClose = function (string $label, $actual, $expected) use (&$ok, $close) {
            $pass = $close($actual, $expected);
            $this->line(($pass ? '  [PASS] ' : '  [FAIL] ').$label.' = '.var_export($actual, true).($pass ? '' : ' (expected ~'.var_export($expected, true).')'));
            $ok = $ok && $pass;
        };

        $this->line('');
        $this->line('--- Assertions ---');
        // Transfer: 2 rows at the SAME cost; godown split; item total + avg unchanged.
        $expect('Transfer posts 2 stock rows', $tRows->count(), 2);
        $expect('Transfer OUT then IN', $tRows->pluck('direction')->all(), ['out', 'in']);
        $expectClose('Transfer both rows @ avg 40', (float) $tRows[0]->rate, 40);
        $expectClose('Transfer IN row @ avg 40 (same cost)', (float) $tRows[1]->rate, 40);
        $expectClose('Godown A holds 30 after transfer (50−20)', $qtyA, 30);
        $expectClose('Godown B holds 20 after transfer', $qtyB, 20);
        $expectClose('Item TOTAL qty unchanged (50)', $itemTotalAfterT['qty'], (float) $itemTotalBefore['qty']);
        $expectClose('Item TOTAL value unchanged (2000)', $itemTotalAfterT['value'], (float) $itemTotalBefore['value']);
        $expectClose('Weighted-average unchanged by transfer (40)', $avgAfterT, (float) $avgBefore);
        $expect('Transfer posts ZERO voucher_entries', $transferLedgerRows, 0);
        // Consumption: OUT at avg 40, value drop 400, zero ledger rows.
        $expect('Consumption direction OUT', $cRow->direction, 'out');
        $expectClose('Consumption cost rate 40 (locked avg)', (float) $cRow->rate, 40);
        $expectClose('Consumption value 400 (10×40)', (float) $cRow->value, 400);
        $expectClose('Item total value dropped to 1600', $itemTotalAfterC['value'], 1600);
        $expect('Consumption posts ZERO voucher_entries', $consumeLedgerRows, 0);
        // Physical shortage: 5-unit OUT at avg 40; B falls to 15.
        $expect('Physical shortage direction OUT', $psShortRow->direction, 'out');
        $expectClose('Physical shortage qty 5', (float) $psShortRow->quantity, 5);
        $expectClose('Physical shortage @ avg 40', (float) $psShortRow->rate, 40);
        $expectClose('Godown B after shortage = 15', $qtyBAfterShort, 15);
        // Physical excess: 3-unit IN at avg 40; A rises to 23; AVERAGE UNCHANGED.
        $expect('Physical excess direction IN', $psExcessRow->direction, 'in');
        $expectClose('Physical excess qty 3', (float) $psExcessRow->quantity, 3);
        $expectClose('Physical excess @ avg 40', (float) $psExcessRow->rate, 40);
        $expectClose('Godown A after excess = 23', $qtyAAfterExcess, 23);
        $expectClose('Average UNCHANGED by excess (40)', $avgAfterExcess, (float) $avgBeforeExcess);
        // Physical no-variance: no row, no ledger row (but the voucher exists).
        $expect('Physical no-variance posts NO stock row', $psNilRows, 0);
        $expect('Physical no-variance posts NO voucher_entries', $psNilLedgerRows, 0);
        // Zero-ledger vouchers all posted (the balance gate accepts 0=0): transfer,
        // consumption, 2 physicals with variance, 1 no-variance, 1 tamper, 1 Zeta
        // transfer = 7.
        $expect('All stock vouchers posted (7 created)', Voucher::whereIn('type', Voucher::STOCK_TYPES)->count(), 7);
        // Review #1: EVERY stock voucher wrote ZERO ledger rows — incl. the two
        // variance physicals and the tamper attempt (its injected Dr 999 was dropped).
        $expect('Physical shortage posts ZERO voucher_entries', VoucherEntry::where('voucher_id', $psShort['voucher']['id'])->count(), 0);
        $expect('Physical excess posts ZERO voucher_entries', VoucherEntry::where('voucher_id', $psExcess['voucher']['id'])->count(), 0);
        $expect('Tampered ledger line on a stock voucher is DROPPED', $tamperLedgerRows, 0);
        $expect('No stock voucher wrote ANY voucher_entries', $stockVoucherLedgerRows, 0);
        // Review #2: transfer value-neutral even after the source rate is altered
        // (Zeta bought 10@10, transfer 5, altered to 10@20 → 200 @ avg 20, split 5/5).
        $expectClose('Transfer value-neutral after alter: value 200', $zClose['value'], 200);
        $expectClose('Transfer value-neutral after alter: avg 20', $zAvg, 20);
        $expectClose('Transfer value-neutral after alter: qty 10', $zClose['qty'], 10);
        $expectClose('Zeta godown A = 5', $zGodA, 5);
        $expectClose('Zeta godown B = 5', $zGodB, 5);
        // Trial Balance untouched by stock vouchers: only the 2 real purchases post
        // ledger rows (Widget 2000 + Zeta 200 = 2200), and it balances.
        $expect('Trial Balance balanced', $tb['balanced'], true);
        $expectClose('Trial Balance total = 2,200 (the 2 purchases only)', $tb['total_dr'] / 100, 2200);
        $expect('Exactly 4 voucher_entries exist (2 purchases × 2 legs)', VoucherEntry::count(), 4);

        if ($keep) {
            DB::commit();
            $this->info($ok ? 'ALL ASSERTIONS PASSED — scenario kept in DB.' : 'ASSERTIONS FAILED — scenario kept for inspection.');
        } else {
            DB::rollBack();
            $this->info($ok ? 'ALL ASSERTIONS PASSED — rolled back.' : 'ASSERTIONS FAILED — rolled back.');
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
