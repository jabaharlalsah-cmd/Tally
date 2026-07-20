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
use App\Services\GstService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 5B numeric proof. Posts GST invoices through the SAME endpoint the UI
 * uses — VoucherScreen::post() — and asserts intra-state (CGST+SGST), inter-state
 * (IGST), a multi-rate invoice, the server-side tax authority (a tampered payload
 * is rejected), the GST summary, and that the Trial Balance still balances.
 *
 * Rolls everything back unless --keep is given.
 */
class ProveGstCommand extends Command
{
    use ResolvesActiveCompany;
    protected $signature = 'zerobook:prove-gst {--keep : keep the seeded scenario in the DB} {--company= : run in this company (slug or id) instead of a fresh throwaway one}';

    protected $description = 'Post GST invoices via the shared path and prove the tax math + server authority';

    public function handle(GstService $gst, BalanceService $bs): int
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

        // Clean prior test data (keep reserved masters, incl. the tax ledgers).
        VoucherEntry::query()->delete();
        Voucher::query()->delete();
        Ledger::where('is_reserved', false)->delete();

        // Enable GST + company state.
        CompanyFeature::current()->update(['gst' => true]);
        activeCompany()->update(['state' => 'Maharashtra', 'gstin' => '27AAAAA0000A1Z5']);
        $gst = app(GstService::class); // fresh (state changed)

        $gid = fn (string $n) => AccountGroup::where('name', $n)->value('id');

        $customerMH = Ledger::create(['name' => 'Acme (MH)', 'group_id' => $gid('Sundry Debtors'), 'state' => 'Maharashtra', 'gstin' => '27AAACA1111A1Z1', 'country' => 'India']);
        $customerGJ = Ledger::create(['name' => 'Zeta (GJ)', 'group_id' => $gid('Sundry Debtors'), 'state' => 'Gujarat', 'gstin' => '24AAACZ2222Z1Z2', 'country' => 'India']);
        $supplierMH = Ledger::create(['name' => 'Metro (MH)', 'group_id' => $gid('Sundry Creditors'), 'state' => 'Maharashtra', 'country' => 'India']);
        $sales18 = Ledger::create(['name' => 'Sales 18%', 'group_id' => $gid('Sales Accounts'), 'gst_rate' => 18, 'hsn_sac' => '9983', 'country' => 'India']);
        $sales5 = Ledger::create(['name' => 'Sales 5%', 'group_id' => $gid('Sales Accounts'), 'gst_rate' => 5, 'country' => 'India']);
        $purch18 = Ledger::create(['name' => 'Purchase 18%', 'group_id' => $gid('Purchase Accounts'), 'gst_rate' => 18, 'country' => 'India']);

        $screen = new VoucherScreen;

        // Build an invoice payload exactly as the client does: taxable legs + the
        // GST-computed tax legs + a party leg = taxable + tax.
        $invoice = function (string $type, Ledger $party, array $taxableLines, string $date) use ($gst) {
            $taxable = array_map(fn ($l) => ['ledger_id' => $l[0], 'amount' => $l[1]], $taxableLines);
            $comp = $gst->computeInvoiceTax($type, $party->state, $taxable);
            $pSide = $type === 'sales' ? 'Dr' : 'Cr';
            $lSide = $type === 'sales' ? 'Cr' : 'Dr';
            $lines = [];
            $totalP = 0;
            foreach ($taxable as $t) {
                $lines[] = ['ledger_id' => $t['ledger_id'], 'dr_cr' => $lSide, 'amount' => $t['amount']];
                $totalP += (int) round($t['amount'] * 100);
            }
            foreach ($comp['lines'] as $tl) {
                $lines[] = ['ledger_id' => $tl['ledger_id'], 'dr_cr' => $tl['dr_cr'], 'amount' => $tl['amount']];
            }
            $totalP += $comp['tax_paise'];
            array_unshift($lines, ['ledger_id' => $party->id, 'dr_cr' => $pSide, 'amount' => $totalP / 100]);

            return ['type' => $type, 'date' => $date, 'party_ledger_id' => $party->id, 'reference_no' => 'GST-'.$party->id, 'lines' => $lines];
        };

        // Post the four invoices.
        $v1 = $screen->post($invoice('sales', $customerMH, [[$sales18->id, 10000]], '2026-07-04'));       // intra 10000@18
        $v2 = $screen->post($invoice('sales', $customerGJ, [[$sales18->id, 10000]], '2026-07-04'));       // inter 10000@18
        $v3 = $screen->post($invoice('purchase', $supplierMH, [[$purch18->id, 6000]], '2026-07-05'));     // intra purchase 6000@18
        $v4 = $screen->post($invoice('sales', $customerMH, [[$sales18->id, 10000], [$sales5->id, 2000]], '2026-07-06')); // multi-rate

        // Helper to read a voucher's entries as [ledgerName => signedPaise].
        $legs = function (int $vid) {
            $out = [];
            foreach (Voucher::find($vid)->entries()->with('ledger')->get() as $e) {
                $p = (int) round($e->amount * 100);
                $out[$e->ledger->name] = ['side' => $e->dr_cr, 'paise' => $p];
            }
            return $out;
        };

