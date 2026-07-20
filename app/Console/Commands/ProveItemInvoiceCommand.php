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
use App\Services\GstService;
use App\Services\StockService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 6B numeric proof. Posts item invoices through the SAME endpoint the UI
 * uses — VoucherScreen::post() — and asserts, to the paise/unit:
 *
 *   • Weighted-average COST: buy 100@50 then 100@70 ⇒ average 60; sell 120@90 ⇒
 *     the OUT row locks cost rate 60 / value 7,200 while the SELLING side records
 *     sale_rate 90 / sale_value 10,800. The two numbers are never confused.
 *   • Stock ledger rides the SAME transaction; the money balance is untouched
 *     (Trial Balance still balances).
 *   • Item-sourced tax overrides the ledger rate (item 18% beats a 5% Sales ledger),
 *     and the server rejects a tampered item tax.
 *   • Negative stock is allowed (documented), costing at the last known average.
 *   • Altering a sale re-locks the cost from the current ledger (excluding itself).
 *   • A plain accounting invoice (no items) is unaffected — no stock rows written.
 *
 * Rolls everything back unless --keep is given.
 */
class ProveItemInvoiceCommand extends Command
{
    use ResolvesActiveCompany;
    protected $signature = 'zerobook:prove-item-invoice {--keep : keep the seeded scenario in the DB} {--company= : run in this company (slug or id) instead of a fresh throwaway one}';

    protected $description = 'Post item invoices via the shared path and prove weighted-average COGS, stock posting, and cost≠selling separation';

    public function handle(GstService $gst, BalanceService $bs, StockService $stock): int
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

        // Clean prior test data (keep reserved masters incl. the tax ledgers + the
        // seeded Main Location godown).
        StockEntry::query()->delete();
        VoucherEntry::query()->delete();
        Voucher::query()->delete();
        Ledger::where('is_reserved', false)->delete();
        StockItem::query()->delete();
        StockGroup::query()->delete();
        Unit::query()->delete();

        // GST on, intra-state (CGST+SGST) so a rate shows on two legs.
        CompanyFeature::current()->update(['gst' => true, 'vat' => false]);
        activeCompany()->update(['state' => 'Maharashtra', 'gstin' => '27AAAAA0000A1Z5']);
        $gst = app(GstService::class);

        $gid = fn (string $n) => AccountGroup::where('name', $n)->value('id');

        // Inventory masters. Item rate 18% deliberately differs from the Sales/
        // Purchase ledger rate 5% — so an item-sourced tax proves the override.
        $unit = Unit::create(['name' => 'Numbers', 'symbol' => 'Nos', 'decimal_places' => 0]);
        $grp = StockGroup::create(['name' => 'Finished Goods']);
        $item = StockItem::create([
            'name' => 'Widget', 'stock_group_id' => $grp->id, 'unit_id' => $unit->id,
            'opening_qty' => 0, 'opening_rate' => 0, 'opening_value' => 0,
            'gst_rate' => 18, 'hsn_sac' => '8471', 'costing_method' => 'weighted_average',
        ]);
        $godownId = Godown::where('name', 'Main Location')->value('id')
            ?? Godown::create(['name' => 'Main Location', 'is_reserved' => true])->id;

        $customer = Ledger::create(['name' => 'Acme (MH)', 'group_id' => $gid('Sundry Debtors'), 'state' => 'Maharashtra', 'gstin' => '27AAACA1111A1Z1', 'country' => 'India']);
        $supplier = Ledger::create(['name' => 'Metro (MH)', 'group_id' => $gid('Sundry Creditors'), 'state' => 'Maharashtra', 'country' => 'India']);
        $salesLed = Ledger::create(['name' => 'Sales - Goods', 'group_id' => $gid('Sales Accounts'), 'gst_rate' => 5, 'country' => 'India']);
        $purchLed = Ledger::create(['name' => 'Purchase - Goods', 'group_id' => $gid('Purchase Accounts'), 'gst_rate' => 5, 'country' => 'India']);

        $screen = new VoucherScreen;

