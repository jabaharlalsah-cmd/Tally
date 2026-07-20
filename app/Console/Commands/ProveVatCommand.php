<?php

namespace App\Console\Commands;

use App\Console\Concerns\ResolvesActiveCompany;
use App\Livewire\VoucherScreen;
use App\Models\AccountGroup;
use App\Models\CompanyFeature;
use App\Models\Ledger;
use App\Models\Voucher;
use App\Models\VoucherEntry;
use App\Services\BalanceService;
use App\Services\VatService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 5E numeric proof. Posts Nepal VAT invoices through the SAME endpoint the
 * UI uses — VoucherScreen::post() — and asserts the single-rate VAT math, the
 * server-side authority, mutual exclusivity, and the VAT summary.
 *
 * Everything runs inside ONE transaction that is always rolled back, so it never
 * leaves the company switched to VAT (which would break the GST proof afterwards)
 * — the acceptance criterion for a self-restoring regime.
 */
class ProveVatCommand extends Command
{
    use ResolvesActiveCompany;
    protected $signature = 'zerobook:prove-vat {--keep : keep the seeded scenario in the DB (regime stays VAT)} {--company= : run in this company (slug or id) instead of a fresh throwaway one}';

    protected $description = 'Post Nepal VAT invoices via the shared path and prove the tax math + server authority';

    public function handle(VatService $vat, BalanceService $bs): int
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

        VoucherEntry::query()->delete();
        Voucher::query()->delete();
        Ledger::where('is_reserved', false)->delete();

        // Enable VAT — and prove it disabled GST (mutual exclusivity).
        $f = CompanyFeature::current();
        $f->update(['gst' => true]); // start from GST on to prove the switch
        $f->update(['vat' => true, 'gst' => false]);
        activeCompany()->update(['pan' => '301234567']);
        \App\Support\ActiveCompany::refresh();
        $mutexOk = ! CompanyFeature::current()->gst && CompanyFeature::current()->vat;
        $vat = app(VatService::class);

        $gid = fn (string $n) => AccountGroup::where('name', $n)->value('id');
        $customer = Ledger::create(['name' => 'Kathmandu Traders', 'group_id' => $gid('Sundry Debtors'), 'gstin' => '600112233', 'country' => 'Nepal']);
        $supplier = Ledger::create(['name' => 'Pokhara Supplies', 'group_id' => $gid('Sundry Creditors'), 'gstin' => '600445566', 'country' => 'Nepal']);
        $sales13 = Ledger::create(['name' => 'Goods Sales (VAT)', 'group_id' => $gid('Sales Accounts'), 'gst_rate' => 13, 'country' => 'Nepal']);
        $purch13 = Ledger::create(['name' => 'Goods Purchase (VAT)', 'group_id' => $gid('Purchase Accounts'), 'gst_rate' => 13, 'country' => 'Nepal']);

        $screen = new VoucherScreen;

        // Build a VAT invoice payload exactly as the client does.
        $invoice = function (string $type, Ledger $party, Ledger $nominal, float $amount, string $date) use ($vat) {
            $comp = $vat->computeInvoiceTax($type, [['ledger_id' => $nominal->id, 'amount' => $amount]]);
            $pSide = $type === 'sales' ? 'Dr' : 'Cr';
            $lSide = $type === 'sales' ? 'Cr' : 'Dr';
            $lines = [['ledger_id' => $nominal->id, 'dr_cr' => $lSide, 'amount' => $amount]];
            foreach ($comp['lines'] as $tl) {
                $lines[] = ['ledger_id' => $tl['ledger_id'], 'dr_cr' => $tl['dr_cr'], 'amount' => $tl['amount']];
            }
            array_unshift($lines, ['ledger_id' => $party->id, 'dr_cr' => $pSide, 'amount' => $comp['total_paise'] / 100]);

            return ['type' => $type, 'date' => $date, 'party_ledger_id' => $party->id, 'reference_no' => 'VAT-'.$party->id, 'lines' => $lines];
        };

        $sale = $screen->post($invoice('sales', $customer, $sales13, 10000, '2026-07-04'));
        $purchase = $screen->post($invoice('purchase', $supplier, $purch13, 6000, '2026-07-05'));

        // Server authority: a tampered VAT amount is rejected.
        $rejected = false;
        try {
            $screen->post([
                'type' => 'sales', 'date' => '2026-07-06', 'party_ledger_id' => $customer->id,
                'lines' => [
                    ['ledger_id' => $customer->id, 'dr_cr' => 'Dr', 'amount' => 11200], // 10000 + 1200 (wrong VAT)
                    ['ledger_id' => $sales13->id, 'dr_cr' => 'Cr', 'amount' => 10000],
                    ['ledger_id' => $vat->taxLedgerId('output'), 'dr_cr' => 'Cr', 'amount' => 1200], // should be 1300
                ],
            ]);
        } catch (ValidationException $e) {
            $rejected = true;
        }

