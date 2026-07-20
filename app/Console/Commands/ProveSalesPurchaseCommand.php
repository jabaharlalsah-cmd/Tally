<?php

namespace App\Console\Commands;

use App\Console\Concerns\ResolvesActiveCompany;
use App\Livewire\VoucherScreen;
use App\Models\AccountGroup;
use App\Models\Ledger;
use App\Models\Voucher;
use App\Models\VoucherEntry;
use App\Services\BalanceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 5A numeric proof. Posts a Sales invoice and a Purchase invoice through
 * the SAME authoritative endpoint the UI uses — VoucherScreen::post() — then
 * asserts they land in the Day Book, the party/sales/purchase ledgers, and flow
 * correctly into the Trial Balance, Profit & Loss and Balance Sheet.
 *
 *   Sales invoice:    Dr Acme Retail (customer)  Cr Product Sales   10,000
 *   Purchase invoice: Dr Raw Material Purchase   Cr Metro Supplies   6,000
 *
 * Expected: P&L Income 10,000 − Expenses 6,000 = Nett Profit 4,000; the
 * customer 10,000 sits under Sundry Debtors (Assets) and the supplier 6,000
 * under Sundry Creditors (Liabilities); the Trial Balance stays balanced.
 *
 * Pass --keep to leave the data for the browser; otherwise it rolls back.
 */
class ProveSalesPurchaseCommand extends Command
{
    use ResolvesActiveCompany;
    protected $signature = 'zerobook:prove-sales-purchase {--keep : keep the seeded scenario in the DB} {--company= : run in this company (slug or id) instead of a fresh throwaway one}';

    protected $description = 'Post Sales + Purchase invoices via the shared path and prove reports flow + balance';

    public function handle(BalanceService $svc): int
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

        // Clean prior test data (keep reserved masters like Cash / P&L A/c).
        VoucherEntry::query()->delete();
        Voucher::query()->delete();
        Ledger::where('is_reserved', false)->delete();

        $gid = fn (string $name) => AccountGroup::where('name', $name)->value('id');

        // Party + nominal ledgers, exactly as the UI's Alt+C inline-create would
        // place them (Debtors / Creditors / Sales / Purchase groups).
        $customer = Ledger::create(['name' => 'Acme Retail', 'group_id' => $gid('Sundry Debtors'), 'country' => 'India', 'state' => 'Maharashtra', 'gstin' => '27ABCDE1234F1Z5']);
        $supplier = Ledger::create(['name' => 'Metro Supplies', 'group_id' => $gid('Sundry Creditors'), 'country' => 'India', 'state' => 'Gujarat']);
        $salesLedger = Ledger::create(['name' => 'Product Sales', 'group_id' => $gid('Sales Accounts'), 'country' => 'India']);
        $purchaseLedger = Ledger::create(['name' => 'Raw Material Purchase', 'group_id' => $gid('Purchase Accounts'), 'country' => 'India']);

        $screen = new VoucherScreen;

        // ---- Sales invoice: Dr Party 10,000 / Cr Sales 10,000 (invoice-derived) ----
        $sale = $screen->post([
            'type' => 'sales',
            'date' => '2026-07-04',
            'narration' => 'Sale of goods to Acme Retail',
            'party_ledger_id' => $customer->id,
            'reference_no' => 'INV-2045',
            'reference_date' => '2026-07-04',
            'lines' => [
                ['ledger_id' => $customer->id, 'dr_cr' => 'Dr', 'amount' => 10000],
                ['ledger_id' => $salesLedger->id, 'dr_cr' => 'Cr', 'amount' => 10000],
            ],
        ]);

        // ---- Purchase invoice: Dr Purchase 6,000 / Cr Party 6,000 ----
        $purchase = $screen->post([
            'type' => 'purchase',
            'date' => '2026-07-05',
            'narration' => 'Purchase of raw material from Metro Supplies',
            'party_ledger_id' => $supplier->id,
            'reference_no' => 'BILL-9981',
            'reference_date' => '2026-07-03',
            'lines' => [
                ['ledger_id' => $purchaseLedger->id, 'dr_cr' => 'Dr', 'amount' => 6000],
                ['ledger_id' => $supplier->id, 'dr_cr' => 'Cr', 'amount' => 6000],
            ],
        ]);

