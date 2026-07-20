<?php

namespace App\Console\Commands;

use App\Console\Concerns\ResolvesActiveCompany;
use App\Console\Concerns\ProvesGstReturns;
use App\Livewire\VoucherScreen;
use App\Models\AccountGroup;
use App\Models\CompanyFeature;
use App\Models\Godown;
use App\Models\GstReturnFiling;
use App\Models\Ledger;
use App\Models\StockGroup;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Models\Unit;
use App\Services\Gst\GstnFormat;
use App\Services\Gst\GstReturnService;
use App\Services\GstService;
use App\Services\Tenancy\TenantProvisioner;
use App\Support\GstReturnExport;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Phase 9A numeric proof — GSTR-1.
 *
 * Posts a known set of vouchers through the SAME VoucherScreen::post() every voucher
 * uses, exports the return, and asserts the JSON to the paise against the schema
 * derived from the Government's own Returns Offline Tool v3.2.4.
 *
 * Refuses to run if `_docs/gstn-schemas/gstr1-schema.json` is absent — the exporter is
 * only ever built and proven against the real schema, never a remembered one.
 */
class ProveGstr1Command extends Command
{
    use ProvesGstReturns;
    use ResolvesActiveCompany;

    protected $signature = 'zerobook:prove-gstr1 {--keep : keep the gstr1test tenant provisioned} {--company= : run in this company (slug or id); default = the throwaway tenant’s default company}';

    protected $description = 'Prove the GSTR-1 JSON export: B2B / B2CL / B2CS / CDNR / HSN / doc_issue to the paise, schema conformance, workflow-voucher exclusion';

    private bool $ok = true;