        $legs = function (int $vid) {
            $out = [];
            foreach (Voucher::find($vid)->entries()->with('ledger')->get() as $e) {
                $out[$e->ledger->name] = ['side' => $e->dr_cr, 'paise' => (int) round($e->amount * 100)];
            }
            return $out;
        };
        $L1 = $legs($sale['voucher']['id']);
        $L2 = $legs($purchase['voucher']['id']);

        [$from, $to] = $bs->withinFy(null, null);
        $tb = $bs->trialBalance($from, $to);
        $sum = $vat->summary($from, $to);
        $money = fn ($p) => BalanceService::money($p);

        $this->line('');
        $this->info('Company VAT (Nepal) on · GST auto-disabled='.($mutexOk ? 'YES' : 'NO').' · period '.$from->toDateString().' to '.$to->toDateString());
        $this->line('--- Sales 10,000 @ 13% ---');
        foreach ($L1 as $n => $r) {
            $this->line('   '.$r['side'].' '.$n.' '.$money($r['paise']));
        }
        $this->line('--- Purchase 6,000 @ 13% ---');
        foreach ($L2 as $n => $r) {
            $this->line('   '.$r['side'].' '.$n.' '.$money($r['paise']));
        }
        $this->line('--- VAT summary ---  Output '.$money($sum['output']).'  Input '.$money($sum['input']).'  Net payable '.$money($sum['net_payable']));
        $this->line('--- Trial Balance ---  Dr '.$money($tb['total_dr']).' = Cr '.$money($tb['total_cr']).'  balanced='.($tb['balanced'] ? 'YES' : 'NO'));

        $ok = true;
        $expect = function (string $label, $actual, $expected) use (&$ok) {
            $pass = $actual === $expected;
            $this->line(($pass ? '  [PASS] ' : '  [FAIL] ').$label.' = '.var_export($actual, true).($pass ? '' : ' (expected '.var_export($expected, true).')'));
            $ok = $ok && $pass;
        };

        $this->line('');
        $this->line('--- Assertions (paise / flags) ---');
        $expect('Enabling VAT disabled GST', $mutexOk, true);
        // Sales: Dr Party 11,300 / Cr Sales 10,000 / Cr Output VAT 1,300
        $expect('Sales: Dr Party 11,300', $L1['Kathmandu Traders'] ?? null, ['side' => 'Dr', 'paise' => 1130000]);
        $expect('Sales: Cr Sales 10,000', $L1['Goods Sales (VAT)'] ?? null, ['side' => 'Cr', 'paise' => 1000000]);
        $expect('Sales: Cr Output VAT 1,300', $L1['Output VAT'] ?? null, ['side' => 'Cr', 'paise' => 130000]);
        $expect('Sales: no CGST/SGST/IGST', isset($L1['Output CGST']) || isset($L1['Output IGST']), false);
        // Purchase: Dr Purchase 6,000 / Dr Input VAT 780 / Cr Party 6,780
        $expect('Purchase: Dr Purchase 6,000', $L2['Goods Purchase (VAT)'] ?? null, ['side' => 'Dr', 'paise' => 600000]);
        $expect('Purchase: Dr Input VAT 780', $L2['Input VAT'] ?? null, ['side' => 'Dr', 'paise' => 78000]);
        $expect('Purchase: Cr Party 6,780', $L2['Pokhara Supplies'] ?? null, ['side' => 'Cr', 'paise' => 678000]);
        // Server authority
        $expect('Tampered VAT payload rejected', $rejected, true);
        // Summary
        $expect('Summary Output VAT = 1,300', $sum['output'], 130000);
        $expect('Summary Input VAT = 780', $sum['input'], 78000);
        $expect('Summary Net Payable = 520', $sum['net_payable'], 52000);
        // Integrity
        $expect('Trial Balance balanced', $tb['balanced'], true);

        if ($keep) {
            DB::commit();
            $this->info($ok ? 'ALL ASSERTIONS PASSED — scenario kept (regime = VAT).' : 'ASSERTIONS FAILED — kept for inspection.');
        } else {
            // Always roll back → the company's prior regime (GST) is restored, so the
            // other prove-* commands keep their assumptions.
            DB::rollBack();
            $this->info($ok ? 'ALL ASSERTIONS PASSED — rolled back (prior regime restored).' : 'ASSERTIONS FAILED — rolled back.');
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
