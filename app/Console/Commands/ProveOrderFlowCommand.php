<?php

namespace App\Console\Commands;

use App\Console\Concerns\ResolvesActiveCompany;
use App\Livewire\VoucherScreen;
use App\Models\AccountGroup;
use App\Models\CompanyFeature;
use App\Models\Godown;
use App\Models\Ledger;
use App\Models\OrderFulfillment;
use App\Models\OrderLine;
use App\Models\StockEntry;
use App\Models\StockGroup;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\Voucher;
use App\Services\BalanceService;
use App\Services\GstService;
use App\Services\StockService;
use App\Services\Tenancy\TenantProvisioner;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Phase 8B numeric proof — the six inventory-workflow vouchers (Sales/Purchase Order,
 * Delivery/Receipt Note, Rejections In/Out), posted through the SAME
 * VoucherScreen::post() every voucher uses. It proves, to the unit and the paise:
 *
 *   • Orders are pure COMMITMENTS — zero stock, zero ledger entries, zero Trial-Balance
 *     movement — that still reconcile through order_lines / order_fulfillments.
 *   • Delivery/Receipt Notes move REAL stock (weighted-average cost, server-authoritative)
 *     but post NO accounting.
 *   • The "cannot deliver more than ordered" rule is enforced server-side and rolls the
 *     whole voucher back.
 *   • THE DOUBLE-STOCK SAFEGUARD: a Sales/Purchase invoice that references a Delivery/
 *     Receipt Note writes NOT ONE stock_entries row — the note already moved the goods —
 *     so the weighted average and on-hand quantity are provably untouched by the invoice,
 *     while a normal (unreferenced) invoice still moves stock exactly as before.
 *   • Alter and cancel of a note deterministically reverse the reconciliation.
 */
class ProveOrderFlowCommand extends Command
{
    use ResolvesActiveCompany;
    protected $signature = 'zerobook:prove-order-flow {--keep : keep the orderflowtest tenant provisioned} {--company= : run in this company (slug or id); default = the throwaway tenant’s default company}';

    protected $description = 'Prove the inventory-workflow vouchers: order reconciliation, over-delivery block, the double-stock safeguard, and deterministic alter/cancel reversal';

    private bool $ok = true;

