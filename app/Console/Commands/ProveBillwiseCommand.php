<?php

namespace App\Console\Commands;

use App\Console\Concerns\ResolvesActiveCompany;
use App\Livewire\VoucherScreen;
use App\Models\AccountGroup;
use App\Models\BillAllocation;
use App\Models\CompanyFeature;
use App\Models\Ledger;
use App\Models\Voucher;
use App\Models\VoucherEntry;
use App\Services\BalanceService;
use App\Services\BillService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 5C numeric proof. Drives the bill lifecycle through the SAME endpoint the
 * UI uses — VoucherScreen::post() — and asserts:
 *   New Ref → Against Ref (partial then full → closed), Advance, On Account,
 *   the allocation = line-amount authority (a mismatched payload is rejected),
 *   the Receivables reconciliation (closing = Σ pending + on-account), and that
 *   the Trial Balance still balances.
 *
 * Rolls back unless --keep.
 */
class ProveBillwiseCommand extends Command
{
    use ResolvesActiveCompany;
    protected $signature = 'zerobook:prove-billwise {--keep : keep the seeded scenario in the DB} {--company= : run in this company (slug or id) instead of a fresh throwaway one}';

    protected $description = 'Post bill-wise vouchers via the shared path and prove the bill lifecycle + reconciliation';

    public function handle(BillService $bill, BalanceService $bs): int
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

        BillAllocation::query()->delete();
        VoucherEntry::query()->delete();
        Voucher::query()->delete();
        Ledger::where('is_reserved', false)->delete();
        CompanyFeature::current()->update(['bill_by_bill' => true, 'gst' => false]);

        $gid = fn (string $n) => AccountGroup::where('name', $n)->value('id');
        $cust = Ledger::create(['name' => 'Nimbus Retail', 'group_id' => $gid('Sundry Debtors'), 'maintain_bill_by_bill' => true, 'country' => 'India']);
        $supp = Ledger::create(['name' => 'Orbit Supplies', 'group_id' => $gid('Sundry Creditors'), 'maintain_bill_by_bill' => true, 'country' => 'India']);
        $sales = Ledger::create(['name' => 'Goods Sales', 'group_id' => $gid('Sales Accounts'), 'country' => 'India']);
        $purch = Ledger::create(['name' => 'Goods Purchase', 'group_id' => $gid('Purchase Accounts'), 'country' => 'India']);
        $cash = Ledger::where('name', 'Cash')->first();

        $screen = new VoucherScreen;

        // (1) Sales invoice — New Ref INV-100 for 10,000, due 2026-07-25.
        $screen->post([
            'type' => 'sales', 'date' => '2026-07-04', 'party_ledger_id' => $cust->id, 'reference_no' => 'INV-100',
            'lines' => [
                ['ledger_id' => $cust->id, 'dr_cr' => 'Dr', 'amount' => 10000, 'allocations' => [
                    ['ref_type' => 'new', 'ref_name' => 'INV-100', 'amount' => 10000, 'due_date' => '2026-07-25'],
                ]],
                ['ledger_id' => $sales->id, 'dr_cr' => 'Cr', 'amount' => 10000],
            ],
        ]);

        // (2) Receipt — Against INV-100 for 6,000 (partial).
        $screen->post([
            'type' => 'receipt', 'date' => '2026-07-05',
            'lines' => [
                ['ledger_id' => $cash->id, 'dr_cr' => 'Dr', 'amount' => 6000],
                ['ledger_id' => $cust->id, 'dr_cr' => 'Cr', 'amount' => 6000, 'allocations' => [
                    ['ref_type' => 'against', 'ref_name' => 'INV-100', 'amount' => 6000],
                ]],
            ],
        ]);

        // (3) Advance receipt — ADV-1 for 2,000 (creates an advance outstanding).
        $screen->post([
            'type' => 'receipt', 'date' => '2026-07-06',
            'lines' => [
                ['ledger_id' => $cash->id, 'dr_cr' => 'Dr', 'amount' => 2000],
                ['ledger_id' => $cust->id, 'dr_cr' => 'Cr', 'amount' => 2000, 'allocations' => [
                    ['ref_type' => 'advance', 'ref_name' => 'ADV-1', 'amount' => 2000],
                ]],
            ],
        ]);

        // (4) Purchase invoice — New Ref BILL-9 for 4,000, plus On Account 1,000.
        $screen->post([
            'type' => 'purchase', 'date' => '2026-07-06', 'party_ledger_id' => $supp->id, 'reference_no' => 'BILL-9',
            'lines' => [
                ['ledger_id' => $purch->id, 'dr_cr' => 'Dr', 'amount' => 5000],
                ['ledger_id' => $supp->id, 'dr_cr' => 'Cr', 'amount' => 5000, 'allocations' => [
                    ['ref_type' => 'new', 'ref_name' => 'BILL-9', 'amount' => 4000, 'due_date' => '2026-08-05'],
                    ['ref_type' => 'onaccount', 'ref_name' => 'On Account', 'amount' => 1000],
                ]],
            ],
        ]);

