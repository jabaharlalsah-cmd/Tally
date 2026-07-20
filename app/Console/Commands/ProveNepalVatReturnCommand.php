<?php

namespace App\Console\Commands;

use App\Console\Concerns\ResolvesActiveCompany;
use App\Livewire\VoucherScreen;
use App\Models\AccountGroup;
use App\Models\CompanyFeature;
use App\Models\Godown;
use App\Models\Ledger;
use App\Models\StockGroup;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\VatReturnFiling;
use App\Models\Voucher;
use App\Services\Vat\IrdFormat;
use App\Services\Vat\NepalVatReturnService;
use App\Services\VatService;
use App\Support\NepalDate;
use App\Support\VatReturnExport;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Phase 9B numeric proof — the Nepal VAT return (अनुसूची-१० / Schedule 10).
 *
 * Posts a known set of vouchers through the SAME VoucherScreen::post() every voucher
 * uses, generates the return, and asserts every box against the Government's own form
 * as catalogued in `_docs/ird-schemas/nepal-vat-return-fields.json`.
 *
 * Refuses to run if that artifact reference is absent — the exporter is only ever built
 * and proven against the real form, never a remembered one.
 */
class ProveNepalVatReturnCommand extends Command
{
    use ResolvesActiveCompany;
    protected $signature = 'zerobook:prove-nepal-vat-return {--keep : keep the nepalvattest tenant provisioned} {--company= : run in this company (slug or id); default = the throwaway tenant’s default company}';

    protected $description = 'Prove the Nepal VAT return: Schedule 10 boxes to the rupee, regime gate, debit-note direction, workflow exclusion, field conformance';

    private bool $ok = true;

    public function handle(): int
    {
        $fields = $this->loadArtifact();
        if ($fields === null) {
            return self::FAILURE;
        }

        $slug = 'nepalvattest';
        $provisioner = app(\App\Services\Tenancy\TenantProvisioner::class);
        try {
            $provisioner->teardown($slug);
            $provisioner->provision($slug, 'Nepal VAT Test Co', 'professional');

            Tenant::find($slug)->run(fn () => $this->runProof($fields));
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
        $this->info($this->ok ? 'ALL NEPAL VAT RETURN ASSERTIONS PASSED.' : 'NEPAL VAT RETURN ASSERTIONS FAILED.');

        return $this->ok ? self::SUCCESS : self::FAILURE;
    }

    /** The schema gate: no artifact, no proof. */
    private function loadArtifact(): ?array
    {
        $path = base_path('_docs/ird-schemas/nepal-vat-return-fields.json');
        if (! is_file($path)) {
            $this->error('Cannot prove the exporter: '.$path.' is missing.');
            $this->line('');
            $this->line('  Place the IRD artifact reference (extracted from the real Schedule 10 /');
            $this->line('  अनुसूची-१० of the VAT Rules 2053, or a screenshot set of the current portal');
            $this->line('  return form) at _docs/ird-schemas/nepal-vat-return-fields.json before running');
            $this->line('  this proof. Proving the exporter against a remembered form would be worthless.');

            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data) || ! isset($data['boxes'])) {
            $this->error('nepal-vat-return-fields.json is not a readable artifact reference (no `boxes`).');

            return null;
        }
        $this->line('Artifact: '.($data['_meta']['artifact'] ?? 'unknown'));
        $this->line('Channel : '.substr((string) ($data['_meta']['filing_channel'] ?? ''), 0, 60).'…');

        return $data;
    }

