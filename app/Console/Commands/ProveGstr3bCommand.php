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
use App\Models\Voucher;
use App\Services\Gst\GstReturnService;
use App\Services\GstService;
use App\Services\Tenancy\TenantProvisioner;
use App\Support\GstReturnExport;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Phase 9A numeric proof — GSTR-3B.
 *
 * Same outward supplies as `prove-gstr1`, plus two purchase invoices carrying input
 * tax. Asserts `sup_details.osup_det.*`, `itc_elg.*` and the net payable to the paise,
 * proves the two returns tie out to each other, and proves that the JSON carries **no
 * tax-payment section** — because the Government's own GSTR-3B utility emits none.
 */
class ProveGstr3bCommand extends Command
{
    use ProvesGstReturns;
    use ResolvesActiveCompany;

    protected $signature = 'zerobook:prove-gstr3b {--keep : keep the gstr3btest tenant provisioned} {--company= : run in this company (slug or id); default = the throwaway tenant’s default company}';

    protected $description = 'Prove the GSTR-3B JSON export: outward supplies, eligible ITC, net payable, schema conformance, no tax-payment section';

    private bool $ok = true;

    public function handle(TenantProvisioner $provisioner): int
    {
        $schema = $this->loadSchema('gstr3b-schema.json');
        if ($schema === null) {
            return self::FAILURE;
        }

        $slug = 'gstr3btest';
        try {
            $provisioner->teardown($slug);
            $provisioner->provision($slug, 'GSTR3B Test Co', 'professional');

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
        $this->info($this->ok ? 'ALL GSTR-3B ASSERTIONS PASSED.' : 'GSTR-3B ASSERTIONS FAILED.');

        return $this->ok ? self::SUCCESS : self::FAILURE;
    }

    private function runProof(array $schema): void
    {
        // Phase 12A — pin the throwaway tenant's default company as active before
        // any scoped model is touched (CLI has no session).
        if (! $this->resolveActiveCompany()) {
            throw new \RuntimeException('No default company in the throwaway tenant.');
        }

        $period = '042026';
        $screen = new VoucherScreen();

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

        $regBuyer = Ledger::create(['name' => 'Reg Buyer (MH)', 'group_id' => $gid('Sundry Debtors'), 'state' => 'Maharashtra', 'country' => 'India', 'gstin' => '27BBBBB1111B1Z5']);
        $unregLocal = Ledger::create(['name' => 'Walk-in (MH)', 'group_id' => $gid('Sundry Debtors'), 'state' => 'Maharashtra', 'country' => 'India']);
        $unregFar = Ledger::create(['name' => 'Walk-in (KA)', 'group_id' => $gid('Sundry Debtors'), 'state' => 'Karnataka', 'country' => 'India']);
        $suppMh = Ledger::create(['name' => 'Supplier (MH)', 'group_id' => $gid('Sundry Creditors'), 'state' => 'Maharashtra', 'country' => 'India', 'gstin' => '27CCCCC2222C1Z5']);
        $suppKa = Ledger::create(['name' => 'Supplier (KA)', 'group_id' => $gid('Sundry Creditors'), 'state' => 'Karnataka', 'country' => 'India', 'gstin' => '29DDDDD3333D1Z5']);

        $salesLed = Ledger::create(['name' => 'Sales @18', 'group_id' => $gid('Sales Accounts'), 'gst_rate' => 18, 'country' => 'India']);
        $purchLed = Ledger::create(['name' => 'Purchase @18', 'group_id' => $gid('Purchase Accounts'), 'gst_rate' => 18, 'country' => 'India']);
        $salesReturn = Ledger::where('name', 'Sales Return')->first();

        $inv = $this->invoiceFactory($gst, $widget, $godown);

        // Outward — identical to prove-gstr1.
        $screen->post($inv('sales', $regBuyer, $salesLed, 100, 1000, '2026-04-05'));   // intra 100,000 → C 9,000 S 9,000
        $screen->post($inv('sales', $unregLocal, $salesLed, 100, 1000, '2026-04-07')); // intra 100,000 → C 9,000 S 9,000
        $screen->post($inv('sales', $unregFar, $salesLed, 300, 1000, '2026-04-09'));   // inter 300,000 → I 54,000
        $sale1Id = Voucher::where('type', 'sales')->orderBy('id')->value('id');
        $screen->post($inv('credit_note', $regBuyer, $salesReturn, 10, 1000, '2026-04-20', $sale1Id)); // −10,000 → C −900 S −900

        // Inward — two purchases carrying input tax.
        $screen->post($inv('purchase', $suppMh, $purchLed, 50, 1000, '2026-04-03'));   // intra 50,000 → C 4,500 S 4,500
        $screen->post($inv('purchase', $suppKa, $purchLed, 20, 1000, '2026-04-04'));   // inter 20,000 → I 3,600

        // Workflow vouchers in the same period — must never contribute.
        $wf = fn (string $type, Ledger $party, float $qty) => [
            'type' => $type, 'date' => '2026-04-11', 'party_ledger_id' => $party->id,
            'items' => [['stock_item_id' => $widget->id, 'godown_id' => $godown, 'qty' => $qty, 'rate' => 1000]],
        ];
        $screen->post($wf('sales_order', $regBuyer, 10));
        $screen->post($wf('purchase_order', $suppMh, 7));
        $screen->post($wf('delivery_note', $regBuyer, 5));

        $svc = app(GstReturnService::class);
        $j = $svc->gstr3b($period);
        $encoded = GstReturnExport::encode($j);

        // ═══ header ═════════════════════════════════════════════════════════
        $this->section('Header');
        $this->expect('gstin', $j['gstin'], '27AAAAA0000A1Z5');
        $this->expect('ret_period is MMYYYY', $j['ret_period'], '042026');

        // ═══ 3.1(a) outward taxable supplies ════════════════════════════════
        $this->section('3.1(a) sup_details.osup_det — outward taxable supplies');
        $o = $j['sup_details']['osup_det'];
        $this->expectClose('txval = 100k + 100k + 300k − 10k (credit note) = 490,000', $o['txval'], 490000);
        $this->expectClose('iamt = 54,000 (the one inter-state sale)', $o['iamt'], 54000);
        $this->expectClose('camt = 9,000 + 9,000 − 900 = 17,100', $o['camt'], 17100);
        $this->expectClose('samt = 17,100', $o['samt'], 17100);
        $this->expectClose('csamt = 0', $o['csamt'], 0);
        $this->expect('zero-rated / nil-exempt / non-GST / reverse-charge are all zero', [
            $j['sup_details']['osup_zero']['txval'],
            $j['sup_details']['osup_nil_exmp']['txval'],
            $j['sup_details']['osup_nongst']['txval'],
            $j['sup_details']['isup_rev']['txval'],
        ], [0.0, 0.0, 0.0, 0.0]);

        // ═══ 4. eligible ITC ════════════════════════════════════════════════
        $this->section('4. itc_elg — eligible input tax credit');
        $avl = collect($j['itc_elg']['itc_avl']);
        $this->expect('itc_avl is an array of typed rows', $avl->pluck('ty')->all(), ['IMPG', 'IMPS', 'ISRC', 'ISD', 'OTH']);
        $oth = $avl->firstWhere('ty', 'OTH');
        $this->expectClose('itc_avl[OTH].iamt = 3,600 (inter-state purchase)', $oth['iamt'], 3600);
        $this->expectClose('itc_avl[OTH].camt = 4,500 (intra-state purchase)', $oth['camt'], 4500);
        $this->expectClose('itc_avl[OTH].samt = 4,500', $oth['samt'], 4500);
        $impg = $avl->firstWhere('ty', 'IMPG');
        $this->expectClose('itc_avl[IMPG] is zero (no imports tracked)', $impg['iamt'] + $impg['camt'] + $impg['samt'], 0);
        $net = $j['itc_elg']['itc_net'];
        $this->expectClose('itc_net.iamt 3,600', $net['iamt'], 3600);
        $this->expectClose('itc_net.camt 4,500', $net['camt'], 4500);
        $this->expectClose('itc_net.samt 4,500', $net['samt'], 4500);
        $this->expect('itc_rev / itc_inelg carry the RUL + OTH rows at zero', [
            collect($j['itc_elg']['itc_rev'])->pluck('ty')->all(),
            collect($j['itc_elg']['itc_inelg'])->pluck('ty')->all(),
        ], [['RUL', 'OTH'], ['RUL', 'OTH']]);

        // ═══ 3.2 inter-state supplies to unregistered persons ═══════════════
        $this->section('3.2 inter_sup.unreg_details');
        $u = $j['inter_sup']['unreg_details'];
        $this->expect('one POS row', count($u), 1);
        $this->expect('pos = Karnataka (29)', $u[0]['pos'], '29');
        $this->expectClose('txval 300,000', $u[0]['txval'], 300000);
        $this->expectClose('iamt 54,000', $u[0]['iamt'], 54000);
        $this->expect('comp_details / uin_details empty', [count($j['inter_sup']['comp_details']), count($j['inter_sup']['uin_details'])], [0, 0]);

        // ═══ the section the prompt expected — which does NOT exist ═════════
        $this->section('No tax-payment section (the real utility emits none)');
        $this->expect('no `tax_pmt` key', array_key_exists('tax_pmt', $j), false);
        $this->expect('no `tx_pmt` key', array_key_exists('tx_pmt', $j), false);
        $this->expect('…and neither string appears anywhere in the file', str_contains($encoded, 'tx_pmt') || str_contains($encoded, 'tax_pmt'), false);
        $this->expect('the schema records their absence', collect($schema['_absent'] ?? [])->pluck('expected_key')->isNotEmpty(), true);
        $this->expect('the sections that DO exist are 5.1 interest/late-fee + 3.1.1 e-commerce', [
            array_key_exists('intr_ltfee', $j), array_key_exists('eco_dtls', $j), array_key_exists('inward_sup', $j),
        ], [true, true, true]);

        // ═══ net payable ════════════════════════════════════════════════════
        $this->section('Net payable (output tax − eligible ITC)');
        $outTax = $o['iamt'] + $o['camt'] + $o['samt'];
        $itcTax = $net['iamt'] + $net['camt'] + $net['samt'];
        $this->expectClose('output tax = 54,000 + 17,100 + 17,100 = 88,200', $outTax, 88200);
        $this->expectClose('eligible ITC = 3,600 + 4,500 + 4,500 = 12,600', $itcTax, 12600);
        $this->expectClose('net payable = 75,600', $outTax - $itcTax, 75600);
        $p = $svc->preview($period)['gstr3b'];
        $this->expectClose('preview reports the same net payable', $p['net_payable'], 75600);
        $this->expectClose('preview outward taxable matches the JSON', $p['outward_taxable'], $o['txval']);
        $this->expectClose('preview ITC matches the JSON', $p['itc_total'], 12600);

        // ═══ the two returns tie out ════════════════════════════════════════
        $this->section('GSTR-1 and GSTR-3B tie out (what a CA checks first)');
        $p1 = $svc->preview($period)['gstr1'];
        $this->expectClose('GSTR-1 total taxable == GSTR-3B osup_det.txval', $p1['total_taxable'], $o['txval']);
        $this->expectClose('GSTR-1 total tax == GSTR-3B output tax', $p1['total_tax'], $outTax);

        // ═══ workflow vouchers excluded ═════════════════════════════════════
        $this->section('Phase 8B workflow vouchers never reach the return');
        $this->expect('the Sales Order / Delivery Note added no taxable value', $o['txval'], 490000.0);
        $this->expect('no workflow voucher has any ledger entry to contribute', \App\Models\VoucherEntry::whereIn(
            'voucher_id', Voucher::whereIn('type', Voucher::INVENTORY_WORKFLOW_TYPES)->pluck('id')
        )->count(), 0);

        // ═══ rounding + conformance ═════════════════════════════════════════
        $this->section('Numeric precision + schema conformance');
        $this->expect('every money 2dp, every rate 2dp, every qty 4dp', $this->roundingViolations($j), []);
        $this->expect('every REQUIRED key of the schema is present, with the right nested shape', $this->conformanceGaps($j, $schema), []);

        // ═══ ARN log ════════════════════════════════════════════════════════
        $this->section('ARN filing log');
        GstReturnFiling::record($period, 'gstr3b', 'AA1234567890ABC');
        $this->expect('GSTR-3B recorded as filed', GstReturnFiling::isFiled($period, 'gstr3b'), true);
        $this->expect('…and GSTR-1 for the same period is tracked separately', GstReturnFiling::isFiled($period, 'gstr1'), false);

        // ═══ missing GSTIN refusal ══════════════════════════════════════════
        $this->section('Refusal without a company GSTIN');
        activeCompany()->update(['gstin' => null]);
        \App\Support\ActiveCompany::refresh();
        $refused = false;
        $msg = '';
        try {
            app(GstReturnService::class)->gstr3b($period);
        } catch (RuntimeException $e) {
            $refused = true;
            $msg = $e->getMessage();
        }
        $this->expect('export refuses without a GSTIN', $refused, true);
        $this->expect('…with the actionable message', $msg, 'Company GSTIN not configured — set it in F11 Features before generating returns');
    }
}