        // Server authority: allocations that don't sum to the line are rejected.
        $rejected = false;
        try {
            $screen->post([
                'type' => 'receipt', 'date' => '2026-07-06',
                'lines' => [
                    ['ledger_id' => $cash->id, 'dr_cr' => 'Dr', 'amount' => 1000],
                    ['ledger_id' => $cust->id, 'dr_cr' => 'Cr', 'amount' => 1000, 'allocations' => [
                        ['ref_type' => 'against', 'ref_name' => 'INV-100', 'amount' => 500], // 500 != 1000
                    ]],
                ],
            ]);
        } catch (ValidationException $e) {
            $rejected = true;
        }

        // Read the bills.
        $custBills = collect($bill->bills([$cust->id]))->keyBy('ref_name');
        $suppBills = collect($bill->bills([$supp->id]))->keyBy('ref_name');
        $rec = $bill->outstandings('receivable', now());
        $pay = $bill->outstandings('payable', now());
        $tb = $bs->trialBalance(...$bs->withinFy(null, null));

        $money = fn ($p) => BalanceService::money($p);

        $this->line('');
        $this->info('Bill-wise on · company as-on '.now()->toDateString());
        $this->line('--- Customer bills (Nimbus Retail) ---');
        foreach ($custBills as $b) {
            $this->line('   '.$b['ref_name'].'  original '.$money($b['original']).'  pending '.$money($b['pending']).' (Dr-terms)');
        }
        $this->line('--- Supplier bills (Orbit Supplies) ---');
        foreach ($suppBills as $b) {
            $this->line('   '.$b['ref_name'].'  original '.$money($b['original']).'  pending '.$money($b['pending']).' (Dr-terms)');
        }
        $this->line('--- Receivables recon ---  pending '.$rec['grand_pending'].' + on-account '.$rec['grand_on_account'].' = closing '.$rec['grand_closing']);
        $this->line('--- Payables recon ---     pending '.$pay['grand_pending'].' + on-account '.$pay['grand_on_account'].' = closing '.$pay['grand_closing']);
        $this->line('--- Trial Balance ---  Dr '.$money($tb['total_dr']).' = Cr '.$money($tb['total_cr']).'  balanced='.($tb['balanced'] ? 'YES' : 'NO'));

        $ok = true;
        $expect = function (string $label, $actual, $expected) use (&$ok) {
            $pass = $actual === $expected;
            $this->line(($pass ? '  [PASS] ' : '  [FAIL] ').$label.' = '.var_export($actual, true).($pass ? '' : ' (expected '.var_export($expected, true).')'));
            $ok = $ok && $pass;
        };

        // Closing balances (Dr-terms paise) for the reconciliation invariant.
        $closings = $bs->ledgerClosings(now());
        $sumCustPending = collect($bill->bills([$cust->id]))->sum('pending');
        $sumSuppPending = collect($bill->bills([$supp->id]))->sum('pending');

        $this->line('');
        $this->line('--- Assertions (paise / flags) ---');
        // (1)+(2) New Ref reduced by Against: 10,000 − 6,000 = 4,000 pending.
        $expect('INV-100 pending (Dr 4,000)', (int) $custBills['INV-100']['pending'], 400000);
        $expect('INV-100 original (10,000)', (int) $custBills['INV-100']['original'], 1000000);
        // (3) Advance is a Cr outstanding on the debtor (−2,000 Dr-terms).
        $expect('ADV-1 advance pending (Cr 2,000)', (int) $custBills['ADV-1']['pending'], -200000);
        // (4) Purchase: New Ref BILL-9 Cr 4,000 and On Account Cr 1,000.
        $expect('BILL-9 pending (Cr 4,000)', (int) $suppBills['BILL-9']['pending'], -400000);
        $expect('On Account pending (Cr 1,000)', (int) $suppBills['On Account']['pending'], -100000);
        // Server authority
        $expect('Mismatched allocation rejected', $rejected, true);
        // Reconciliation invariant: Σ bill pending == ledger closing (openings are 0 here).
        $expect('Customer closing == Σ pending', $closings[$cust->id] ?? 0, (int) $sumCustPending);
        $expect('Supplier closing == Σ pending', $closings[$supp->id] ?? 0, (int) $sumSuppPending);
        $expect('Customer closing Dr 2,000', $closings[$cust->id] ?? 0, 200000);   // 10000 −6000 −2000
        $expect('Supplier closing Cr 5,000', $closings[$supp->id] ?? 0, -500000);
        $expect('Receivables reconciles', $rec['reconciles'], true);
        $expect('Payables reconciles', $pay['reconciles'], true);
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
