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
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Phase 6D numeric proof — Integrate Accounts with Inventory.
 *
 * Posts through the REAL paths, then reads BalanceService::profitAndLoss() and
 * balanceSheet() and asserts the whole system TIES OUT with inventory:
 *   • Gross/Net Profit = the true margin (revenue − weighted-average COGS), via the
 *     additive Opening/Closing Stock correction — NOT the ledger-only figure.
 *   • The Balance Sheet balances with a computed Stock-in-Hand asset.
 *   • F2 period changes Closing Stock (earlier period = more stock on hand).
 *   • REGRESSION: a company with no stock items sees the correction term EXACTLY
 *     zero — P&L/BS identical to the pre-6D ledger-only computation.
 *
 * Rolls everything back unless --keep.
 */
class ProveInventoryIntegrationCommand extends Command
{
    use ResolvesActiveCompany;
    protected $signature = 'zerobook:prove-inventory-integration {--keep : keep the seeded scenario in the DB} {--company= : run in this company (slug or id) instead of a fresh throwaway one}';

    protected $description = 'Prove Opening/Closing Stock + Gross Profit in the P&L and Stock-in-Hand in the Balance Sheet tie out';

    public function handle(BalanceService $bs): int
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

        $wipe = function () {
            StockEntry::query()->delete();
            VoucherEntry::query()->delete();
            Voucher::query()->delete();
            Ledger::where('is_reserved', false)->delete();
            StockItem::query()->delete();
            StockGroup::query()->delete();
            Unit::query()->delete();
        };
        $wipe();
        CompanyFeature::current()->update(['gst' => false, 'vat' => false]);
        $gid = fn (string $n) => AccountGroup::where('name', $n)->value('id');

        // ============ Scenario 1 — inventory company ============
        $unit = Unit::create(['name' => 'Numbers', 'symbol' => 'Nos', 'decimal_places' => 0]);
        $grp = StockGroup::create(['name' => 'Finished Goods']);
        $godown = Godown::where('name', 'Main Location')->value('id') ?? Godown::create(['name' => 'Main Location', 'is_reserved' => true])->id;
        $item = StockItem::create(['name' => 'Widget', 'stock_group_id' => $grp->id, 'unit_id' => $unit->id, 'opening_qty' => 0, 'opening_rate' => 0, 'opening_value' => 0, 'costing_method' => 'weighted_average']);
        $supplier = Ledger::create(['name' => 'Metro Supply', 'group_id' => $gid('Sundry Creditors'), 'country' => 'India']);
        $customer = Ledger::create(['name' => 'Acme Retail', 'group_id' => $gid('Sundry Debtors'), 'country' => 'India']);
        $purchLed = Ledger::create(['name' => 'Purchase - Goods', 'group_id' => $gid('Purchase Accounts'), 'country' => 'India']);
        $salesLed = Ledger::create(['name' => 'Sales - Goods', 'group_id' => $gid('Sales Accounts'), 'country' => 'India']);

        $screen = new VoucherScreen;
        // Purchase 100 @ 10  (Dr Purchase 1,000 / Cr Party 1,000 + stock IN 100@10)
        $screen->post([
            'type' => 'purchase', 'date' => '2026-07-02', 'party_ledger_id' => $supplier->id,
            'lines' => [['ledger_id' => $supplier->id, 'dr_cr' => 'Cr', 'amount' => 1000], ['ledger_id' => $purchLed->id, 'dr_cr' => 'Dr', 'amount' => 1000]],
            'items' => [['stock_item_id' => $item->id, 'godown_id' => $godown, 'qty' => 100, 'rate' => 10]],
        ]);
        // Sale 60 @ 20  (Dr Party 1,200 / Cr Sales 1,200; locked cost 60×10=600)
        $screen->post([
            'type' => 'sales', 'date' => '2026-07-05', 'party_ledger_id' => $customer->id,
            'lines' => [['ledger_id' => $customer->id, 'dr_cr' => 'Dr', 'amount' => 1200], ['ledger_id' => $salesLed->id, 'dr_cr' => 'Cr', 'amount' => 1200]],
            'items' => [['stock_item_id' => $item->id, 'godown_id' => $godown, 'qty' => 60, 'rate' => 20]],
        ]);