    public function handle(TenantProvisioner $provisioner): int
    {
        $schema = $this->loadSchema('gstr1-schema.json');
        if ($schema === null) {
            return self::FAILURE;
        }

        $slug = 'gstr1test';
        try {
            $provisioner->teardown($slug);
            $provisioner->provision($slug, 'GSTR1 Test Co', 'professional');

            Tenant::find($slug)->run(fn () => $this->runProof($schema));
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
        $this->info($this->ok ? 'ALL GSTR-1 ASSERTIONS PASSED.' : 'GSTR-1 ASSERTIONS FAILED.');

        return $this->ok ? self::SUCCESS : self::FAILURE;
    }

    private function runProof(array $schema): void
    {
        // Phase 12A — pin the throwaway tenant's default company as active before
        // any scoped model is touched (CLI has no session).
        if (! $this->resolveActiveCompany()) {
            throw new \RuntimeException('No default company in the throwaway tenant.');
        }

        $period = '042026'; // April 2026 → fp "042026" (MMYYYY)
        $screen = new VoucherScreen();

        // Company: Maharashtra (state code 27), GST on.
        CompanyFeature::current()->update(['gst' => true, 'vat' => false]);
        activeCompany()->update(['state' => 'Maharashtra', 'gstin' => '27AAAAA0000A1Z5']);
        $gst = app(GstService::class);

        $gid = fn (string $n) => AccountGroup::where('name', $n)->value('id');
        $godown = Godown::where('name', 'Main Location')->value('id');
        $unit = Unit::create(['name' => 'Numbers', 'symbol' => 'Nos', 'decimal_places' => 0]);
        $grp = StockGroup::create(['name' => 'Finished Goods']);
        $widget = StockItem::create([
            'name' => 'Widget', 'stock_group_id' => $grp->id, 'unit_id' => $unit->id,
            'opening_qty' => 0, 'opening_rate' => 0, 'opening_value' => 0,
            'gst_rate' => 18, 'hsn_sac' => '8471', 'costing_method' => 'weighted_average',
        ]);

        // Parties: one registered (B2B), two unregistered (B2CS intra / B2CL inter).
        $regBuyer = Ledger::create(['name' => 'Reg Buyer (MH)', 'group_id' => $gid('Sundry Debtors'), 'state' => 'Maharashtra', 'country' => 'India', 'gstin' => '27BBBBB1111B1Z5']);
        $unregLocal = Ledger::create(['name' => 'Walk-in (MH)', 'group_id' => $gid('Sundry Debtors'), 'state' => 'Maharashtra', 'country' => 'India']);
        $unregFar = Ledger::create(['name' => 'Walk-in (KA)', 'group_id' => $gid('Sundry Debtors'), 'state' => 'Karnataka', 'country' => 'India']);
        $suppMh = Ledger::create(['name' => 'Supplier (MH)', 'group_id' => $gid('Sundry Creditors'), 'state' => 'Maharashtra', 'country' => 'India', 'gstin' => '27CCCCC2222C1Z5']);

        $salesLed = Ledger::create(['name' => 'Sales @18', 'group_id' => $gid('Sales Accounts'), 'gst_rate' => 18, 'country' => 'India']);
        $purchLed = Ledger::create(['name' => 'Purchase @18', 'group_id' => $gid('Purchase Accounts'), 'gst_rate' => 18, 'country' => 'India']);
        $salesReturn = Ledger::where('name', 'Sales Return')->first();

        $inv = $this->invoiceFactory($gst, $widget, $godown);

        // ── the scenario ────────────────────────────────────────────────────
        // SALE-1  B2B   registered, intra : 100 × 1000 = 100,000 + 18% → val 118,000
        // SALE-2  B2CS  unregistered, intra: 100 × 1000 = 100,000 + 18% → val 118,000
        // SALE-3  B2CL  unregistered, inter: 300 × 1000 = 300,000 + 18% → val 354,000
        // CRNT-1  CDNR  credit note to the registered buyer: 10 × 1000 = 10,000 + 18%
        $screen->post($inv('sales', $regBuyer, $salesLed, 100, 1000, '2026-04-05'));
        $screen->post($inv('sales', $unregLocal, $salesLed, 100, 1000, '2026-04-07'));
        $screen->post($inv('sales', $unregFar, $salesLed, 300, 1000, '2026-04-09'));
        $sale1Id = \App\Models\Voucher::where('type', 'sales')->orderBy('id')->value('id');
        $screen->post($inv('credit_note', $regBuyer, $salesReturn, 10, 1000, '2026-04-20', $sale1Id));

        // A purchase, so the period also has inward tax (proved by prove-gstr3b).
        $screen->post($inv('purchase', $suppMh, $purchLed, 50, 1000, '2026-04-03'));

        // ── Phase 8B workflow vouchers in the SAME period — must never appear ──
        $wf = fn (string $type, Ledger $party, float $qty) => [
            'type' => $type, 'date' => '2026-04-11', 'party_ledger_id' => $party->id,
            'items' => [['stock_item_id' => $widget->id, 'godown_id' => $godown, 'qty' => $qty, 'rate' => 1000]],
        ];
        $screen->post($wf('sales_order', $regBuyer, 10));
        $screen->post($wf('purchase_order', $suppMh, 7));
        $screen->post($wf('delivery_note', $regBuyer, 5));

        // ── generate ────────────────────────────────────────────────────────
        $svc = app(GstReturnService::class);
        $j = $svc->gstr1($period);
        $encoded = GstReturnExport::encode($j);

        // ═══ header ═════════════════════════════════════════════════════════
        $this->section('Header');
        $this->expect('gstin is the company GSTIN', $j['gstin'], '27AAAAA0000A1Z5');
        $this->expect('fp is the period in MMYYYY (not YYYYMM)', $j['fp'], '042026');
        $this->expect('version stamp', $j['version'], 'GST3.2.4');
        $this->expect('hash placeholder', $j['hash'], 'hash');
        $this->expect('the documents-issued key is doc_issue (NOT "docs")', array_key_exists('doc_issue', $j) && ! array_key_exists('docs', $j), true);
        $this->expect('hsn is bifurcated for fp ≥ 052025', array_keys($j['hsn']), ['hsn_b2b', 'hsn_b2c']);

        // ═══ B2B ════════════════════════════════════════════════════════════
        $this->section('B2B — sale to a registered buyer (invoice level)');
        $this->expect('one B2B counterparty', count($j['b2b']), 1);
        $b2b = $j['b2b'][0];
        $this->expect('ctin is the recipient GSTIN', $b2b['ctin'], '27BBBBB1111B1Z5');
        $this->expect('one invoice under it', count($b2b['inv']), 1);
        $i = $b2b['inv'][0];
        $this->expect('inum', $i['inum'], 'SALE-1');
        $this->expect('idt is DD-MM-YYYY', $i['idt'], '05-04-2026');
        $this->expectClose('val = 100,000 + 18% = 118,000', $i['val'], 118000);
        $this->expect('pos from the recipient GSTIN prefix', $i['pos'], '27');
        $this->expect('rchrg', $i['rchrg'], 'N');
        $this->expect('inv_typ', $i['inv_typ'], 'R');
        $this->expect('one rate slab', count($i['itms']), 1);
        $it = $i['itms'][0];
        $this->expect('itms.num = rate×100+1 (the tool\'s convention)', $it['num'], 1801);
        $this->expectClose('itm_det.txval', $it['itm_det']['txval'], 100000);
        $this->expectClose('itm_det.rt', $it['itm_det']['rt'], 18);
        $this->expectClose('intra-state → camt 9,000', $it['itm_det']['camt'], 9000);
        $this->expectClose('intra-state → samt 9,000', $it['itm_det']['samt'], 9000);
        $this->expect('…and NO iamt on an intra-state line', array_key_exists('iamt', $it['itm_det']), false);
        $this->expectClose('csamt always present', $it['itm_det']['csamt'], 0);

        // ═══ B2CL ═══════════════════════════════════════════════════════════
        $this->section('B2CL — unregistered, inter-state, above the period threshold');
        $this->expectClose('threshold for 042026 is 100,000 (changed from 250,000 in Aug-2024)', GstnFormat::b2clThreshold($period), 100000);
        $this->expect('one POS group', count($j['b2cl']), 1);
        $this->expect('pos = Karnataka (29)', $j['b2cl'][0]['pos'], '29');
        $bi = $j['b2cl'][0]['inv'][0];
        $this->expect('inum', $bi['inum'], 'SALE-3');
        $this->expectClose('val = 300,000 + 18% = 354,000', $bi['val'], 354000);
        $this->expectClose('itm_det.txval', $bi['itms'][0]['itm_det']['txval'], 300000);
        $this->expectClose('inter-state → iamt 54,000', $bi['itms'][0]['itm_det']['iamt'], 54000);
        $this->expect('…and NO camt/samt on an inter-state line', array_key_exists('camt', $bi['itms'][0]['itm_det']), false);

        // ═══ B2CS ═══════════════════════════════════════════════════════════
        $this->section('B2CS — unregistered, intra-state (rate/POS summary)');
        $this->expect('one summary row', count($j['b2cs']), 1);
        $cs = $j['b2cs'][0];
        $this->expect('sply_ty', $cs['sply_ty'], 'INTRA');
        $this->expect('typ (other than e-commerce)', $cs['typ'], 'OE');
        $this->expect('pos', $cs['pos'], '27');
        $this->expectClose('rt', $cs['rt'], 18);
        $this->expectClose('txval 100,000', $cs['txval'], 100000);
        $this->expectClose('camt 9,000', $cs['camt'], 9000);
        $this->expectClose('samt 9,000', $cs['samt'], 9000);
        $this->expect('no iamt on an INTRA row', array_key_exists('iamt', $cs), false);

        // ═══ CDNR ═══════════════════════════════════════════════════════════
        $this->section('CDNR — credit note to the registered buyer');
        $this->expect('one counterparty', count($j['cdnr']), 1);
        $nt = $j['cdnr'][0]['nt'][0];
        $this->expect('ctin', $j['cdnr'][0]['ctin'], '27BBBBB1111B1Z5');
        $this->expect('cname is stripped from cdnr (the tool removes it)', array_key_exists('cname', $j['cdnr'][0]), false);
        $this->expect('ntty = C (credit note)', $nt['ntty'], 'C');
        $this->expect('nt_num', $nt['nt_num'], 'CRNT-1');
        $this->expect('nt_dt', $nt['nt_dt'], '20-04-2026');
        $this->expectClose('val = 10,000 + 18% = 11,800', $nt['val'], 11800);
        $this->expect('CDN is delinked — no inum on the note', array_key_exists('inum', $nt), false);
        $this->expectClose('itm_det.txval 10,000', $nt['itms'][0]['itm_det']['txval'], 10000);
        $this->expectClose('camt 900', $nt['itms'][0]['itm_det']['camt'], 900);
        $this->expectClose('samt 900', $nt['itms'][0]['itm_det']['samt'], 900);
        $this->expect('no CDNUR rows (the note is to a registered party)', count($j['cdnur']), 0);

        // ═══ HSN ════════════════════════════════════════════════════════════
        $this->section('HSN — bifurcated B2B / B2C summary');
        $this->expect('one hsn_b2b row', count($j['hsn']['hsn_b2b']), 1);
        $hb = $j['hsn']['hsn_b2b'][0];
        $this->expect('hsn_sc', $hb['hsn_sc'], '8471');
        $this->expect('uqc is the SHORT official code', $hb['uqc'], 'NOS');
        $this->expectClose('qty = 100 sold − 10 returned = 90', $hb['qty'], 90);
        $this->expectClose('txval = 100,000 − 10,000 = 90,000', $hb['txval'], 90000);
        $this->expectClose('camt = 9,000 − 900 = 8,100', $hb['camt'], 8100);
        $this->expectClose('samt = 8,100', $hb['samt'], 8100);
        $this->expectClose('val = txval + all tax = 106,200', $hb['val'], 106200);

        $this->expect('one hsn_b2c row (both B2C sales share HSN+UQC+rate)', count($j['hsn']['hsn_b2c']), 1);
        $hc = $j['hsn']['hsn_b2c'][0];
        $this->expectClose('qty = 100 (intra) + 300 (inter) = 400', $hc['qty'], 400);
        $this->expectClose('txval 400,000', $hc['txval'], 400000);
        $this->expectClose('iamt 54,000 (from the inter-state sale)', $hc['iamt'], 54000);
        $this->expectClose('camt 9,000 (from the intra-state sale)', $hc['camt'], 9000);
        $this->expectClose('an HSN row totals BOTH kinds of tax (not mutually exclusive)', $hc['samt'], 9000);
        $this->expectClose('val 472,000', $hc['val'], 472000);

        // ═══ doc_issue ══════════════════════════════════════════════════════
        $this->section('doc_issue — document number ranges (Table 13)');
        $det = $j['doc_issue']['doc_det'];
        $this->expect('two document natures issued (invoices + credit notes)', count($det), 2);
        $this->expect('doc_num 1 = Invoices for outward supply', $det[0]['doc_num'], 1);
        $d0 = $det[0]['docs'][0];
        $this->expect('from', $d0['from'], 'SALE-1');
        $this->expect('to', $d0['to'], 'SALE-3');
        $this->expect('totnum', $d0['totnum'], 3);
        $this->expect('cancel (no gaps)', $d0['cancel'], 0);
        $this->expect('net_issue', $d0['net_issue'], 3);
        $this->expect('doc_num 5 = Credit Note', $det[1]['doc_num'], 5);
        $this->expect('one credit note issued', $det[1]['docs'][0]['net_issue'], 1);
        $this->expect('no doc_det for a Purchase (we did not issue it)', collect($det)->pluck('doc_num')->all(), [1, 5]);

        // ═══ workflow vouchers excluded ═════════════════════════════════════
        $this->section('Phase 8B workflow vouchers never reach the return');
        $this->expect('a Sales Order does not appear anywhere in the JSON', str_contains($encoded, 'SO-1'), false);
        $this->expect('a Purchase Order does not appear anywhere in the JSON', str_contains($encoded, 'PO-1'), false);
        $this->expect('a Delivery Note does not appear anywhere in the JSON', str_contains($encoded, 'DELN-1'), false);
        $this->expectClose('…and the Delivery Note\'s 5 units did NOT inflate the HSN qty (90 + 400)', $hb['qty'] + $hc['qty'], 490);

        // ═══ rounding ═══════════════════════════════════════════════════════
        $this->section('Numeric precision');
        $bad = $this->roundingViolations($j);
        $this->expect('every money 2dp, every rate 2dp, every qty 4dp', $bad, []);

        // ═══ schema conformance ═════════════════════════════════════════════
        $this->section('Conformance to the GSTN-derived schema');
        $missing = $this->conformanceGaps($j, $schema);
        $this->expect('every REQUIRED key of the schema is present, with the right nested shape', $missing, []);

        // ═══ preview cannot drift from the JSON ═════════════════════════════
        $this->section('Preview mirrors the generated JSON');
        $p = $svc->preview($period)['gstr1'];
        $this->expect('B2B invoice count', $p['b2b_invoices'], 1);
        $this->expect('B2CL invoice count', $p['b2cl_invoices'], 1);
        $this->expect('B2CS row count', $p['b2cs_rows'], 1);
        $this->expect('CDNR note count', $p['cdnr_notes'], 1);
        $this->expect('HSN row count', $p['hsn_rows'], 2);
        $this->expectClose('total taxable = 100k + 300k + 100k − 10k = 490,000', $p['total_taxable'], 490000);
        $this->expectClose('total tax = 18k + 54k + 18k − 1.8k = 88,200', $p['total_tax'], 88200);

        // ═══ ARN log ════════════════════════════════════════════════════════
        $this->section('ARN filing log');
        $this->expect('not filed yet', GstReturnFiling::isFiled($period, 'gstr1'), false);
        GstReturnFiling::record($period, 'gstr1', 'AA1234567890ABC');
        $this->expect('filed after recording the ARN', GstReturnFiling::isFiled($period, 'gstr1'), true);
        $row = GstReturnFiling::where('period', $period)->where('return_type', 'gstr1')->first();
        $this->expect('ARN persisted', $row->arn, 'AA1234567890ABC');
        $this->expect('filed_at stamped', $row->filed_at !== null, true);
        GstReturnFiling::record($period, 'gstr1', 'AA9999999999XYZ');
        $this->expect('re-filing the same period updates in place (one row)', GstReturnFiling::where('period', $period)->where('return_type', 'gstr1')->count(), 1);

        // ═══ missing GSTIN refusal (last — it mutates the company) ═══════════
        $this->section('Refusal without a company GSTIN');
        activeCompany()->update(['gstin' => null]);
        \App\Support\ActiveCompany::refresh();
        $refused = false;
        $msg = '';
        try {
            app(GstReturnService::class)->gstr1($period);
        } catch (RuntimeException $e) {
            $refused = true;
            $msg = $e->getMessage();
        }
        $this->expect('export refuses without a GSTIN', $refused, true);
        $this->expect('…with the actionable message', $msg, 'Company GSTIN not configured — set it in F11 Features before generating returns');
    }
}
