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
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\Voucher;
use App\Models\CostCentre;
use App\Services\BalanceService;
use App\Services\BillService;
use App\Services\GstService;
use App\Services\StockService;
use App\Services\Tenancy\TenantProvisioner;
use App\Services\VatService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Phase 8A numeric proof — Debit & Credit Notes, posted through the SAME
 * VoucherScreen::post() every voucher uses. Proves the direction is exactly right
 * and — the subtlest point — that a Sales Return brought in against its original
 * sale does NOT distort the running weighted average.
 */
class ProveNotesCommand extends Command
{
    use ResolvesActiveCompany;
    protected $signature = 'zerobook:prove-notes {--keep : keep the notestest tenant provisioned} {--company= : run in this company (slug or id); default = the throwaway tenant’s default company}';

    protected $description = 'Prove Debit/Credit Notes: mirror Dr/Cr, GST/VAT reversal, cost-preserving sales return, tamper rejection, bill-wise & cost-centre';

    private bool $ok = true;

    public function handle(TenantProvisioner $provisioner): int
    {
        $slug = 'notestest';
        try {
            $provisioner->teardown($slug);
            $provisioner->provision($slug, 'Notes Test Co', 'professional');

            Tenant::find($slug)->run(fn () => $this->runProof());
        } catch (Throwable $e) {
            $this->ok = false;
            $this->error('Fatal: '.$e->getMessage());
            $this->line($e->getFile().':'.$e->getLine());
        } finally {
            if (! $this->option('keep')) {
                $provisioner->teardown($slug);
            }
        }

        $this->line('');
        $this->info($this->ok ? 'ALL NOTES ASSERTIONS PASSED.' : 'NOTES ASSERTIONS FAILED.');

        return $this->ok ? self::SUCCESS : self::FAILURE;
    }