        [$from, $to] = $bs->withinFy(null, null);
        $pl = $bs->profitAndLoss($from, $to);
        $sheet = $bs->balanceSheet($from, $to);
        // Earlier period end (after the purchase, before the sale) — stock still 100.
        $plEarly = $bs->profitAndLoss(Carbon::parse('2026-07-01'), Carbon::parse('2026-07-03'));

        // Verify the Stock-in-Hand value was actually FOLDED INTO the asset tree (not
        // just the scalar total) — walk the returned asset_roots for the group node.
        $findGroup = function (array $roots, string $name) use (&$findGroup) {
            foreach ($roots as $n) {
                if (($n['name'] ?? '') === $name) {
                    return $n['closing'];
                }
                $c = $findGroup($n['children'] ?? [], $name);
                if ($c !== null) {
                    return $c;
                }
            }

            return null;
        };
        $stockNode = $findGroup($sheet['asset_roots'], 'Stock-in-Hand');
        $assetRootsSum = (int) array_sum(array_map(fn ($n) => $n['closing'], $sheet['asset_roots']));

        // ============ Scenario 2 — regression: no stock items ============
        $wipe();
        StockItem::query()->delete();
        $supplier2 = Ledger::create(['name' => 'Metro Supply', 'group_id' => $gid('Sundry Creditors'), 'country' => 'India']);
        $customer2 = Ledger::create(['name' => 'Acme Retail', 'group_id' => $gid('Sundry Debtors'), 'country' => 'India']);
        $purchLed2 = Ledger::create(['name' => 'Purchase - Goods', 'group_id' => $gid('Purchase Accounts'), 'country' => 'India']);
        $salesLed2 = Ledger::create(['name' => 'Sales - Goods', 'group_id' => $gid('Sales Accounts'), 'country' => 'India']);
        // Plain accounting purchase 1,000 and sale 1,200 — no items, no stock.
        $screen->post(['type' => 'purchase', 'date' => '2026-07-02', 'party_ledger_id' => $supplier2->id,
            'lines' => [['ledger_id' => $supplier2->id, 'dr_cr' => 'Cr', 'amount' => 1000], ['ledger_id' => $purchLed2->id, 'dr_cr' => 'Dr', 'amount' => 1000]]]);
        $screen->post(['type' => 'sales', 'date' => '2026-07-05', 'party_ledger_id' => $customer2->id,
            'lines' => [['ledger_id' => $customer2->id, 'dr_cr' => 'Dr', 'amount' => 1200], ['ledger_id' => $salesLed2->id, 'dr_cr' => 'Cr', 'amount' => 1200]]]);
        $pl2 = $bs->profitAndLoss($from, $to);
        $sheet2 = $bs->balanceSheet($from, $to);

        // ---- report ----
        $m = fn ($p) => BalanceService::money($p);
        $this->line('');
        $this->info('Buy 100 @ 10, sell 60 @ 20 (no tax) — the P&L must show the TRUE margin');
        $this->line('--- P&L (with inventory) ---');
        $this->line('   Ledger-only Net (Sales−Purchases) = '.$m($pl['ledger_net']));
        $this->line('   Opening Stock = '.$m($pl['opening_stock']).'   Closing Stock = '.$m($pl['closing_stock']));
        $this->line('   Gross Profit = '.$m($pl['gross_profit']).'   Net Profit (inv-adjusted) = '.$m($pl['net']));
        $this->line('--- Balance Sheet ---');
        $this->line('   Assets '.$m($sheet['asset_total']).' = Liabilities '.$m($sheet['liability_total']).'  balanced='.($sheet['balanced'] ? 'YES' : 'NO'));
        $this->line('   (Stock-in-Hand asset = '.$m($sheet['closing_stock']).', Net Profit carried = '.$m($sheet['net']).')');
        $this->line('--- Regression (no stock items) ---');
        $this->line('   Net = '.$m($pl2['net']).'   ledger_net = '.$m($pl2['ledger_net']).'   closing_stock = '.$m($pl2['closing_stock']));