        // Confirm the server refuses an out-of-balance invoice (shared gate).
        $rejected = false;
        try {
            $screen->post([
                'type' => 'sales',
                'date' => '2026-07-06',
                'party_ledger_id' => $customer->id,
                'lines' => [
                    ['ledger_id' => $customer->id, 'dr_cr' => 'Dr', 'amount' => 10000],
                    ['ledger_id' => $salesLedger->id, 'dr_cr' => 'Cr', 'amount' => 9000],
                ],
            ]);
        } catch (ValidationException $e) {
            $rejected = true;
        }

        [$from, $to] = $svc->withinFy(null, null);
        $tb = $svc->trialBalance($from, $to);
        $pl = $svc->profitAndLoss($from, $to);
        $bs = $svc->balanceSheet($from, $to);

        // Re-read persisted headers to prove invoice metadata was stored.
        $saleV = Voucher::find($sale['voucher']['id']);
        $purchaseV = Voucher::find($purchase['voucher']['id']);

        // Ledger closings for the party/nominal checks (paise, Dr terms).
        $closings = $svc->ledgerClosings($to);

        $this->line('');
        $this->info('Period: '.$from->toDateString().' to '.$to->toDateString());
        $this->line('--- Posted (via VoucherScreen::post) ---');
        $this->line('  Sales    '.$sale['voucher']['display_number'].'  Dr Acme Retail / Cr Product Sales  10,000  ref='.$saleV->reference_no);
        $this->line('  Purchase '.$purchase['voucher']['display_number'].'  Dr Raw Material Purchase / Cr Metro Supplies  6,000  ref='.$purchaseV->reference_no);
        $this->line('--- Trial Balance ---');
        $this->line('  Total Dr = '.BalanceService::money($tb['total_dr']).'   Total Cr = '.BalanceService::money($tb['total_cr']).'   balanced='.($tb['balanced'] ? 'YES' : 'NO'));
        $this->line('--- Profit & Loss ---');
        $this->line('  Income = '.BalanceService::money($pl['total_income']).'   Expenses = '.BalanceService::money($pl['total_expenses']).'   Net '.($pl['is_profit'] ? 'Profit' : 'Loss').' = '.BalanceService::money($pl['net']));
        $this->line('--- Balance Sheet ---');
        $this->line('  Assets = '.BalanceService::money($bs['total_assets']).'   Liabilities = '.BalanceService::money($bs['total_liabilities']).'   balanced='.($bs['balanced'] ? 'YES' : 'NO'));

        $ok = true;
        $expect = function (string $label, $actual, $expected) use (&$ok) {
            $pass = $actual === $expected;
            $this->line(($pass ? '  [PASS] ' : '  [FAIL] ').$label.' = '.var_export($actual, true).($pass ? '' : ' (expected '.var_export($expected, true).')'));
            $ok = $ok && $pass;
        };

        $this->line('');
        $this->line('--- Assertions (paise / flags) ---');
        // Invoice metadata persisted on the shared path
        $expect('Sales voucher type', $saleV->type, 'sales');
        $expect('Sales party_ledger_id', (int) $saleV->party_ledger_id, (int) $customer->id);
        $expect('Sales reference_no', $saleV->reference_no, 'INV-2045');
        $expect('Sales isInvoice', $saleV->isInvoice(), true);
        $expect('Purchase voucher type', $purchaseV->type, 'purchase');
        $expect('Purchase party_ledger_id', (int) $purchaseV->party_ledger_id, (int) $supplier->id);
        // Correct legs
        $expect('Customer closing (Dr 10,000)', $closings[$customer->id] ?? 0, 1000000);
        $expect('Supplier closing (Cr 6,000)', $closings[$supplier->id] ?? 0, -600000);
        $expect('Sales ledger closing (Cr 10,000)', $closings[$salesLedger->id] ?? 0, -1000000);
        $expect('Purchase ledger closing (Dr 6,000)', $closings[$purchaseLedger->id] ?? 0, 600000);
        // Reports flow
        $expect('TB balanced', $tb['balanced'], true);
        $expect('P&L income = 10,000', $pl['total_income'], 1000000);
        $expect('P&L expenses = 6,000', $pl['total_expenses'], 600000);
        $expect('P&L net profit = 4,000', $pl['net'], 400000);
        $expect('BS total assets = 10,000 (debtor)', $bs['total_assets'], 1000000);
        $expect('BS total liabilities = 6,000 (creditor)', $bs['total_liabilities'], 600000);
        $expect('BS balanced', $bs['balanced'], true);
        // Shared balance gate still rejects an unbalanced invoice
        $expect('Out-of-balance invoice rejected', $rejected, true);

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