        $L1 = $legs($v1['voucher']['id']);
        $L2 = $legs($v2['voucher']['id']);
        $L3 = $legs($v3['voucher']['id']);

        // Server-authority: a tampered payload (CGST understated) must be rejected.
        $tamperRejected = false;
        try {
            $screen->post([
                'type' => 'sales', 'date' => '2026-07-07', 'party_ledger_id' => $customerMH->id,
                'lines' => [
                    ['ledger_id' => $customerMH->id, 'dr_cr' => 'Dr', 'amount' => 11700], // 10000 + 800 + 900
                    ['ledger_id' => $sales18->id, 'dr_cr' => 'Cr', 'amount' => 10000],
                    ['ledger_id' => $gst->taxLedgerId('output', 'central'), 'dr_cr' => 'Cr', 'amount' => 800], // WRONG (should be 900)
                    ['ledger_id' => $gst->taxLedgerId('output', 'state'), 'dr_cr' => 'Cr', 'amount' => 900],
                ],
            ]);
        } catch (ValidationException $e) {
            $tamperRejected = true;
        }

        [$from, $to] = $bs->withinFy(null, null);
        $tb = $bs->trialBalance($from, $to);
        $sum = $gst->summary($from, $to);

        $money = fn ($p) => BalanceService::money($p);

        $this->line('');
        $this->info('Company state Maharashtra · GST on · period '.$from->toDateString().' to '.$to->toDateString());
        $this->line('--- Intra sale 10,000 @ 18% (MH → MH) ---');
        foreach ($L1 as $n => $r) {
            $this->line('   '.$r['side'].' '.$n.' '.$money($r['paise']));
        }
        $this->line('--- Inter sale 10,000 @ 18% (MH → GJ) ---');
        foreach ($L2 as $n => $r) {
            $this->line('   '.$r['side'].' '.$n.' '.$money($r['paise']));
        }
        $this->line('--- GST summary ---');
        $this->line('   Output tax = '.$money($sum['output_total']).'   Input tax (ITC) = '.$money($sum['input_total']).'   Net payable = '.$money($sum['net_payable']));
        $this->line('   Taxable sales = '.$money($sum['taxable_sales']).'   Taxable purchase = '.$money($sum['taxable_purchase']));
        $this->line('--- Trial Balance ---');
        $this->line('   Dr '.$money($tb['total_dr']).' = Cr '.$money($tb['total_cr']).'  balanced='.($tb['balanced'] ? 'YES' : 'NO'));

        $ok = true;
        $expect = function (string $label, $actual, $expected) use (&$ok) {
            $pass = $actual === $expected;
            $this->line(($pass ? '  [PASS] ' : '  [FAIL] ').$label.' = '.var_export($actual, true).($pass ? '' : ' (expected '.var_export($expected, true).')'));
            $ok = $ok && $pass;
        };

        $this->line('');
        $this->line('--- Assertions (paise / flags) ---');
        // Intra sale legs
        $expect('Intra: Dr Party', $L1['Acme (MH)'] ?? null, ['side' => 'Dr', 'paise' => 1180000]);
        $expect('Intra: Cr Sales', $L1['Sales 18%'] ?? null, ['side' => 'Cr', 'paise' => 1000000]);
        $expect('Intra: Cr Output CGST', $L1['Output CGST'] ?? null, ['side' => 'Cr', 'paise' => 90000]);
        $expect('Intra: Cr Output SGST', $L1['Output SGST'] ?? null, ['side' => 'Cr', 'paise' => 90000]);
        $expect('Intra: no IGST leg', isset($L1['Output IGST']), false);
        // Inter sale legs
        $expect('Inter: Dr Party', $L2['Zeta (GJ)'] ?? null, ['side' => 'Dr', 'paise' => 1180000]);
        $expect('Inter: Cr Output IGST', $L2['Output IGST'] ?? null, ['side' => 'Cr', 'paise' => 180000]);
        $expect('Inter: no CGST leg', isset($L2['Output CGST']), false);
        // Purchase legs (intra)
        $expect('Purchase: Dr Purchase', $L3['Purchase 18%'] ?? null, ['side' => 'Dr', 'paise' => 600000]);
        $expect('Purchase: Dr Input CGST', $L3['Input CGST'] ?? null, ['side' => 'Dr', 'paise' => 54000]);
        $expect('Purchase: Dr Input SGST', $L3['Input SGST'] ?? null, ['side' => 'Dr', 'paise' => 54000]);
        $expect('Purchase: Cr Party', $L3['Metro (MH)'] ?? null, ['side' => 'Cr', 'paise' => 708000]);
        // Server authority
        $expect('Tampered tax payload rejected', $tamperRejected, true);
        // Summary (4 posted invoices: Output CGST 900+950, SGST 900+950, IGST 1800; Input 540+540)
        $expect('Summary output tax = 5,500', $sum['output_total'], 550000);
        $expect('Summary input tax  = 1,080', $sum['input_total'], 108000);
        $expect('Summary net payable = 4,420', $sum['net_payable'], 442000);
        $expect('Summary taxable sales = 32,000', $sum['taxable_sales'], 3200000);
        $expect('Summary taxable purchase = 6,000', $sum['taxable_purchase'], 600000);
        // Integrity
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