    private function runProof(array $fields): void
    {
        // Phase 12A — pin the throwaway tenant's default company as active before
        // any scoped model is touched (CLI has no session).
        if (! $this->resolveActiveCompany()) {
            throw new \RuntimeException('No default company in the throwaway tenant.');
        }

        $period = '2082-04'; // Shrawan 2082 → AD 2025-07-17 .. 2025-08-16
        [$from, $to] = NepalDate::periodRange($period);
        $screen = new VoucherScreen();

        // ═══ regime gate — before VAT is even switched on ════════════════════
        $this->section('Regime gate');
        CompanyFeature::current()->update(['gst' => false, 'vat' => false]);
        $this->expect('no regime → refuses, pointing at F11', $this->refusalMessage($period), 'No tax regime is configured — set your tax regime in F11 Features before generating returns');

        CompanyFeature::current()->update(['gst' => true, 'vat' => false]);
        activeCompany()->update(['gstin' => '27AAAAA0000A1Z5']);
        $this->expect('GST regime → refuses (this is not a Nepal VAT tenant)', $this->refusalMessage($period), 'This tenant is under GST regime, not Nepal VAT — generate GSTR-1 / GSTR-3B instead');

        // Switch to VAT, but with no PAN yet.
        CompanyFeature::current()->update(['gst' => false, 'vat' => true]);
        activeCompany()->update(['pan' => null]);
        \App\Support\ActiveCompany::refresh();
        $this->expect('VAT regime, no PAN → refuses', $this->refusalMessage($period), 'Company PAN not configured — set it in F11 Features before generating the VAT return');

        activeCompany()->update(['pan' => '301234567']);
        \App\Support\ActiveCompany::refresh();

        // ═══ post the scenario ══════════════════════════════════════════════
        $gid = fn (string $n) => AccountGroup::where('name', $n)->value('id');
        $godown = Godown::where('name', 'Main Location')->value('id');
        $unit = Unit::create(['name' => 'Numbers', 'symbol' => 'Nos', 'decimal_places' => 0]);
        $grp = StockGroup::create(['name' => 'Finished Goods']);
        $item = StockItem::create([
            'name' => 'Widget', 'stock_group_id' => $grp->id, 'unit_id' => $unit->id,
            'opening_qty' => 0, 'opening_rate' => 0, 'opening_value' => 0,
            'gst_rate' => 13, 'hsn_sac' => '8471', 'costing_method' => 'weighted_average',
        ]);

        $cust = Ledger::create(['name' => 'Customer', 'group_id' => $gid('Sundry Debtors'), 'country' => 'Nepal']);
        $supp = Ledger::create(['name' => 'Supplier', 'group_id' => $gid('Sundry Creditors'), 'country' => 'Nepal']);
        $salesLed = Ledger::create(['name' => 'Sales @13', 'group_id' => $gid('Sales Accounts'), 'gst_rate' => 13, 'country' => 'Nepal']);
        $purchLed = Ledger::create(['name' => 'Purchase @13', 'group_id' => $gid('Purchase Accounts'), 'gst_rate' => 13, 'country' => 'Nepal']);
        $salesReturn = Ledger::where('name', 'Sales Return')->first();

        $vat = app(VatService::class);
        $inv = function (string $type, Ledger $party, Ledger $rev, float $qty, float $rate, string $date, ?int $ref = null) use ($vat, $item, $godown) {
            $base = round($qty * $rate, 2);
            $comp = $vat->computeInvoiceTax($type, [['amount' => $base, 'rate' => (float) $item->gst_rate]]);
            $revSide = match ($type) { 'sales' => 'Cr', 'credit_note' => 'Dr', 'debit_note' => 'Cr', default => 'Dr' };
            $partySide = match ($type) { 'sales' => 'Dr', 'credit_note' => 'Cr', 'debit_note' => 'Dr', default => 'Cr' };
            $lines = [['ledger_id' => $rev->id, 'dr_cr' => $revSide, 'amount' => $base]];
            $totalP = (int) round($base * 100);
            foreach ($comp['lines'] as $tl) {
                $lines[] = ['ledger_id' => $tl['ledger_id'], 'dr_cr' => $tl['dr_cr'], 'amount' => $tl['amount']];
            }
            $totalP += $comp['tax_paise'];
            array_unshift($lines, ['ledger_id' => $party->id, 'dr_cr' => $partySide, 'amount' => $totalP / 100]);
            $p = ['type' => $type, 'date' => $date, 'party_ledger_id' => $party->id, 'lines' => $lines,
                'items' => [['stock_item_id' => $item->id, 'godown_id' => $godown, 'qty' => $qty, 'rate' => $rate]]];
            if ($ref) {
                $p['reference_voucher_id'] = $ref;
            }

            return $p;
        };

        // Dates INSIDE Shrawan 2082 (AD 2025-07-17 .. 2025-08-16).
        // Sale 100,000 → output VAT 13,000 ; Purchase 50,000 → input VAT 6,500 ;
        // Credit Note 10,000 → output VAT reversal 1,300.
        $screen->post($inv('sales', $cust, $salesLed, 100, 1000, '2025-07-20'));
        $screen->post($inv('purchase', $supp, $purchLed, 50, 1000, '2025-07-22'));
        $saleId = Voucher::where('type', 'sales')->orderBy('id')->value('id');
        $screen->post($inv('credit_note', $cust, $salesReturn, 10, 1000, '2025-08-05', $saleId));

        // Phase 8B workflow vouchers in the SAME period — must never contribute.
        $wf = fn (string $type, Ledger $party, float $qty) => [
            'type' => $type, 'date' => '2025-07-25', 'party_ledger_id' => $party->id,
            'items' => [['stock_item_id' => $item->id, 'godown_id' => $godown, 'qty' => $qty, 'rate' => 1000]],
        ];
        $screen->post($wf('sales_order', $cust, 10));
        $screen->post($wf('delivery_note', $cust, 5));

        $svc = app(NepalVatReturnService::class);
        $r = $svc->return($period);
        $b = $r['boxes'];
        $encoded = VatReturnExport::encode($r);

        // ═══ header ═════════════════════════════════════════════════════════
        $this->section('Header (page 70)');
        $this->expect('form is अनुसूची-१०, not "Form 07"', $r['form']['id'], 'अनुसूची-१०');
        $this->expect('title', $r['form']['title_np'], 'मूल्य अभिवृद्धि कर विवरण फाराम');
        $this->expect('legal basis is Rule 26(1)', $r['form']['legal_basis_np'], 'नियम २६ को उपनियम (१) सँग सम्बन्धित');
        $this->expect('PAN (करदाता दर्ता नम्बर), 9 digits', $r['header']['pan'], '301234567');
        $this->expect('BS month is Shrawan', $r['header']['month_np'], 'साउन');
        $this->expect('…which is BS calendar month 4', $r['header']['month_bs'], 4);
        $this->expect('…but fiscal month index 1 (the form lists साउन first)', $r['header']['fiscal_month_index'], 1);
        $this->expect('fiscal year', $r['header']['fiscal_year'], '2082/83');
        $this->expect('Gregorian window of Shrawan 2082', [$r['header']['gregorian_from'], $r['header']['gregorian_to']], ['2025-07-17', '2025-08-16']);

        // ═══ 1. विक्री ══════════════════════════════════════════════════════
        $this->section('1. विक्री (Sales) — page 71');
        $this->expect('1.1 label', $b['1.1']['np'], 'कर लाग्ने विक्री');
        $this->expect('1.1 कारोवार मूल्य = 100,000 − 10,000 credit note = 90,000', $b['1.1']['value'], 90000);
        $this->expect('1.1 डेविट (output VAT) = 13,000 − 1,300 = 11,700', $b['1.1']['debit'], 11700);
        $this->expect('1.1 has NO credit cell (sales create no purchase credit)', array_key_exists('credit', $b['1.1']), false);
        $this->expect('1.2 निर्यात = 0 (exports not classified)', $b['1.2']['value'], 0);
        $this->expect('1.3 छुट विक्री = 0 (exempt sales not classified)', $b['1.3']['value'], 0);
        $this->expect('1.2/1.3 carry only the value column', [array_key_exists('debit', $b['1.2']), array_key_exists('debit', $b['1.3'])], [false, false]);

        // ═══ 2. खरिद।पैठारी ═════════════════════════════════════════════════
        $this->section('2. खरिद।पैठारी (Purchase / Import) — page 71');
        $this->expect('2.1 label', $b['2.1']['np'], 'कर लाग्ने खरिद');
        $this->expect('2.1 कारोवार मूल्य = 50,000', $b['2.1']['value'], 50000);
        $this->expect('2.1 क्रेडिट (input VAT) = 6,500', $b['2.1']['credit'], 6500);
        $this->expect('2.1 has NO debit cell', array_key_exists('debit', $b['2.1']), false);
        $this->expect('2.2 कर लाग्ने पैठारी = 0 (imports not distinguished)', $b['2.2']['value'], 0);
        $this->expect('2.3 / 2.4 exempt purchase & import = 0', [$b['2.3']['value'], $b['2.4']['value']], [0, 0]);

        // ═══ 3 / 4 / 5 / 6 / 7 ══════════════════════════════════════════════
        $this->section('3–7. अन्य / जम्मा / डेविट—क्रेडिट / net (pages 71–72)');
        $this->expect('3.1 अन्य थपघट = 0 / 0', [$b['3.1']['credit'], $b['3.1']['debit']], [0, 0]);
        $this->expect('4 जम्मा क्रेडिट = 6,500', $b['4']['credit'], 6500);
        $this->expect('4 जम्मा डेविट = 11,700', $b['4']['debit'], 11700);
        $this->expect('5 डेविट—क्रेडिट = 11,700 − 6,500 = 5,200', $b['5']['amount'], 5200);
        $this->expect('5 sign is + (payable)', $b['5']['sign'], '+');
        $this->expect('6 गत महिनाको बाँकी क्रेडिट = 0 (no carry-forward supplied)', $b['6']['amount'], 0);
        $this->expect('7 कुल तिर्नु पर्ने कर (५—६) = 5,200', $b['7']['amount'], 5200);
        $this->expect('7 label carries the form\'s own formula', $b['7']['np'], 'कुल तिर्नु पर्ने कर रु. (५—६)');

        // ═══ box 9 options come from the form ═══════════════════════════════
        $this->section('8–10. refund claim / payment (taxpayer actions)');
        $this->expect('8 कर फिर्ता माग = 0', $b['8']['amount'], 0);
        $this->expect('9 nothing ticked', $b['9']['selected'], null);
        $this->expect('9 has the form\'s four options', array_keys($b['9']['options']), ['unadjusted_four_months', 'regular_exporter', 'excess_deposit_four_months', 'other']);
        $this->expect('9 "regular exporter" label', $b['9']['options']['regular_exporter']['np'], 'नियमित निर्यातकर्ता');
        $this->expect('10 जम्मा भुक्तानी = 0, भौचर नं. blank', [$b['10']['amount'], $b['10']['voucher_no']], [0, null]);

        // ═══ 11. document counts ════════════════════════════════════════════
        $this->section('11. कर अवधिमा प्रयोग गरिएका कागजातहरुको विवरण — page 72');
        $rows = $b['11']['rows'];
        $this->expect('कुल खरिद बिजक संख्या = 1', $rows['purchase_invoices']['count'], 1);
        $this->expect('क्रेडिट नोट संख्या = 1', $rows['credit_notes']['count'], 1);
        $this->expect('डेविट नोट संख्या = 0', $rows['debit_notes']['count'], 0);
        $this->expect('विक्री बिजक जम्मा संख्या = 1', $rows['sales_invoices']['count'], 1);
        $this->expect('क्रेडिट/डेविट एडभाइस = 0 (ZeroBook has no advice document)', [$rows['credit_advices']['count'], $rows['debit_advices']['count']], [0, 0]);

        // ═══ Debit Note reduces INPUT VAT, never output ═════════════════════
        $this->section('A Debit Note (purchase return) reduces INPUT VAT, not output');
        $purchaseId = Voucher::where('type', 'purchase')->orderBy('id')->value('id');
        $screen->post($inv('debit_note', $supp, $purchLed, 4, 1000, '2025-08-10', $purchaseId)); // 4,000 → VAT 520
        $r2 = $svc->return($period);
        $b2 = $r2['boxes'];
        $this->expect('2.1 value falls 50,000 → 46,000', $b2['2.1']['value'], 46000);
        $this->expect('2.1 credit (input VAT) falls 6,500 → 5,980', $b2['2.1']['credit'], 5980);
        $this->expect('1.1 debit (output VAT) is UNTOUCHED at 11,700', $b2['1.1']['debit'], 11700);
        $this->expect('1.1 value is UNTOUCHED at 90,000', $b2['1.1']['value'], 90000);
        $this->expect('5 डेविट—क्रेडिट rises to 11,700 − 5,980 = 5,720', $b2['5']['amount'], 5720);
        $this->expect('डेविट नोट संख्या is now 1', $b2['11']['rows']['debit_notes']['count'], 1);

        // ═══ carry-forward (box 6) flows into box 7 ═════════════════════════
        $this->section('Carry-forward credit (box 6) reduces box 7');
        $r3 = $svc->return($period, 1720);
        $this->expect('6 = 1,720', $r3['boxes']['6']['amount'], 1720);
        $this->expect('7 = 5,720 − 1,720 = 4,000', $r3['boxes']['7']['amount'], 4000);
        $r4 = $svc->return($period, 9999);
        $this->expect('an excess carry-forward makes box 7 negative', $r4['boxes']['7']['amount'] < 0, true);
        $this->expect('…and the form\'s (—) sign is shown', $r4['boxes']['7']['sign'], '—');

        // ═══ workflow vouchers excluded ═════════════════════════════════════
        $this->section('Phase 8B workflow vouchers never reach the return');
        $this->expect('a Sales Order / Delivery Note added no taxable sales', $b['1.1']['value'], 90000);
        $this->expect('…and no ledger entry exists on any workflow voucher', \App\Models\VoucherEntry::whereIn(
            'voucher_id', Voucher::whereIn('type', Voucher::INVENTORY_WORKFLOW_TYPES)->pluck('id')
        )->count(), 0);
        $this->expect('…and no workflow voucher is counted in box 11', $rows['sales_invoices']['count'] + $rows['purchase_invoices']['count'], 2);
        $this->expect('their display numbers appear nowhere in the document', str_contains($encoded, 'SO-1') || str_contains($encoded, 'DELN-1'), false);

        // ═══ whole-rupee rounding rule (page 71) ════════════════════════════
        $this->section('Whole-rupee rounding (the form\'s own instruction, p.71)');
        $this->expect('paise round to whole rupees', IrdFormat::rupeesFromPaise(1234567), 12346);
        $this->expect('a non-zero amount below Re.1 becomes Re.1', IrdFormat::rupeesFromPaise(40), 1);
        $this->expect('…and its negative becomes −1', IrdFormat::rupeesFromPaise(-40), -1);
        $this->expect('a genuinely zero amount stays 0', IrdFormat::rupeesFromPaise(0), 0);
        $this->expect('every money box in the document is an integer', $this->nonIntegerBoxes($b), []);

        // ═══ field conformance against the artifact ═════════════════════════
        $this->section('Field conformance — every box traced to the IRD artifact');
        $this->expect('every generated box exists on the form, with the form\'s exact label', $this->conformanceGaps($r, $fields), []);
        $this->expect('every box the artifact lists is present in the document', $this->missingBoxes($r, $fields), []);

        // ═══ preview cannot drift from the document ═════════════════════════
        $this->section('Preview mirrors the generated document');
        $p = $svc->preview($period);
        $this->expect('taxable sales', $p['taxable_sales'], $b2['1.1']['value']);
        $this->expect('output VAT', $p['output_vat'], $b2['1.1']['debit']);
        $this->expect('input VAT', $p['input_vat'], $b2['2.1']['credit']);
        $this->expect('net VAT', $p['net_vat'], $b2['7']['amount']);
        $this->expect('is payable', $p['is_payable'], true);
        $this->expect('document counts', $p['documents']['sales_invoices'], 1);

        // ═══ submission-ref log ═════════════════════════════════════════════
        $this->section('Submission reference log');
        $this->expect('not filed yet', VatReturnFiling::isFiled($period), false);
        VatReturnFiling::record($period, '07123456789');
        $this->expect('filed after recording the submission ref', VatReturnFiling::isFiled($period), true);
        $row = VatReturnFiling::where('period', $period)->first();
        $this->expect('submission ref persisted', $row->submission_ref, '07123456789');
        $this->expect('filed_at stamped', $row->filed_at !== null, true);
        VatReturnFiling::record($period, '07999999999');
        $this->expect('re-filing the same period updates in place (one row)', VatReturnFiling::where('period', $period)->count(), 1);
    }