    public function handle(TenantProvisioner $provisioner): int
    {
        $slug = 'orderflowtest';
        try {
            $provisioner->teardown($slug);
            $provisioner->provision($slug, 'Order Flow Test Co', 'professional');

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
        $this->info($this->ok ? 'ALL ORDER-FLOW ASSERTIONS PASSED.' : 'ORDER-FLOW ASSERTIONS FAILED.');

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
        $bs = app(BalanceService::class);
        $stock = app(StockService::class);

        // GST on, intra-state (company state == party state) so accounting invoices
        // split CGST+SGST — used only to prove the referenced invoice STILL posts its
        // ledger side while moving no stock.
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

        // ── helpers ─────────────────────────────────────────────────────────────
        // A pure inventory-workflow payload: item lines only, NO ledger side.
        $wf = function (string $type, ?Ledger $party, array $items, string $date, ?int $ref = null, ?int $alterId = null) use ($godown) {
            $p = ['type' => $type, 'date' => $date, 'items' => array_map(fn ($it) => [
                'stock_item_id' => $it[0]->id ?? $it[0], 'godown_id' => $godown, 'qty' => $it[1], 'rate' => $it[2],
            ], $items)];
            if ($party) {
                $p['party_ledger_id'] = $party->id;
            }
            if ($ref) {
                $p['reference_voucher_id'] = $ref;
            }
            if ($alterId) {
                $p['voucher_id'] = $alterId;
            }

            return $p;
        };

        // A GST item-invoice payload (Sales/Purchase), optionally referencing a note.
        $inv = function (string $type, Ledger $party, Ledger $rev, float $qty, float $rate, string $date, ?int $ref = null) use ($gst, $widget, $godown) {
            $base = round($qty * $rate, 2);
            $comp = $gst->computeInvoiceTax($type, $party->state, [['amount' => $base, 'rate' => (float) $widget->gst_rate]]);
            $revSide = $type === 'sales' ? 'Cr' : 'Dr';
            $partySide = $type === 'sales' ? 'Dr' : 'Cr';
            $lines = [['ledger_id' => $rev->id, 'dr_cr' => $revSide, 'amount' => $base]];
            $totalP = (int) round($base * 100);
            foreach ($comp['lines'] as $tl) {
                $lines[] = ['ledger_id' => $tl['ledger_id'], 'dr_cr' => $tl['dr_cr'], 'amount' => $tl['amount']];
            }
            $totalP += $comp['tax_paise'];
            array_unshift($lines, ['ledger_id' => $party->id, 'dr_cr' => $partySide, 'amount' => $totalP / 100]);
            $p = ['type' => $type, 'date' => $date, 'party_ledger_id' => $party->id, 'lines' => $lines,
                'items' => [['stock_item_id' => $widget->id, 'godown_id' => $godown, 'qty' => $qty, 'rate' => $rate]]];
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
        $stkCount = fn (int $vid) => StockEntry::where('voucher_id', $vid)->count();
        $entryCount = fn (int $vid) => Voucher::find($vid)?->entries()->count();
        $line = fn (int $orderId) => OrderLine::where('voucher_id', $orderId)->orderBy('line_no')->first();
        $balanced = fn () => $bs->trialBalance(...$bs->withinFy(null, null))['balanced'];
        $far = Carbon::parse('2027-03-31'); // "current total" as-of date
        $qtyNow = fn () => $stock->closingBalance($widget->id, $far)['qty'];
        $valNow = fn () => $stock->closingBalance($widget->id, $far)['value'];
        $orderPending = fn (int $orderId) => (float) OrderLine::where('voucher_id', $orderId)->get()->sum(fn ($l) => max(0.0, $l->pendingQty()));
        $isOutstanding = fn (int $orderId) => OrderLine::where('voucher_id', $orderId)->get()->contains(fn ($l) => $l->pendingQty() > 1e-6);

        // ── build the running average: buy 100 @ 50, buy 100 @ 70 ⇒ avg 60 ──────
        $screen->post($inv('purchase', $supp, $purchLed, 100, 50, '2026-04-01'));
        $screen->post($inv('purchase', $supp, $purchLed, 100, 70, '2026-04-02'));
        $this->expectClose('Opening running average after the two buys = 60', $stock->weightedAverageRate($widget->id, Carbon::parse('2026-04-03')), 60);
        $this->expectClose('On hand = 200', $qtyNow(), 200);

        // ═══ 1. Sales Order — a pure commitment, ZERO impact ════════════════════
        $this->section('Sales Order 20 @ 100 — commitment only (no stock, no accounting)');
        $so = $screen->post($wf('sales_order', $cust, [[$widget, 20, 100]], '2026-04-03'));
        $soId = $so['voucher']['id'];
        $this->expect('SO wrote NO ledger entries', $entryCount($soId), 0);
        $this->expect('SO wrote NO stock entries', $stkCount($soId), 0);
        $ol = $line($soId);
        $this->expectClose('SO order line: ordered 20', (float) $ol->ordered_qty, 20);
        $this->expectClose('SO order line: delivered 0', (float) $ol->delivered_qty, 0);
        $this->expectClose('SO order line: pending 20', $ol->pendingQty(), 20);
        $this->expectClose('On hand UNCHANGED at 200 (order moved nothing)', $qtyNow(), 200);
        $this->expectClose('Running average UNCHANGED at 60', $stock->weightedAverageRate($widget->id, $far), 60);
        $this->expect('Trial Balance still balances', $balanced(), true);

        // ═══ 2. Delivery Note referencing the order — 12 of 20 ══════════════════
        $this->section('Delivery Note (ref SO) 12 units — moves stock, fulfils the order');
        $dn1 = $screen->post($wf('delivery_note', $cust, [[$widget, 12, 100]], '2026-04-04', $soId));
        $dn1Id = $dn1['voucher']['id'];
        $this->expect('Delivery Note wrote NO ledger entries', $entryCount($dn1Id), 0);
        $dr = $stkRow($dn1Id);
        $this->expect('Delivery stock direction OUT', $dr->direction, 'out');
        $this->expect('Delivery movement_type = sale', $dr->movement_type, 'sale');
        $this->expectClose('Delivery OUT qty 12', (float) $dr->quantity, 12);
        $this->expectClose('Delivery OUT costed at weighted average 60 (server-authoritative)', (float) $dr->rate, 60);
        $this->expectClose('Delivery OUT cost value 720', (float) $dr->value, 720);
        $ol = $line($soId);
        $this->expectClose('Order line delivered_qty now 12', (float) $ol->delivered_qty, 12);
        $this->expectClose('Order line pending now 8', $ol->pendingQty(), 8);
        $ff = OrderFulfillment::where('order_line_id', $ol->id)->get();
        $this->expect('Exactly one fulfillment row recorded', $ff->count(), 1);
        $this->expect('Fulfillment links to THIS delivery note', (int) $ff->first()->fulfillment_voucher_id, (int) $dn1Id);
        $this->expectClose('Fulfillment qty 12', (float) $ff->first()->qty, 12);
        $this->expectClose('On hand now 188', $qtyNow(), 188);
        $this->expectClose('Running average STILL 60 (a sale never shifts the average)', $stock->weightedAverageRate($widget->id, $far), 60);
        $this->expect('Trial Balance still balances (delivery posts no entries)', $balanced(), true);

        // ═══ 3. Over-delivery is refused (server-side, whole voucher rolled back) ═
        $this->section('Over-delivery blocked — 12 already out, cannot deliver 10 more (max 20)');
        $dnCountBefore = Voucher::where('type', 'delivery_note')->count();
        $rejected = false;
        $keys = [];
        try {
            $screen->post($wf('delivery_note', $cust, [[$widget, 10, 100]], '2026-04-05', $soId));
        } catch (ValidationException $e) {
            $rejected = true;
            $keys = array_keys($e->errors());
        }
        $this->expect('Over-delivery rejected', $rejected, true);
        $this->expect('…flagged on the items field', in_array('items', $keys, true), true);
        $this->expect('No stray delivery note was created (rolled back)', Voucher::where('type', 'delivery_note')->count(), $dnCountBefore);
        $this->expectClose('Order line delivered_qty UNMOVED at 12', (float) $line($soId)->delivered_qty, 12);
        $this->expectClose('On hand UNMOVED at 188 (the rejected note wrote no stock)', $qtyNow(), 188);

        // ═══ 4. Deliver the remaining 8 — order fully fulfilled, off the report ══
        $this->section('Delivery Note (ref SO) remaining 8 — order fully fulfilled');
        $dn2 = $screen->post($wf('delivery_note', $cust, [[$widget, 8, 100]], '2026-04-06', $soId));
        $dn2Id = $dn2['voucher']['id'];
        $ol = $line($soId);
        $this->expectClose('Order line delivered_qty now 20', (float) $ol->delivered_qty, 20);
        $this->expectClose('Order line pending now 0', $ol->pendingQty(), 0);
        $this->expect('Fully-fulfilled order drops OFF the Sales-Orders-Outstanding report', $isOutstanding($soId), false);
        $this->expectClose('On hand now 180', $qtyNow(), 180);

        // ═══ 5. THE DOUBLE-STOCK SAFEGUARD ══════════════════════════════════════
        $this->section('Sales invoice REFERENCING the delivery note — posts accounting, moves NO stock');
        $qtyBefore = $qtyNow();
        $valBefore = $valNow();
        $sale = $screen->post($inv('sales', $cust, $salesLed, 12, 100, '2026-04-07', $dn1Id));
        $saleId = $sale['voucher']['id'];
        $sl = $legs($saleId);
        $this->expect('Referenced invoice STILL posts its ledger side: Dr Cust 1,416', $sl['Cust (MH)'] ?? null, ['side' => 'Dr', 'paise' => 141600]);
        $this->expect('…Cr Sales 1,200', $sl['Sales @18'] ?? null, ['side' => 'Cr', 'paise' => 120000]);
        $this->expect('…Cr Output CGST 108', $sl['Output CGST'] ?? null, ['side' => 'Cr', 'paise' => 10800]);
        $this->expect('…Cr Output SGST 108', $sl['Output SGST'] ?? null, ['side' => 'Cr', 'paise' => 10800]);
        $this->expect('*** SAFEGUARD: the referenced invoice wrote ZERO stock entries ***', $stkCount($saleId), 0);
        $this->expectClose('On hand UNCHANGED (the delivery note already moved the goods)', $qtyNow(), $qtyBefore);
        $this->expectClose('Stock value UNCHANGED', $valNow(), $valBefore);
        $this->expectClose('Running average UNCHANGED at 60', $stock->weightedAverageRate($widget->id, $far), 60);
        $this->expect('Trial Balance still balances', $balanced(), true);

        // ═══ 6. Control — a NORMAL (unreferenced) invoice DOES move stock ════════
        $this->section('Control: a normal Sales invoice (no reference) still moves stock as before');
        $ctrl = $screen->post($inv('sales', $cust, $salesLed, 5, 100, '2026-04-08'));
        $ctrlId = $ctrl['voucher']['id'];
        $this->expect('Unreferenced invoice wrote its stock entry (safeguard is targeted, not global)', $stkCount($ctrlId), 1);
        $this->expect('…OUT direction', $stkRow($ctrlId)->direction, 'out');
        $this->expectClose('…OUT qty 5 costed at 60', (float) $stkRow($ctrlId)->quantity, 5);
        $this->expectClose('On hand now 175', $qtyNow(), 175);

        // ═══ 7. Alter the first delivery note 12 → 10 — reconciliation reverses ══
        $this->section('Alter Delivery Note #1 from 12 to 10 — delivered_qty recomputed deterministically');
        $screen->post($wf('delivery_note', $cust, [[$widget, 10, 100]], '2026-04-04', $soId, $dn1Id));
        $this->expectClose('DN#1 stock entry now qty 10', (float) $stkRow($dn1Id)->quantity, 10);
        $ol = $line($soId);
        $this->expectClose('Order line delivered_qty now 18 (10 + 8)', (float) $ol->delivered_qty, 18);
        $this->expectClose('Order line pending back to 2', $ol->pendingQty(), 2);
        $this->expect('Still exactly one fulfillment row for DN#1 (replaced, not duplicated)', OrderFulfillment::where('fulfillment_voucher_id', $dn1Id)->count(), 1);
        $this->expect('Partly-fulfilled order is outstanding again', $isOutstanding($soId), true);
        $this->expectClose('On hand now 177 (2 units un-delivered returned to stock)', $qtyNow(), 177);

        // ═══ 8. Cancel the first delivery note — reconciliation rolls fully back ═
        $this->section('Cancel Delivery Note #1 — fulfillment reversed, stock restored');
        Voucher::find($dn1Id)->delete();
        $this->expect('DN#1 stock entries gone (FK cascade)', $stkCount($dn1Id), 0);
        $this->expect('DN#1 fulfillment rows gone', OrderFulfillment::where('fulfillment_voucher_id', $dn1Id)->count(), 0);
        $ol = $line($soId);
        $this->expectClose('Order line delivered_qty back to 8 (only DN#2 remains)', (float) $ol->delivered_qty, 8);
        $this->expectClose('Order line pending back to 12', $ol->pendingQty(), 12);
        $this->expectClose('On hand now 187 (10 cancelled units restored)', $qtyNow(), 187);

        // ═══ 9. Rejections — Out sends stock away, In brings it back ═════════════
        $this->section('Rejections Out 3 (to supplier) then Rejections In 2 (from customer)');
        $ro = $screen->post($wf('rejection_out', $supp, [[$widget, 3, 100]], '2026-04-11'));
        $roId = $ro['voucher']['id'];
        $this->expect('Rejection Out posts no ledger entries', $entryCount($roId), 0);
        $this->expect('Rejection Out stock direction OUT', $stkRow($roId)->direction, 'out');
        $this->expect('Rejection Out movement_type = purchase_return', $stkRow($roId)->movement_type, 'purchase_return');
        $this->expectClose('Rejection Out OUT qty 3 @ 60', (float) $stkRow($roId)->quantity, 3);
        $this->expectClose('On hand now 184', $qtyNow(), 184);

        $ri = $screen->post($wf('rejection_in', $cust, [[$widget, 2, 100]], '2026-04-12'));
        $riId = $ri['voucher']['id'];
        $this->expect('Rejection In posts no ledger entries', $entryCount($riId), 0);
        $this->expect('Rejection In stock direction IN', $stkRow($riId)->direction, 'in');
        $this->expect('Rejection In movement_type = sales_return', $stkRow($riId)->movement_type, 'sales_return');
        $this->expectClose('Rejection In IN cost = current average 60 (NOT the client rate 100), so the average is undistorted', (float) $stkRow($riId)->rate, 60);
        $this->expectClose('On hand now 186', $qtyNow(), 186);
        $this->expectClose('Running average STILL 60 after both rejections', $stock->weightedAverageRate($widget->id, $far), 60);

        // ═══ 10. Purchase Order → Receipt Note → Purchase invoice (the mirror) ══
        $this->section('Purchase Order 50 @ 45 — commitment only');
        $po = $screen->post($wf('purchase_order', $supp, [[$widget, 50, 45]], '2026-04-13'));
        $poId = $po['voucher']['id'];
        $this->expect('PO wrote NO ledger entries', $entryCount($poId), 0);
        $this->expect('PO wrote NO stock entries', $stkCount($poId), 0);
        $this->expectClose('PO order line pending 50', $line($poId)->pendingQty(), 50);
        $this->expectClose('On hand UNCHANGED at 186', $qtyNow(), 186);

        $this->section('Receipt Note (ref PO) 30 — brings stock IN at the provisional (entered) rate');
        $rn = $screen->post($wf('receipt_note', $supp, [[$widget, 30, 45]], '2026-04-14', $poId));
        $rnId = $rn['voucher']['id'];
        $this->expect('Receipt Note wrote NO ledger entries', $entryCount($rnId), 0);
        $this->expect('Receipt stock direction IN', $stkRow($rnId)->direction, 'in');
        $this->expect('Receipt movement_type = purchase', $stkRow($rnId)->movement_type, 'purchase');
        $this->expectClose('Receipt IN qty 30 at provisional rate 45 (Approach A)', (float) $stkRow($rnId)->rate, 45);
        $this->expectClose('Receipt IN value 1,350', (float) $stkRow($rnId)->value, 1350);
        $this->expectClose('PO order line received 30, pending 20', $line($poId)->pendingQty(), 20);
        $this->expect('Partly-received PO is on the Purchase-Orders-Outstanding report', $isOutstanding($poId), true);
        $this->expectClose('On hand now 216', $qtyNow(), 216);
        $this->expectClose('Running average moved to 57.9167 (the 30 @ 45 IN blended in)', $stock->weightedAverageRate($widget->id, $far), 57.9167, 0.01);

        $this->section('Purchase invoice REFERENCING the receipt note — accounting only, NO stock');
        $qtyBefore = $qtyNow();
        $pi = $screen->post($inv('purchase', $supp, $purchLed, 30, 45, '2026-04-15', $rnId));
        $piId = $pi['voucher']['id'];
        $pl = $legs($piId);
        $this->expect('Referenced purchase invoice STILL posts: Dr Purchase 1,350', $pl['Purchase @18'] ?? null, ['side' => 'Dr', 'paise' => 135000]);
        $this->expect('…Cr Supp 1,593 (tax-inclusive)', $pl['Supp (MH)'] ?? null, ['side' => 'Cr', 'paise' => 159300]);
        $this->expect('*** SAFEGUARD: the referenced purchase invoice wrote ZERO stock entries ***', $stkCount($piId), 0);
        $this->expectClose('On hand UNCHANGED (the receipt note already brought the goods in)', $qtyNow(), $qtyBefore);
        $this->expect('Trial Balance still balances', $balanced(), true);

        // ═══ Reports & pickers — the reconciliation data the UI reads ═══════════
        $this->section('Orders Outstanding report + reference-picker lookup');
        $orderSvc = app(\App\Services\OrderService::class);
        $soOut = $orderSvc->outstanding(['sales_order']);
        // The SO was fully delivered (20/20), then DN#1 (10u) was cancelled — so its
        // reconciliation rolled back to delivered 8, pending 12, and the report shows
        // it outstanding again. This proves the report reflects the cancel deterministically.
        $soRow = collect($soOut)->firstWhere('id', $soId);
        $this->expect('After DN#1 was cancelled, the Sales Order is outstanding again', $soRow !== null, true);
        $this->expectClose('SO outstanding pending qty 12 (20 ordered − 8 still delivered)', $soRow['lines'][0]['pending'] ?? 0, 12);
        $this->expectClose('SO outstanding pending value = 12 × 100 = 1,200', $soRow['pending_value'] ?? 0, 1200);
        $poOut = $orderSvc->outstanding(['purchase_order']);
        $poRow = collect($poOut)->firstWhere('id', $poId);
        $this->expect('Partly-received Purchase Order IS on the outstanding report', $poRow !== null, true);
        $this->expectClose('PO outstanding pending qty 20', $poRow['lines'][0]['pending'] ?? 0, 20);
        $this->expectClose('PO outstanding pending value = 20 × 45 = 900', $poRow['pending_value'] ?? 0, 900);
        $ref = $screen->referenceOrder($poId);
        $this->expect('referenceOrder(PO) returns exactly its one pending line', count($ref['items'] ?? []), 1);
        $this->expectClose('…pre-filling the Receipt Note with pending qty 20', $ref['items'][0]['qty'] ?? 0, 20);

        // The client's pickers are fed by bootData(); verify the data-providers end to end.
        $dnScreen = new VoucherScreen();
        $dnScreen->initialType = 'delivery_note';
        $boot = $dnScreen->bootData();
        $this->expect('bootData(delivery_note) ships the workflow type list to the client', in_array('delivery_note', $boot['workflowTypes'] ?? [], true), true);
        $this->expect('bootData(delivery_note) offers the open Sales Order to deliver against', collect($boot['referenceOrders']['delivery_note'] ?? [])->contains(fn ($o) => $o['id'] === $soId), true);
        $saleScreen = new VoucherScreen();
        $saleScreen->initialType = 'sales';
        $sboot = $saleScreen->bootData();
        $this->expect('bootData(sales) offers Delivery Notes for the double-stock picker', count($sboot['referenceDeliveries']['sales'] ?? []) >= 1, true);

        // ═══ Global invariants across every workflow voucher ════════════════════
        $this->section('Global invariants');
        $wfIds = Voucher::whereIn('type', Voucher::INVENTORY_WORKFLOW_TYPES)->pluck('id');
        $wfEntryTotal = \App\Models\VoucherEntry::whereIn('voucher_id', $wfIds)->count();
        $this->expect('EVERY inventory-workflow voucher posted ZERO ledger entries (Trial Balance untouched)', $wfEntryTotal, 0);
        $orderIds = Voucher::whereIn('type', Voucher::ORDER_TYPES)->pluck('id');
        $orderStockTotal = StockEntry::whereIn('voucher_id', $orderIds)->count();
        $this->expect('EVERY order posted ZERO stock entries (commitments move no stock)', $orderStockTotal, 0);
        $this->expect('Trial Balance balances at the end of the whole flow', $balanced(), true);
    }

    private function section(string $t): void
    {
        $this->line('');
        $this->line('── '.$t.' '.str_repeat('─', max(0, 66 - strlen($t))));
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