        // Build an item-invoice payload exactly as the client will: one aggregate
        // revenue ledger leg (Σ qty×rate), item-sourced tax legs, a party leg =
        // taxable + tax, plus the `items` array. The COST is NOT in the payload.
        $itemInvoice = function (string $type, Ledger $party, Ledger $rev, float $qty, float $rate, string $date, ?int $voucherId = null) use ($gst, $item, $godownId) {
            $base = round($qty * $rate, 2);
            $taxable = [['amount' => $base, 'rate' => (float) $item->gst_rate]]; // item rate
            $comp = $gst->computeInvoiceTax($type, $party->state, $taxable);
            $pSide = $type === 'sales' ? 'Dr' : 'Cr';
            $lSide = $type === 'sales' ? 'Cr' : 'Dr';
            $lines = [['ledger_id' => $rev->id, 'dr_cr' => $lSide, 'amount' => $base]];
            $totalP = (int) round($base * 100);
            foreach ($comp['lines'] as $tl) {
                $lines[] = ['ledger_id' => $tl['ledger_id'], 'dr_cr' => $tl['dr_cr'], 'amount' => $tl['amount']];
            }
            $totalP += $comp['tax_paise'];
            array_unshift($lines, ['ledger_id' => $party->id, 'dr_cr' => $pSide, 'amount' => $totalP / 100]);

            $payload = [
                'type' => $type, 'date' => $date, 'party_ledger_id' => $party->id,
                'reference_no' => 'ITM-'.$date,
                'lines' => $lines,
                'items' => [['stock_item_id' => $item->id, 'godown_id' => $godownId, 'qty' => $qty, 'rate' => $rate]],
            ];
            if ($voucherId) {
                $payload['voucher_id'] = $voucherId;
            }

            return $payload;
        };

        // --- Post the core scenario -----------------------------------------
        $p1 = $screen->post($itemInvoice('purchase', $supplier, $purchLed, 100, 50, '2026-07-01')); // IN 100@50
        $p2 = $screen->post($itemInvoice('purchase', $supplier, $purchLed, 100, 70, '2026-07-02')); // IN 100@70 ⇒ avg 60
        $s = $screen->post($itemInvoice('sales', $customer, $salesLed, 120, 90, '2026-07-03'));    // OUT 120, cost 60, sell 90

        $stkRow = function (int $vid) {
            $s = StockEntry::where('voucher_id', $vid)->orderBy('line_no')->first();

            return $s ? [
                'dir' => $s->direction, 'qty' => (float) $s->quantity, 'rate' => (float) $s->rate,
                'value' => (float) $s->value,
                'sale_rate' => $s->sale_rate === null ? null : (float) $s->sale_rate,
                'sale_value' => $s->sale_value === null ? null : (float) $s->sale_value,
            ] : null;
        };

        $r1 = $stkRow($p1['voucher']['id']);
        $r2 = $stkRow($p2['voucher']['id']);
        $r3 = $stkRow($s['voucher']['id']);
        $closeSale = $stock->closingBalance($item->id, Carbon::parse('2026-07-03'));
        $avgAtSale = $stock->weightedAverageRate($item->id, Carbon::parse('2026-07-03'));

        // Item-sourced tax authority: the posted sale must carry 18% tax (item),
        // NOT 5% (the Sales ledger) — 10,800 × 18% = 1,944 (CGST 972 + SGST 972).
        $saleLegs = [];
        foreach (Voucher::find($s['voucher']['id'])->entries()->with('ledger')->get() as $e) {
            $saleLegs[$e->ledger->name] = ['side' => $e->dr_cr, 'paise' => (int) round($e->amount * 100)];
        }

        // Server rejects a tampered item tax — and it must be the GST authority that
        // rejects it, NOT the balance gate. Base 1,000 → item 18% = CGST 90 + SGST 90,
        // party 1,180. Understate CGST to 1.00 AND drop the party leg to 1,091 so
        // Dr==Cr stays balanced: now ONLY GstService::verifyInvoicePayload can catch it.
        $tamperRejected = false;
        $tamperErrorKeys = [];
        try {
            $bad = $itemInvoice('sales', $customer, $salesLed, 10, 100, '2026-07-04');
            foreach ($bad['lines'] as &$ln) {
                if ($ln['ledger_id'] === $gst->taxLedgerId('output', 'central')) {
                    $ln['amount'] = 1.00;      // understate CGST (should be 90.00)
                } elseif ($ln['ledger_id'] === $customer->id) {
                    $ln['amount'] = 1091.00;   // 1000 + 1 + 90 → keeps the voucher balanced
                }
            }
            unset($ln);
            $screen->post($bad);
        } catch (ValidationException $e) {
            $tamperRejected = true;
            $tamperErrorKeys = array_keys($e->errors());
        }

        // --- Negative stock is allowed (documented), costing at last average ---
        $n = $screen->post($itemInvoice('sales', $customer, $salesLed, 200, 95, '2026-07-05')); // sell 200 with 80 on hand
        $rNeg = $stkRow($n['voucher']['id']);
        $closeNeg = $stock->closingBalance($item->id, Carbon::parse('2026-07-05'));