    /** Capture the refusal message the service throws, or '' if it did not refuse. */
    private function refusalMessage(string $period): string
    {
        try {
            app(NepalVatReturnService::class)->return($period);
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }

        return '';
    }

    /** Any money/count box carrying a non-integer (the form takes whole rupees only). */
    private function nonIntegerBoxes(array $boxes): array
    {
        $bad = [];
        foreach ($boxes as $key => $box) {
            foreach (['value', 'credit', 'debit', 'amount'] as $cell) {
                if (array_key_exists($cell, $box) && ! is_int($box[$cell])) {
                    $bad[] = $key.'.'.$cell.' = '.var_export($box[$cell], true);
                }
            }
        }

        return $bad;
    }

    /** Every generated box must exist on the artifact, with the artifact's exact Devanagari label. */
    private function conformanceGaps(array $return, array $fields): array
    {
        $byBox = [];
        foreach ($fields['boxes'] as $f) {
            $byBox[$f['box']] = $f;
        }

        $gaps = [];
        foreach ($return['boxes'] as $key => $box) {
            if (! isset($byBox[$key])) {
                $gaps[] = "box {$key}: not present on the IRD form";

                continue;
            }
            if (($box['np'] ?? null) !== $byBox[$key]['np']) {
                $gaps[] = "box {$key}: label “{$box['np']}” ≠ form's “{$byBox[$key]['np']}”";
            }
            // The enterable cells must match the form's white/shaded layout exactly.
            foreach (['value', 'credit', 'debit'] as $cell) {
                $onForm = in_array($cell, $byBox[$key]['cells'] ?? [], true);
                $inDoc = array_key_exists($cell, $box);
                if ($onForm !== $inDoc) {
                    $gaps[] = "box {$key}: cell '{$cell}' ".($inDoc ? 'emitted but blocked on the form' : 'required by the form but missing');
                }
            }
        }

        return $gaps;
    }

    /** Every box the artifact lists must appear in the generated document. */
    private function missingBoxes(array $return, array $fields): array
    {
        $missing = [];
        foreach ($fields['boxes'] as $f) {
            if (! array_key_exists($f['box'], $return['boxes'])) {
                $missing[] = 'box '.$f['box'].' ('.$f['np'].')';
            }
        }

        return $missing;
    }

    private function section(string $t): void
    {
        $this->line('');
        $this->line('── '.$t.' '.str_repeat('─', max(0, 60 - mb_strlen($t))));
    }

    private function expect(string $label, $actual, $expected): void
    {
        $pass = $actual === $expected;
        $this->line(($pass ? '  [PASS] ' : '  [FAIL] ').$label.' = '.$this->fmt($actual).($pass ? '' : ' (expected '.$this->fmt($expected).')'));
        $this->ok = $this->ok && $pass;
    }

    private function fmt($v): string
    {
        if (is_array($v)) {
            return $v === [] ? '[] (none)' : json_encode($v, JSON_UNESCAPED_UNICODE);
        }

        return is_bool($v) ? ($v ? 'true' : 'false') : var_export($v, true);
    }
}