    private function runProof(): void
    {
        // Phase 12A — pin the throwaway tenant's default company as active before
        // any scoped model is touched (CLI has no session).
        if (! $this->resolveActiveCompany()) {
            throw new \RuntimeException('No default company in the throwaway tenant.');
        }

        $screen = new VoucherScreen();
        $gst = app(GstService::class);
        $bs = app(BalanceService::class);
        $stock = app(StockService::class);

        // GST on, intra-state (company state == party state).
        CompanyFeature::current()->update(['gst' => true, 'vat' => false]);
        activeCompany()->update(['state' => 'Maharashtra', 'gstin' => '27AAAAA0000A1Z5']);
        $gst = app(GstService::class);

        $gid = fn (string $n) => AccountGroup::where('name', $n)->value('id');
        $godown = Godown::where('name', 'Main Location')->value('id');
        $unit = Unit::create(['name' => 'Nos', 'symbol' => 'Nos', 'decimal_places' => 0]);
        $grp = StockGroup::create(['name' => 'Finished Goods']);
        $widget = StockItem::create(['name' => 'Widget', 'stock_group_id' => $grp->id, 'unit_id' => $unit->id,
            'opening_qty' => 0, 'opening_rate' => 0, 'opening_value' => 0, 'gst_rate' => 18, 'costing_method' => 'weighted_average']);

        $cust = Ledger::create(['name' => 'Cust (MH)', 'group_id' => $gid('Sundry Debtors'), 'state' => 'Maharashtra', 'country' => 'India']);
        $supp = Ledger::create(['name' => 'Supp (MH)', 'group_id' => $gid('Sundry Creditors'), 'state' => 'Maharashtra', 'country' => 'India']);
        $salesLed = Ledger::create(['name' => 'Sales @18', 'group_id' => $gid('Sales Accounts'), 'gst_rate' => 18, 'country' => 'India']);
        $purchLed = Ledger::create(['name' => 'Purchase @18', 'group_id' => $gid('Purchase Accounts'), 'gst_rate' => 18, 'country' => 'India']);
        $salesReturn = Ledger::where('name', 'Sales Return')->first();
        $purchReturn = Ledger::where('name', 'Purchase Return')->first();

        // A GST item-invoice payload for any of the four party-centric types.
        $inv = function (string $type, Ledger $party, Ledger $rev, float $qty, float $rate, string $date, ?int $ref = null) use ($gst, $widget, $godown) {
            $base = round($qty * $rate, 2);
            $comp = $gst->computeInvoiceTax($type, $party->state, [['amount' => $base, 'rate' => (float) $widget->gst_rate]]);
            $revSide = match ($type) { 'sales' => 'Cr', 'credit_note' => 'Dr', 'debit_note' => 'Cr', default => 'Dr' };
            $partySide = match ($type) { 'sales' => 'Dr', 'credit_note' => 'Cr', 'debit_note' => 'Dr', default => 'Cr' };
            $lines = [['ledger_id' => $rev->id, 'dr_cr' => $revSide, 'amount' => $base]];
            $totalP = (int) round($base * 100);
            foreach ($comp['lines'] as $tl) {
                $lines[] = ['ledger_id' => $tl['ledger_id'], 'dr_cr' => $tl['dr_cr'], 'amount' => $tl['amount']];
            }
            $totalP += $comp['tax_paise'];
            array_unshift($lines, ['ledger_id' => $party->id, 'dr_cr' => $partySide, 'amount' => $totalP / 100]);
            $p = ['type' => $type, 'date' => $date, 'party_ledger_id' => $party->id,
                'lines' => $lines, 'items' => [['stock_item_id' => $widget->id, 'godown_id' => $godown, 'qty' => $qty, 'rate' => $rate]]];
            if ($ref) {
                $p['reference_voucher_id'] = $ref;
            }

            return $p;
        };

        $legs = function (int $vid) {
            $out = [];
            foreach (Voucher::find($vid)->entries()->with('ledger')->get() as $e) {
                $out[$e->ledger->name] = ['side' => $e->dr_cr, 'paise' => (int) round($e->amount * 100)];
            }

            return $out;
        };
        $stkRow = fn (int $vid) => StockEntry::where('voucher_id', $vid)->orderBy('line_no')->first();

        // ── build the running average: buy 100 @ 50, buy 100 @ 70 ⇒ avg 60 ──────
        $screen->post($inv('purchase', $supp, $purchLed, 100, 50, '2026-04-01'));
        $screen->post($inv('purchase', $supp, $purchLed, 100, 70, '2026-04-02'));
        $this->expectClose('Running average after the two buys = 60', $stock->weightedAverageRate($widget->id, Carbon::parse('2026-04-03')), 60);

        // ═══ Sales invoice: sell 20 @ 100 ════════════════════════════════════════
        $this->section('Sales invoice — sell 20 @ 100');
        $sale = $screen->post($inv('sales', $cust, $salesLed, 20, 100, '2026-04-05'));
        $saleId = $sale['voucher']['id'];
        $sl = $legs($saleId);
        $this->expect('Sales: Dr Cust 2,360', $sl['Cust (MH)'] ?? null, ['side' => 'Dr', 'paise' => 236000]);
        $this->expect('Sales: Cr Sales 2,000', $sl['Sales @18'] ?? null, ['side' => 'Cr', 'paise' => 200000]);
        $this->expect('Sales: Cr Output CGST 180', $sl['Output CGST'] ?? null, ['side' => 'Cr', 'paise' => 18000]);
        $this->expect('Sales: Cr Output SGST 180', $sl['Output SGST'] ?? null, ['side' => 'Cr', 'paise' => 18000]);
        $so = $stkRow($saleId);
        $this->expect('Sales stock OUT qty 20', (float) $so->quantity, 20.0);
        $this->expectClose('Sales OUT cost rate 60', (float) $so->rate, 60);
        $this->expectClose('Sales OUT cost value 1,200', (float) $so->value, 1200);
        $this->expectClose('Sales OUT sale_rate 100', (float) $so->sale_rate, 100);
        $close = $stock->closingBalance($widget->id, Carbon::parse('2026-04-05'));
        $this->expectClose('Running after sale: qty 180', $close['qty'], 180);
        $this->expectClose('Running after sale: value 10,800', $close['value'], 10800);

        // ═══ Credit Note referencing that sale, 5 units returned ════════════════
        $this->section('Credit Note (Sales Return) — ref the sale, 5 units back');
        $custBefore = $bs->ledgerClosings(Carbon::parse('2026-04-06'))[$cust->id] ?? 0;
        $cn = $screen->post($inv('credit_note', $cust, $salesReturn, 5, 100, '2026-04-06', $saleId));
        $cnId = $cn['voucher']['id'];
        $cl = $legs($cnId);
        $this->expect('CN: Dr Sales Return 500', $cl['Sales Return'] ?? null, ['side' => 'Dr', 'paise' => 50000]);
        $this->expect('CN: Dr Output CGST 45 (reversal)', $cl['Output CGST'] ?? null, ['side' => 'Dr', 'paise' => 4500]);
        $this->expect('CN: Dr Output SGST 45 (reversal)', $cl['Output SGST'] ?? null, ['side' => 'Dr', 'paise' => 4500]);
        $this->expect('CN: Cr Cust 590 (tax-inclusive)', $cl['Cust (MH)'] ?? null, ['side' => 'Cr', 'paise' => 59000]);
        $ci = $stkRow($cnId);
        $this->expect('CN stock IN direction', $ci->direction, 'in');
        $this->expectClose('CN IN cost rate 60 (from the ORIGINAL sale, not a made-up rate)', (float) $ci->rate, 60);
        $this->expectClose('CN IN cost value 300', (float) $ci->value, 300);
        $this->expectClose('CN IN sale_rate 100 (credited-back, kept separate from cost)', (float) $ci->sale_rate, 100);
        $closeCn = $stock->closingBalance($widget->id, Carbon::parse('2026-04-06'));
        $this->expectClose('Running after CN: qty 185', $closeCn['qty'], 185);
        $this->expectClose('Running after CN: value 11,100', $closeCn['value'], 11100);
        $this->expectClose('Running average AFTER the return is STILL 60.00 (undistorted)', $stock->weightedAverageRate($widget->id, Carbon::parse('2026-04-07')), 60);
        $custAfter = $bs->ledgerClosings(Carbon::parse('2026-04-06'))[$cust->id] ?? 0;
        $this->expect('Party balance dropped by exactly 590 (the tax-inclusive Note total)', $custBefore - $custAfter, 59000);
        $this->expect('Trial Balance still balances', $bs->trialBalance(...$bs->withinFy(null, null))['balanced'], true);

        // ═══ Purchase invoice + Debit Note (Purchase Return) ════════════════════
        $this->section('Purchase 10 @ 55, then Debit Note ref it, 3 units back');
        $pu = $screen->post($inv('purchase', $supp, $purchLed, 10, 55, '2026-04-07'));
        $this->expectClose('Running average after the purchase ≈ 59.74', $stock->weightedAverageRate($widget->id, Carbon::parse('2026-04-08')), 59.7436, 0.001);
        $inputBefore = $gst->summary(...$bs->withinFy(null, null))['input_total'];
        $dn = $screen->post($inv('debit_note', $supp, $purchReturn, 3, 55, '2026-04-08', $pu['voucher']['id']));
        $dnId = $dn['voucher']['id'];
        $dl = $legs($dnId);
        $this->expect('DN: Dr Supp 194.70 (tax-inclusive)', $dl['Supp (MH)'] ?? null, ['side' => 'Dr', 'paise' => 19470]);
        $this->expect('DN: Cr Purchase Return 165', $dl['Purchase Return'] ?? null, ['side' => 'Cr', 'paise' => 16500]);
        $this->expect('DN: Cr Input CGST 14.85 (reversal)', $dl['Input CGST'] ?? null, ['side' => 'Cr', 'paise' => 1485]);
        $this->expect('DN: Cr Input SGST 14.85 (reversal)', $dl['Input SGST'] ?? null, ['side' => 'Cr', 'paise' => 1485]);
        $do = $stkRow($dnId);
        $this->expect('DN stock OUT direction', $do->direction, 'out');
        $this->expectClose('DN OUT costed at the CURRENT running average ≈ 59.74', (float) $do->rate, 59.7436, 0.001);
        $this->expectClose('DN OUT qty 3', (float) $do->quantity, 3);

        // ═══ GST summary reflects both reversals ════════════════════════════════
        $this->section('GST summary reflects the reversals');
        $sum = $gst->summary(...$bs->withinFy(null, null));
        // Output: 2 buys have none; the sale added 360; the CN reversed 90 → net 270.
        $this->expect('Output tax = 270 (360 sale − 90 credit-note reversal)', $sum['output_total'], 27000);
        $this->expect('Input tax dropped by 29.70 across the debit note', $inputBefore - $sum['input_total'], 2970);

        // ═══ Free-standing Credit Note (no reference) — costs at current average ═
        $this->section('Free-standing Credit Note (no reference) — fallback to current average');
        $avgNow = $stock->weightedAverageRate($widget->id, Carbon::parse('2026-04-09'));
        $free = $screen->post($inv('credit_note', $cust, $salesReturn, 2, 100, '2026-04-09')); // no ref
        $fi = $stkRow($free['voucher']['id']);
        $this->expect('Free-standing CN has no reference_voucher_id', Voucher::find($free['voucher']['id'])->reference_voucher_id, null);
        $this->expectClose('Free-standing CN IN costs at the current running average (documented fallback)', (float) $fi->rate, $avgNow, 0.001);

        // ═══ Tamper rejection ═══════════════════════════════════════════════════
        $this->section('Server rejects a tampered Credit Note');
        $rejected = false;
        $keys = [];
        try {
            $bad = $inv('credit_note', $cust, $salesReturn, 10, 100, '2026-04-10', $saleId);
            foreach ($bad['lines'] as &$ln) {
                if ($ln['ledger_id'] === $gst->taxLedgerId('output', 'central')) {
                    $ln['amount'] = 1.00; // understate CGST (should be 90)
                } elseif ($ln['ledger_id'] === $cust->id) {
                    $ln['amount'] = 1091.00; // 1000 + 1 + 90 → keeps Dr==Cr so ONLY GST authority can catch it
                }
            }
            unset($ln);
            $screen->post($bad);
        } catch (ValidationException $e) {
            $rejected = true;
            $keys = array_keys($e->errors());
        }
        $this->expect('Tampered Credit Note rejected', $rejected, true);
        $this->expect('…by the GST authority (not the balance gate)', in_array('gst', $keys, true) && ! in_array('balance', $keys, true), true);

        // ═══ Bill-wise: a Credit Note Against Ref reduces the sale bill's pending ═
        $this->section('Bill-wise — Credit Note Against Ref reduces the sale bill');
        CompanyFeature::current()->update(['bill_by_bill' => true]);
        $cust2 = Ledger::create(['name' => 'Cust2 (MH)', 'group_id' => $gid('Sundry Debtors'), 'state' => 'Maharashtra', 'country' => 'India', 'maintain_bill_by_bill' => true]);
        $saleP = $inv('sales', $cust2, $salesLed, 10, 100, '2026-04-11'); // party Dr 1,180
        foreach ($saleP['lines'] as &$ln) {
            if ($ln['ledger_id'] === $cust2->id) {
                $ln['allocations'] = [['ref_type' => 'new', 'ref_name' => 'INV-2', 'amount' => 1180, 'due_date' => null]];
            }
        }
        unset($ln);
        $sale2 = $screen->post($saleP);
        $cnP = $inv('credit_note', $cust2, $salesReturn, 4, 100, '2026-04-12', $sale2['voucher']['id']); // party Cr 472
        foreach ($cnP['lines'] as &$ln) {
            if ($ln['ledger_id'] === $cust2->id) {
                $ln['allocations'] = [['ref_type' => 'against', 'ref_name' => 'INV-2', 'amount' => 472, 'due_date' => null]];
            }
        }
        unset($ln);
        $screen->post($cnP);
        $inv2 = collect(app(BillService::class)->bills([$cust2->id], null))->firstWhere('ref_name', 'INV-2');
        $this->expect('Bill INV-2 pending reduced to 708 (1,180 − 472) by the Credit Note', $inv2['pending'] ?? null, 70800);

        // ═══ Cost-centre enforced on a Note line ════════════════════════════════
        $this->section('Cost-centre — enforced on a Note line');
        CompanyFeature::current()->update(['cost_centres' => true]);
        $north = CostCentre::create(['name' => 'North']);
        $disc = Ledger::create(['name' => 'Discount Allowed', 'group_id' => $gid('Indirect Expenses'), 'cost_centres_applicable' => true, 'country' => 'India']);
        $acctCn = fn (array $allocs) => [
            'type' => 'credit_note', 'date' => '2026-04-13', 'party_ledger_id' => $cust->id,
            'lines' => [
                ['ledger_id' => $cust->id, 'dr_cr' => 'Cr', 'amount' => 100],
                array_merge(['ledger_id' => $disc->id, 'dr_cr' => 'Dr', 'amount' => 100], $allocs),
            ],
        ];
        $rej2 = false;
        try {
            $screen->post($acctCn([])); // no cost allocation on the cost-applicable line
        } catch (ValidationException $e) {
            $rej2 = in_array('costcentre', array_keys($e->errors()), true);
        }
        $this->expect('A cost-applicable Note line REQUIRES a cost allocation', $rej2, true);
        $okCc = false;
        try {
            $screen->post($acctCn(['cost_allocations' => [['cost_centre_id' => $north->id, 'amount' => 100]]]));
            $okCc = true;
        } catch (Throwable $e) {
        }
        $this->expect('…and posts once allocated', $okCc, true);
        CompanyFeature::current()->update(['bill_by_bill' => false, 'cost_centres' => false]);

        // ═══ VAT mirror (13% flat) ══════════════════════════════════════════════
        $this->section('VAT regime — the mirror-image reversal (13% flat)');
        CompanyFeature::current()->update(['gst' => false, 'vat' => true]);
        activeCompany()->update(['pan' => '301234567']);
        \App\Support\ActiveCompany::refresh();
        $vat = app(VatService::class);
        $vItem = StockItem::create(['name' => 'V-Widget', 'stock_group_id' => $grp->id, 'unit_id' => $unit->id, 'opening_qty' => 0, 'opening_rate' => 0, 'opening_value' => 0, 'gst_rate' => 13, 'costing_method' => 'weighted_average']);
        $vInv = function (string $type, Ledger $party, Ledger $rev, float $qty, float $rate, string $date, ?int $ref = null) use ($vat, $vItem, $godown) {
            $base = round($qty * $rate, 2);
            $comp = $vat->computeInvoiceTax($type, [['amount' => $base, 'rate' => (float) $vItem->gst_rate]]);
            $revSide = match ($type) { 'sales' => 'Cr', 'credit_note' => 'Dr', 'debit_note' => 'Cr', default => 'Dr' };
            $partySide = match ($type) { 'sales' => 'Dr', 'credit_note' => 'Cr', 'debit_note' => 'Dr', default => 'Cr' };
            $lines = [['ledger_id' => $rev->id, 'dr_cr' => $revSide, 'amount' => $base]];
            $totalP = (int) round($base * 100);
            foreach ($comp['lines'] as $tl) {
                $lines[] = ['ledger_id' => $tl['ledger_id'], 'dr_cr' => $tl['dr_cr'], 'amount' => $tl['amount']];
            }
            $totalP += $comp['tax_paise'];
            array_unshift($lines, ['ledger_id' => $party->id, 'dr_cr' => $partySide, 'amount' => $totalP / 100]);
            $p = ['type' => $type, 'date' => $date, 'party_ledger_id' => $party->id, 'lines' => $lines, 'items' => [['stock_item_id' => $vItem->id, 'godown_id' => $godown, 'qty' => $qty, 'rate' => $rate]]];
            if ($ref) {
                $p['reference_voucher_id'] = $ref;
            }

            return $p;
        };
        $vBuy = $screen->post($vInv('purchase', $supp, $purchLed, 10, 40, '2026-05-01'));
        $vSale = $screen->post($vInv('sales', $cust, $salesLed, 4, 100, '2026-05-02')); // base 400, VAT 52, party Dr 452
        $vsl = $legs($vSale['voucher']['id']);
        $this->expect('VAT Sale: Cr Output VAT 52', $vsl['Output VAT'] ?? null, ['side' => 'Cr', 'paise' => 5200]);
        $vCn = $screen->post($vInv('credit_note', $cust, $salesReturn, 1, 100, '2026-05-03', $vSale['voucher']['id'])); // base 100, VAT 13
        $vcl = $legs($vCn['voucher']['id']);
        $this->expect('VAT Credit Note: Dr Output VAT 13 (reversal)', $vcl['Output VAT'] ?? null, ['side' => 'Dr', 'paise' => 1300]);
        $this->expect('VAT Credit Note: Cr Cust 113 (tax-inclusive)', $vcl['Cust (MH)'] ?? null, ['side' => 'Cr', 'paise' => 11300]);
        $vci = $stkRow($vCn['voucher']['id']);
        $this->expectClose('VAT CN IN costed at original sale cost (40)', (float) $vci->rate, 40);
        // restore GST for anything after (teardown drops the DB anyway)
        CompanyFeature::current()->update(['gst' => true, 'vat' => false]);
    }

    private function section(string $t): void
    {
        $this->line('');
        $this->line('── '.$t.' '.str_repeat('─', max(0, 58 - strlen($t))));
    }

    private function expect(string $label, $actual, $expected): void
    {
        $pass = $actual === $expected;
        $this->line(($pass ? '  [PASS] ' : '  [FAIL] ').$label.' = '.$this->fmt($actual).($pass ? '' : ' (expected '.$this->fmt($expected).')'));
        $this->ok = $this->ok && $pass;
    }

    private function expectClose(string $label, $actual, $expected, float $tol = 0.005): void
    {
        $pass = is_numeric($actual) && abs((float) $actual - (float) $expected) < $tol;
        $this->line(($pass ? '  [PASS] ' : '  [FAIL] ').$label.' = '.$this->fmt($actual).($pass ? '' : ' (expected ~'.$this->fmt($expected).')'));
        $this->ok = $this->ok && $pass;
    }

    private function fmt($v): string
    {
        if (is_array($v)) {
            return json_encode($v);
        }

        return is_bool($v) ? ($v ? 'true' : 'false') : var_export($v, true);
    }
}