        // --- Divide-by-zero / empty-stock fallback: a fresh item with ZERO opening
        //     and no purchases. The first sale costs at the opening_rate fallback
        //     (weightedAverageRate's outer guard); a second sale — folding a prior
        //     OUT that already drove the running qty <= 0 — exercises fold()'s inner
        //     OUT-branch fallback. Neither may divide by zero. ---------------------
        $zero = StockItem::create([
            'name' => 'Zero-Stock Widget', 'stock_group_id' => $grp->id, 'unit_id' => $unit->id,
            'opening_qty' => 0, 'opening_rate' => 0, 'opening_value' => 0,
            'gst_rate' => 18, 'costing_method' => 'weighted_average',
        ]);
        $zItemInvoice = function (float $qty, float $rate, string $date) use ($gst, $customer, $salesLed, $zero, $godownId) {
            $base = round($qty * $rate, 2);
            $comp = $gst->computeInvoiceTax('sales', $customer->state, [['amount' => $base, 'rate' => (float) $zero->gst_rate]]);
            $lines = [['ledger_id' => $salesLed->id, 'dr_cr' => 'Cr', 'amount' => $base]];
            $totalP = (int) round($base * 100);
            foreach ($comp['lines'] as $tl) {
                $lines[] = ['ledger_id' => $tl['ledger_id'], 'dr_cr' => $tl['dr_cr'], 'amount' => $tl['amount']];
            }
            $totalP += $comp['tax_paise'];
            array_unshift($lines, ['ledger_id' => $customer->id, 'dr_cr' => 'Dr', 'amount' => $totalP / 100]);

            return ['type' => 'sales', 'date' => $date, 'party_ledger_id' => $customer->id, 'reference_no' => 'Z-'.$date,
                'lines' => $lines, 'items' => [['stock_item_id' => $zero->id, 'godown_id' => $godownId, 'qty' => $qty, 'rate' => $rate]]];
        };
        $z1 = $screen->post($zItemInvoice(10, 30, '2026-07-01')); // sell 10 with 0 on hand → cost falls back to 0
        $z2 = $screen->post($zItemInvoice(5, 40, '2026-07-02'));  // folds Z1 (qty already <= 0) → inner fallback, cost 0
        $rZ1 = $stkRow($z1['voucher']['id']);
        $rZ2 = $stkRow($z2['voucher']['id']);
        $closeZero = $stock->closingBalance($zero->id, Carbon::parse('2026-07-02'));

        // --- Intervening back-dated purchase P3 (100 @ 90 on 07-03) BEFORE the alter,
        //     so the re-locked average is a DISCRIMINATING 70 (P1+P2+P3 = 300 @ 21,000),
        //     not the coincidental 60 a stale/non-recomputed cost would show. ---------
        $screen->post($itemInvoice('purchase', $supplier, $purchLed, 100, 90, '2026-07-03')); // IN 100@90

        // --- Alter the sale (120→100): cost must be RE-LOCKED from current state
        //     (P1 100@50 + P2 100@70 + P3 100@90 = 300 @ avg 70), excluding S itself. --
        $screen->post($itemInvoice('sales', $customer, $salesLed, 100, 90, '2026-07-03', $s['voucher']['id']));
        $rAlt = $stkRow($s['voucher']['id']);

        // --- A plain accounting invoice (no items) is unaffected ---------------
        $acct = $screen->post([
            'type' => 'sales', 'date' => '2026-07-06', 'party_ledger_id' => $customer->id,
            'lines' => [
                ['ledger_id' => $customer->id, 'dr_cr' => 'Dr', 'amount' => 1050],
                ['ledger_id' => $salesLed->id, 'dr_cr' => 'Cr', 'amount' => 1000], // 5% ledger rate applies here
                ['ledger_id' => $gst->taxLedgerId('output', 'central'), 'dr_cr' => 'Cr', 'amount' => 25],
                ['ledger_id' => $gst->taxLedgerId('output', 'state'), 'dr_cr' => 'Cr', 'amount' => 25],
            ],
        ]);
        $acctStockRows = StockEntry::where('voucher_id', $acct['voucher']['id'])->count();

        [$from, $to] = $bs->withinFy(null, null);
        $tb = $bs->trialBalance($from, $to);