        $ok = true;
        $expect = function (string $label, $actual, $expected) use (&$ok) {
            $pass = $actual === $expected;
            $this->line(($pass ? '  [PASS] ' : '  [FAIL] ').$label.' = '.var_export($actual, true).($pass ? '' : ' (expected '.var_export($expected, true).')'));
            $ok = $ok && $pass;
        };

        $this->line('');
        $this->line('--- Assertions (paise) ---');
        // Ledger-only net is the naive 200; inventory-adjusted is the true 600.
        $expect('Ledger-only Net Profit = 200', $pl['ledger_net'], 20000);
        $expect('Opening Stock = 0', $pl['opening_stock'], 0);
        $expect('Closing Stock = 400 (40 units × 10)', $pl['closing_stock'], 40000);
        $expect('Gross Profit = 600', $pl['gross_profit'], 60000);
        $expect('Net Profit (inventory-adjusted) = 600', $pl['net'], 60000);
        $expect('total_income = 1,200', $pl['total_income'], 120000);
        $expect('total_expenses = 1,000', $pl['total_expenses'], 100000);
        // Balance Sheet ties out: Assets (Debtor 1,200 + Stock 400) = Liab (Creditor 1,000 + Profit 600).
        $expect('Balance Sheet balanced', $sheet['balanced'], true);
        $expect('Asset total = 1,600', $sheet['asset_total'], 160000);
        $expect('Liability total = 1,600', $sheet['liability_total'], 160000);
        $expect('Stock-in-Hand asset = 400', $sheet['closing_stock'], 40000);
        $expect('BS Net Profit == P&L Net Profit (one shared figure)', $sheet['net'], $pl['net']);
        $expect('No forced difference line needed (opening=0)', $sheet['asset_diff'] + $sheet['liability_diff'], 0);
        // The Stock-in-Hand value was actually injected into the asset TREE (not just
        // the scalar total) and rolls up so the roots sum to the total.
        $expect('Stock-in-Hand group node = 400 (injected + rolled up)', $stockNode, 40000);
        $expect('Asset roots sum to total_assets (injection rolled up)', $assetRootsSum, $sheet['total_assets']);
        // F2 earlier period — nothing sold yet, closing stock is the full 100 units.
        $expect('Earlier period Closing Stock = 1,000 (100 units)', $plEarly['closing_stock'], 100000);
        // Regression — the correction term is EXACTLY zero, net == ledger net.
        $expect('Regression: Opening Stock = 0', $pl2['opening_stock'], 0);
        $expect('Regression: Closing Stock = 0 (exactly, no stock items)', $pl2['closing_stock'], 0);
        $expect('Regression: Net == ledger Net (correction is 0)', $pl2['net'], $pl2['ledger_net']);
        $expect('Regression: Net Profit = 200 (ledger-only, unchanged)', $pl2['net'], 20000);
        $expect('Regression: Balance Sheet still balanced', $sheet2['balanced'], true);
        // Non-tautological: the two SIDES equal each other with no forced diff line.
        $expect('Regression: BS asset_total == liability_total', $sheet2['asset_total'], $sheet2['liability_total']);
        $expect('Regression: no forced difference line on a no-stock BS', $sheet2['asset_diff'] + $sheet2['liability_diff'], 0);
        $expect('Regression: Stock-in-Hand absent (0)', $sheet2['closing_stock'], 0);

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