        // ---- report ----
        $this->line('');
        $this->info('Company state Maharashtra · GST on · weighted-average inventory');
        $this->line('--- Buy 100@50, buy 100@70  ⇒  running 200 @ avg 60 = 12,000 ---');
        $this->line('   P1 IN : qty '.$r1['qty'].' rate '.$r1['rate'].' value '.$r1['value']);
        $this->line('   P2 IN : qty '.$r2['qty'].' rate '.$r2['rate'].' value '.$r2['value']);
        $this->line('--- Sell 120 @ selling 90 ---');
        $this->line('   OUT cost : rate '.$r3['rate'].' value '.$r3['value'].'   (weighted-average)');
        $this->line('   OUT sell : sale_rate '.$r3['sale_rate'].' sale_value '.$r3['sale_value'].'   (revenue)');
        $this->line('   closing  : qty '.$closeSale['qty'].' value '.$closeSale['value'].'   avg-at-sale '.$avgAtSale);
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
        // Purchases: IN rows carry the entered rate as COST; no selling side.
        $expect('P1 direction IN', $r1['dir'], 'in');
        $expectClose('P1 cost rate 50', $r1['rate'], 50);
        $expectClose('P1 cost value 5,000', $r1['value'], 5000);
        $expect('P1 no sale_rate', $r1['sale_rate'], null);
        $expectClose('P2 cost rate 70', $r2['rate'], 70);
        $expectClose('P2 cost value 7,000', $r2['value'], 7000);
        // The sale: cost (weighted-average 60) and selling (90) never confused.
        $expect('Sale direction OUT', $r3['dir'], 'out');
        $expectClose('Sale COST rate 60 (weighted-avg)', $r3['rate'], 60);
        $expectClose('Sale COST value 7,200', $r3['value'], 7200);
        $expectClose('Sale SELL rate 90', $r3['sale_rate'], 90);
        $expectClose('Sale SELL value 10,800', $r3['sale_value'], 10800);
        $expectClose('weightedAverageRate at sale = 60', $avgAtSale, 60);
        $expectClose('Closing qty 80', $closeSale['qty'], 80);
        $expectClose('Closing value 4,800', $closeSale['value'], 4800);
        // Item-sourced tax overrides the 5% ledger rate → 18%.
        $expect('Sale taxed at item 18%: Cr Output CGST 972', $saleLegs['Output CGST'] ?? null, ['side' => 'Cr', 'paise' => 97200]);
        $expect('Sale taxed at item 18%: Cr Output SGST 972', $saleLegs['Output SGST'] ?? null, ['side' => 'Cr', 'paise' => 97200]);
        $expect('Sale Cr revenue 10,800 (Σ item)', $saleLegs['Sales - Goods'] ?? null, ['side' => 'Cr', 'paise' => 1080000]);
        $expect('Sale Dr party 12,744 (incl tax)', $saleLegs['Acme (MH)'] ?? null, ['side' => 'Dr', 'paise' => 1274400]);
        $expect('Tampered item tax rejected', $tamperRejected, true);
        // ...and rejected specifically by the GST authority, NOT the balance gate
        // (the tamper is balance-preserving), proving the item-tax server authority.
        $expect('Tamper rejected by GST authority (not balance)', in_array('gst', $tamperErrorKeys, true), true);
        $expect('Tamper did NOT trip the balance gate', in_array('balance', $tamperErrorKeys, true), false);
        // Negative stock allowed; cost at last average 60 (P3 not yet posted here).
        $expect('Neg sale direction OUT', $rNeg['dir'], 'out');
        $expectClose('Neg sale cost rate 60 (last avg)', $rNeg['rate'], 60);
        $expectClose('Neg sale cost value 12,000', $rNeg['value'], 12000);
        $expectClose('Neg closing qty -120 (allowed)', $closeNeg['qty'], -120);
        // Divide-by-zero / empty-stock fallback: cost falls back to 0, never crashes.
        $expect('Zero-stock sale 1 direction OUT', $rZ1['dir'], 'out');
        $expectClose('Zero-stock sale 1 cost 0 (opening_rate fallback)', $rZ1['rate'], 0);
        $expectClose('Zero-stock sale 2 cost 0 (inner OUT fallback, qty<=0)', $rZ2['rate'], 0);
        $expectClose('Zero-stock sale 1 SELL rate 30 (selling still recorded)', $rZ1['sale_rate'], 30);
        $expectClose('Zero-stock closing qty -15 (allowed)', $closeZero['qty'], -15);
        // Alter re-locks cost from CURRENT state — a discriminating 70 (P1+P2+P3),
        // not the coincidental 60. A stale/non-recomputed cost would fail here.
        $expectClose('Altered sale qty 100', $rAlt['qty'], 100);
        $expectClose('Altered sale cost rate 70 (re-locked: P1+P2+P3=300@21000)', $rAlt['rate'], 70);
        $expectClose('Altered sale cost value 7,000', $rAlt['value'], 7000);
        $expectClose('Altered sale SELL value 9,000 (selling unchanged)', $rAlt['sale_value'], 9000);
        // Accounting invoice unregressed.
        $expect('Accounting invoice writes NO stock rows', $acctStockRows, 0);
        // Integrity — the money side is untouched by stock.
        $expect('Trial Balance balanced', $tb['balanced'], true);

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
