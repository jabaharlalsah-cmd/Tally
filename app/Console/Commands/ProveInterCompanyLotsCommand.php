<?php

namespace App\Console\Commands;

use App\Livewire\VoucherScreen;
use App\Models\AccountGroup;
use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\Godown;
use App\Models\StockLot;
use App\Models\Ledger;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\Voucher;
use App\Services\BalanceService;
use App\Services\CompanyProvisioner;
use App\Services\StockLotService;
use App\Services\Tenancy\TenantProvisioner;
use App\Support\ActiveCompany;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Phase 12C-1 — THE inter-company FIFO lot proof. Companies A (Apex Supply) and
 * B (Bravo Retail) grouped with reciprocal linked ledgers; C ungrouped. The
 * worked numeric scenario, to the paise and the quarter-unit:
 *
 *   • A buys Widget 100 @₹100 outside, sells 100 @₹120 to B → B's Purchase
 *     writes the usual stock row (qty 100 rate 120) AND a lot
 *     (100/100, source A, source voucher = A's sale, source cost 100, price 120);
 *   • ambiguous source (two identical candidate sales) → lot with NULL source,
 *     surfaced as unmatched;
 *   • FIFO depletion: sales of 30 then 90 walk lots 100→70→0 and 50→30 while the
 *     OUT cost stays the RUNNING WEIGHTED AVERAGE (asserted to the paise);
 *   • over-depletion into mixed inventory is a normal case, never an error;
 *   • alter replays exactly; cancel cascades the lot and refolds the trace;
 *   • a godown transfer splits a child lot at the destination, inheriting the
 *     parent's FIFO position;
 *   • remainingLotsFor(asOf) replays history in memory (12C-2's query);
 *   • the Trial Balance and every valuation number are byte-identical to the
 *     pre-lot engine — prove-balance and prove-inventory-integration re-run
 *     green INSIDE this tenant; and an ungrouped company writes zero lot rows.
 */
class ProveInterCompanyLotsCommand extends Command
{
    protected $signature = 'zerobook:prove-inter-company-lots {--keep : keep the icltest tenant provisioned}';

    protected $description = 'Prove Phase 12C-1: FIFO lot provenance for inter-company stock — write/deplete/transfer/alter/cancel lifecycle, weighted-average untouched, ungrouped tenants inert';

    private bool $ok = true;

    private int $a;

    private int $b;

    public function handle(TenantProvisioner $provisioner): int
    {
        $slug = 'icltest';
        try {
            $provisioner->teardown($slug);
            $provisioner->provision($slug, 'Apex Supply Co', 'professional');
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
        $this->info($this->ok ? 'ALL INTER-COMPANY LOT ASSERTIONS PASSED.' : 'INTER-COMPANY LOT ASSERTIONS FAILED.');

        return $this->ok ? self::SUCCESS : self::FAILURE;
    }

    private function runProof(): void
    {
        $this->a = Company::defaultCompany()->id;
        $this->b = app(CompanyProvisioner::class)->create('Bravo Retail Co')->id;
        $g = CompanyGroup::create(['name' => 'Apex Group', 'slug' => 'apex-group']);
        $g->companies()->attach($this->a);
        $g->companies()->attach($this->b);

        // A: debtor for B (the reciprocal 12C-1's source matching keys on) + masters.
        [$aDebtorB, $aWidget] = ActiveCompany::runAs($this->a, function () {
            $gid = fn (string $n) => AccountGroup::where('name', $n)->value('id');
            $debtor = Ledger::create(['name' => 'Bravo Retail Co', 'group_id' => $gid('Sundry Debtors'), 'linked_company_id' => $this->b]);
            Ledger::create(['name' => 'Outside Supplies Ltd', 'group_id' => $gid('Sundry Creditors')]);
            Ledger::create(['name' => 'Sales A/c', 'group_id' => $gid('Sales Accounts')]);
            Ledger::create(['name' => 'Purchase A/c', 'group_id' => $gid('Purchase Accounts')]);
            $unit = Unit::create(['name' => 'Numbers', 'symbol' => 'Nos', 'decimal_places' => 0]);
            $widget = StockItem::create(['name' => 'Widget', 'unit_id' => $unit->id, 'opening_qty' => 0, 'opening_rate' => 0, 'opening_value' => 0]);

            return [$debtor, $widget];
        });

        // B: creditor for A + its own masters (same ITEM NAME — the cross-company identity).
        $bLedgers = ActiveCompany::runAs($this->b, function () {
            $gid = fn (string $n) => AccountGroup::where('name', $n)->value('id');
            $unit = Unit::create(['name' => 'Numbers', 'symbol' => 'Nos', 'decimal_places' => 0]);

            return [
                'creditorA' => Ledger::create(['name' => 'Apex Supply Co', 'group_id' => $gid('Sundry Creditors'), 'linked_company_id' => $this->a]),
                'outsideCust' => Ledger::create(['name' => 'Walk-in Customer', 'group_id' => $gid('Sundry Debtors')]),
                'outsideSupp' => Ledger::create(['name' => 'Local Wholesale', 'group_id' => $gid('Sundry Creditors')]),
                'sales' => Ledger::create(['name' => 'Sales A/c', 'group_id' => $gid('Sales Accounts')]),
                'purchase' => Ledger::create(['name' => 'Purchase A/c', 'group_id' => $gid('Purchase Accounts')]),
                'widget' => StockItem::create(['name' => 'Widget', 'unit_id' => $unit->id, 'opening_qty' => 0, 'opening_rate' => 0, 'opening_value' => 0]),
                'gadget' => StockItem::create(['name' => 'Gadget', 'unit_id' => $unit->id, 'opening_qty' => 0, 'opening_rate' => 0, 'opening_value' => 0]),
                'gizmo' => StockItem::create(['name' => 'Gizmo', 'unit_id' => $unit->id, 'opening_qty' => 0, 'opening_rate' => 0, 'opening_value' => 0]),
                'wh2' => Godown::create(['name' => 'Warehouse Two']),
                'main' => Godown::where('name', 'Main Location')->value('id'),
            ];
        });

        $post = function (int $companyId, array $payload) {
            return ActiveCompany::runAs($companyId, fn () => (new VoucherScreen())->post($payload));
        };
        $inv = function (string $type, string $date, Ledger $party, Ledger $rev, StockItem $item, float $qty, float $rate, ?int $godownId, ?int $counterparty = null, ?int $voucherId = null) {
            $base = round($qty * $rate, 2);
            $pSide = $type === 'sales' ? 'Dr' : 'Cr';
            $lSide = $type === 'sales' ? 'Cr' : 'Dr';
            $p = [
                'type' => $type, 'date' => $date, 'party_ledger_id' => $party->id,
                'lines' => [
                    ['ledger_id' => $party->id, 'dr_cr' => $pSide, 'amount' => $base],
                    ['ledger_id' => $rev->id, 'dr_cr' => $lSide, 'amount' => $base],
                ],
                'items' => [['stock_item_id' => $item->id, 'godown_id' => $godownId, 'qty' => $qty, 'rate' => $rate]],
            ];
            if ($counterparty) {
                $p['intercompany'] = ['counterparty_company_id' => $counterparty];
            }
            if ($voucherId) {
                $p['voucher_id'] = $voucherId;
            }

            return $p;
        };
        $lotsFor = fn (int $companyId, int $itemId) => ActiveCompany::runAs($companyId,
            fn () => StockLot::where('stock_item_id', $itemId)->orderBy('id')->get());

        $aMainGodown = ActiveCompany::runAs($this->a, fn () => Godown::where('name', 'Main Location')->value('id'));
        $aL = fn (string $n) => ActiveCompany::runAs($this->a, fn () => Ledger::where('name', $n)->first());

        // ═══ 1. A's cost basis + A sells 100 @120 to B ══════════════════════════
        $this->section('Setup: A buys @100 outside, sells 100 @120 to B');
        $post($this->a, $inv('purchase', '2026-06-01', $aL('Outside Supplies Ltd'), $aL('Purchase A/c'), $aWidget, 100, 100.0, $aMainGodown));
        $aSale = $post($this->a, $inv('sales', '2026-06-05', $aDebtorB, $aL('Sales A/c'), $aWidget, 100, 120.0, $aMainGodown, $this->b));
        $aSaleOut = ActiveCompany::runAs($this->a, fn () => Voucher::find($aSale['voucher']['id'])->stockEntries()->first());
        $this->expect("A's OUT costed at its own weighted average (100)", (float) $aSaleOut->rate, 100.0);

        // ═══ 2. B's inter-company Purchase writes stock row + LOT ═══════════════
        $this->section('Lot written on the inter-company Purchase');
        $bPur1 = $post($this->b, $inv('purchase', '2026-06-06', $bLedgers['creditorA'], $bLedgers['purchase'], $bLedgers['widget'], 100, 120.0, $bLedgers['main'], $this->a));
        $in1 = ActiveCompany::runAs($this->b, fn () => Voucher::find($bPur1['voucher']['id'])->stockEntries()->first());
        $this->expect('stock_entries IN row unchanged: qty 100 @120', [(float) $in1->quantity, (float) $in1->rate], [100.0, 120.0]);
        $lots = $lotsFor($this->b, $bLedgers['widget']->id);
        $this->expect('Exactly one lot row', $lots->count(), 1);
        $lot1 = $lots[0];
        $this->expect('Lot: original/remaining 100/100', [(float) $lot1->original_qty, (float) $lot1->remaining_qty], [100.0, 100.0]);
        $this->expect('Lot: source company = A', (int) $lot1->source_company_id, $this->a);
        $this->expect("Lot: source voucher = A's sale (unambiguous match)", (int) $lot1->source_voucher_id, (int) $aSale['voucher']['id']);
        $this->expect('Lot: source cost ₹100/unit', (int) $lot1->source_cost_paise, 10000);
        $this->expect('Lot: transfer price ₹120/unit', (int) $lot1->received_rate_paise, 12000);

        // ═══ 3. FIFO depletion on an outside sale; weighted average untouched ═══
        $this->section('FIFO depletion — OUT costs stay weighted-average');
        $bSale1 = $post($this->b, $inv('sales', '2026-06-10', $bLedgers['outsideCust'], $bLedgers['sales'], $bLedgers['widget'], 30, 200.0, $bLedgers['main']));
        $out1 = ActiveCompany::runAs($this->b, fn () => Voucher::find($bSale1['voucher']['id'])->stockEntries()->first());
        $this->expect('OUT cost = running average 120 (NOT lot cost)', (float) $out1->rate, 120.0);
        $this->expect('Lot 1 remaining 100 → 70', (float) $lotsFor($this->b, $bLedgers['widget']->id)[0]->remaining_qty, 70.0);

        // ═══ 4. Ambiguous source → second lot UNMATCHED ═════════════════════════
        $this->section('Ambiguous source match → null + surfaced as unmatched');
        $post($this->a, $inv('sales', '2026-06-11', $aDebtorB, $aL('Sales A/c'), $aWidget, 50, 130.0, $aMainGodown, $this->b));
        $post($this->a, $inv('sales', '2026-06-11', $aDebtorB, $aL('Sales A/c'), $aWidget, 50, 130.0, $aMainGodown, $this->b));
        $post($this->b, $inv('purchase', '2026-06-12', $bLedgers['creditorA'], $bLedgers['purchase'], $bLedgers['widget'], 50, 130.0, $bLedgers['main'], $this->a));
        $lots = $lotsFor($this->b, $bLedgers['widget']->id);
        $this->expect('Second lot written: 50/50', [(float) $lots[1]->original_qty, (float) $lots[1]->remaining_qty], [50.0, 50.0]);
        $this->expect('Second lot: source voucher NULL (two candidates)', $lots[1]->source_voucher_id, null);
        $this->expect('Second lot: source cost NULL (unmatched, never guessed)', $lots[1]->source_cost_paise, null);
        $unmatched = ActiveCompany::runAs($this->b, fn () => collect(app(StockLotService::class)
            ->remainingLotsFor($bLedgers['widget']->id))->filter(fn ($e) => $e['lot']->source_voucher_id === null)->count());
        $this->expect('Unmatched lot surfaces in the 12C-2 query', $unmatched, 1);

        // ═══ 5. Depletion ACROSS lots — average asserted to the paise ═══════════
        $this->section('Depletion across lots');
        // On hand 120: 70@avg120 (value 8400) + 50@130 (6500) → avg 124.1666…
        $bSale2 = $post($this->b, $inv('sales', '2026-06-15', $bLedgers['outsideCust'], $bLedgers['sales'], $bLedgers['widget'], 90, 210.0, $bLedgers['main']));
        $out2 = ActiveCompany::runAs($this->b, fn () => Voucher::find($bSale2['voucher']['id'])->stockEntries()->first());
        $this->expectClose('OUT cost = pooled average 124.1667', (float) $out2->rate, 14900.0 / 120.0);
        $lots = $lotsFor($this->b, $bLedgers['widget']->id);
        $this->expect('FIFO: lot1 70→0, lot2 50→30', [(float) $lots[0]->remaining_qty, (float) $lots[1]->remaining_qty], [0.0, 30.0]);

        // ═══ 6. Over-depletion into mixed inventory — normal, no error ══════════
        $this->section('Over-depletion (mixed source inventory)');
        $post($this->b, $inv('purchase', '2026-06-16', $bLedgers['outsideSupp'], $bLedgers['purchase'], $bLedgers['widget'], 20, 110.0, $bLedgers['main']));
        $this->expect('Outside purchase wrote NO lot', $lotsFor($this->b, $bLedgers['widget']->id)->count(), 2);
        $post($this->b, $inv('sales', '2026-06-18', $bLedgers['outsideCust'], $bLedgers['sales'], $bLedgers['widget'], 40, 210.0, $bLedgers['main']));
        $lots = $lotsFor($this->b, $bLedgers['widget']->id);
        $this->expect('Lots fully depleted (30 IC + 10 non-IC, no error)', [(float) $lots[0]->remaining_qty, (float) $lots[1]->remaining_qty], [0.0, 0.0]);

        // ═══ 7. ALTER an inter-company Purchase — exact replay ══════════════════
        $this->section('Alter replays the lot state exactly');
        $gadPur = $post($this->b, $inv('purchase', '2026-06-20', $bLedgers['creditorA'], $bLedgers['purchase'], $bLedgers['gadget'], 100, 50.0, $bLedgers['main'], $this->a));
        $post($this->b, $inv('sales', '2026-06-21', $bLedgers['outsideCust'], $bLedgers['sales'], $bLedgers['gadget'], 30, 80.0, $bLedgers['main']));
        $this->expect('Gadget lot before alter: 100/70', [(float) $lotsFor($this->b, $bLedgers['gadget']->id)[0]->original_qty, (float) $lotsFor($this->b, $bLedgers['gadget']->id)[0]->remaining_qty], [100.0, 70.0]);
        $post($this->b, $inv('purchase', '2026-06-20', $bLedgers['creditorA'], $bLedgers['purchase'], $bLedgers['gadget'], 80, 50.0, $bLedgers['main'], $this->a, $gadPur['voucher']['id']));
        $lots = $lotsFor($this->b, $bLedgers['gadget']->id);
        $this->expect('After alter 100→80: ONE fresh lot, replayed to 80/50', [$lots->count(), (float) $lots[0]->original_qty, (float) $lots[0]->remaining_qty], [1, 80.0, 50.0]);

        // ═══ 8. CANCEL — cascade + refold ════════════════════════════════════════
        $this->section('Cancel cascades the lot and refolds the trace');
        ActiveCompany::runAs($this->b, fn () => Voucher::find($gadPur['voucher']['id'])->delete());
        $this->expect('Gadget lots gone with the cancelled Purchase', $lotsFor($this->b, $bLedgers['gadget']->id)->count(), 0);

        // ═══ 9. GODOWN TRANSFER — child lot, inherited FIFO position ════════════
        $this->section('Godown transfer splits a child lot (inherited received_date)');
        $post($this->b, $inv('purchase', '2026-06-22', $bLedgers['creditorA'], $bLedgers['purchase'], $bLedgers['gizmo'], 100, 60.0, $bLedgers['main'], $this->a));
        $post($this->b, ['type' => 'stock_journal', 'date' => '2026-06-23', 'lines' => [], 'movement' => [
            'mode' => 'transfer', 'stock_item_id' => $bLedgers['gizmo']->id,
            'from_godown_id' => $bLedgers['main'], 'to_godown_id' => $bLedgers['wh2']->id, 'qty' => 40,
        ]]);
        $lots = $lotsFor($this->b, $bLedgers['gizmo']->id);
        $this->expect('Parent lot: 60 remaining at Main', [(float) $lots[0]->remaining_qty, (int) $lots[0]->godown_id], [60.0, (int) $bLedgers['main']]);
        $this->expect('Child lot: 40 at Warehouse Two, genealogy set', [(float) $lots[1]->remaining_qty, (int) $lots[1]->godown_id, (int) $lots[1]->parent_lot_id], [40.0, $bLedgers['wh2']->id, (int) $lots[0]->id]);
        $this->expect('Child INHERITS the receipt date (FIFO position kept)', $lots[1]->received_date->toDateString(), '2026-06-22');
        $post($this->b, $inv('sales', '2026-06-24', $bLedgers['outsideCust'], $bLedgers['sales'], $bLedgers['gizmo'], 20, 90.0, $bLedgers['wh2']->id));
        $post($this->b, $inv('sales', '2026-06-25', $bLedgers['outsideCust'], $bLedgers['sales'], $bLedgers['gizmo'], 50, 90.0, $bLedgers['main']));
        $lots = $lotsFor($this->b, $bLedgers['gizmo']->id);
        $this->expect('Per-godown depletion: Main 60→10, WH2 40→20', [(float) $lots[0]->remaining_qty, (float) $lots[1]->remaining_qty], [10.0, 20.0]);

        // as-of replay (12C-2's period-end read): before the two sales
        $asOf = ActiveCompany::runAs($this->b, fn () => collect(app(StockLotService::class)
            ->remainingLotsFor($bLedgers['gizmo']->id, null, Carbon::parse('2026-06-23')))
            ->map(fn ($e) => $e['remaining'])->sort()->values()->all());
        $this->expect('remainingLotsFor(as-of 23-Jun) replays to [40, 60]', $asOf, [40.0, 60.0]);

        // ═══ 9b. ADVERSARIAL-REVIEW REGRESSIONS — locked in for good ═════════════
        $this->section('Workflow-note alter refolds (review fix): receipt + delivery notes');
        $wf = function (string $type, Ledger $party, StockItem $item, float $qty, float $rate, string $date, ?int $alterId = null) use ($bLedgers) {
            $p = ['type' => $type, 'date' => $date, 'party_ledger_id' => $party->id,
                'items' => [['stock_item_id' => $item->id, 'godown_id' => $bLedgers['main'], 'qty' => $qty, 'rate' => $rate]]];
            if ($alterId) {
                $p['voucher_id'] = $alterId;
            }

            return $p;
        };
        $doohick = ActiveCompany::runAs($this->b, fn () => StockItem::create([
            'name' => 'Doohickey', 'unit_id' => Unit::where('name', 'Numbers')->value('id'),
            'opening_qty' => 0, 'opening_rate' => 0, 'opening_value' => 0,
        ]));
        // Receipt Note from the groupmate → lot; a Delivery Note depletes it.
        $rn = $post($this->b, $wf('receipt_note', $bLedgers['creditorA'], $doohick, 100, 40.0, '2026-06-27'));
        $dn = $post($this->b, $wf('delivery_note', $bLedgers['outsideCust'], $doohick, 30, 40.0, '2026-06-28'));
        $this->expect('Receipt Note wrote the lot; Delivery Note depleted to 70',
            (float) $lotsFor($this->b, $doohick->id)[0]->remaining_qty, 70.0);
        // No-op alter of the DELIVERY note must NOT double-deplete (was 70→40).
        $post($this->b, $wf('delivery_note', $bLedgers['outsideCust'], $doohick, 30, 40.0, '2026-06-28', $dn['voucher']['id']));
        $this->expect('No-op DELIVERY-note alter: remaining STAYS 70 (no double-deplete)',
            (float) $lotsFor($this->b, $doohick->id)[0]->remaining_qty, 70.0);
        // No-op alter of the RECEIPT note must NOT resurrect the full lot (was →100).
        $post($this->b, $wf('receipt_note', $bLedgers['creditorA'], $doohick, 100, 40.0, '2026-06-27', $rn['voucher']['id']));
        $this->expect('No-op RECEIPT-note alter: depletion replayed, remaining STAYS 70',
            (float) $lotsFor($this->b, $doohick->id)->where('remaining_qty', '>', 0)->sum('remaining_qty'), 70.0);
        // Real alter: delivery 30 → 20 ⇒ replay to 80.
        $post($this->b, $wf('delivery_note', $bLedgers['outsideCust'], $doohick, 20, 40.0, '2026-06-28', $dn['voucher']['id']));
        $this->expect('Delivery-note alter 30→20 replays to 80',
            (float) $lotsFor($this->b, $doohick->id)->where('remaining_qty', '>', 0)->sum('remaining_qty'), 80.0);

        $this->section('Pre-receipt OUTs never deplete later lots (review fix — the date gate)');
        $thing = ActiveCompany::runAs($this->b, fn () => StockItem::create([
            'name' => 'Thingamajig', 'unit_id' => Unit::where('name', 'Numbers')->value('id'),
            'opening_qty' => 0, 'opening_rate' => 0, 'opening_value' => 0,
        ]));
        // Ordinary history FIRST: outside purchase Jun-1, sale Jun-5 …
        $post($this->b, $inv('purchase', '2026-06-01', $bLedgers['outsideSupp'], $bLedgers['purchase'], $thing, 100, 10.0, $bLedgers['main']));
        $preSale = $post($this->b, $inv('sales', '2026-06-05', $bLedgers['outsideCust'], $bLedgers['sales'], $thing, 30, 15.0, $bLedgers['main']));
        // … THEN the first inter-company receipt on Jul-1.
        $post($this->b, $inv('purchase', '2026-07-01', $bLedgers['creditorA'], $bLedgers['purchase'], $thing, 50, 12.0, $bLedgers['main'], $this->a));
        $this->expect('Lot written 50/50 (the Jun-5 sale cannot contain July units)',
            (float) $lotsFor($this->b, $thing->id)[0]->remaining_qty, 50.0);
        // A byte-identical re-save of the Jun-5 sale triggers a refold — remaining
        // must STAY 50 (the probe caught 50→20 before the date gate).
        $post($this->b, $inv('sales', '2026-06-05', $bLedgers['outsideCust'], $bLedgers['sales'], $thing, 30, 15.0, $bLedgers['main'], null, $preSale['voucher']['id']));
        $this->expect('No-op alter of the PRE-RECEIPT sale: lot STAYS 50 (date-gated replay)',
            (float) $lotsFor($this->b, $thing->id)[0]->remaining_qty, 50.0);
        // And the as-of replay agrees.
        $asOfThing = ActiveCompany::runAs($this->b, fn () => collect(app(StockLotService::class)
            ->remainingLotsFor($thing->id, null, Carbon::parse('2026-07-02')))->sum('remaining'));
        $this->expect('as-of replay agrees (date-gated): 50', (float) $asOfThing, 50.0);

        $this->section('as-of entries carry the EFFECTIVE godown (review fix)');
        $gizmoAsOf = ActiveCompany::runAs($this->b, fn () => collect(app(StockLotService::class)
            ->remainingLotsFor($bLedgers['gizmo']->id, null, Carbon::parse('2026-06-23')))
            ->map(fn ($e) => [$e['godown_id'], $e['remaining']])->sortBy(fn ($x) => $x[1])->values()->all());
        $this->expect('Split portions report destination godowns: [WH2×40, Main×60]',
            $gizmoAsOf, [[$bLedgers['wh2']->id, 40.0], [(int) $bLedgers['main'], 60.0]]);

        // ═══ 10. Valuation + Trial Balance untouched; engine proofs green ═══════
        $this->section('Valuation + TB untouched; engine proofs re-run inside this tenant');
        $tbB = ActiveCompany::runAs($this->b, fn () => app(BalanceService::class)->trialBalance(Carbon::parse('2026-04-01'), Carbon::parse('2027-03-31')));
        $this->expect('[B] TB balanced with the whole lot lifecycle behind it', $tbB['balanced'], true);
        $tbA = ActiveCompany::runAs($this->a, fn () => app(BalanceService::class)->trialBalance(Carbon::parse('2026-04-01'), Carbon::parse('2027-03-31')));
        $this->expect('[A] TB balanced', $tbA['balanced'], true);
        foreach (['prove-balance', 'prove-inventory-integration'] as $p) {
            $this->expect("zerobook:{$p} green inside this tenant", Artisan::call('zerobook:'.$p), self::SUCCESS);
        }

        // ═══ 11. Ungrouped company: perfectly inert ══════════════════════════════
        $this->section('Ungrouped company writes zero lots (the CA-firm case)');
        $c = app(CompanyProvisioner::class)->create('Chi Solo Co')->id;
        ActiveCompany::runAs($c, function () {
            $gid = fn (string $n) => AccountGroup::where('name', $n)->value('id');
            $supp = Ledger::create(['name' => 'Some Supplier', 'group_id' => $gid('Sundry Creditors')]);
            $pur = Ledger::create(['name' => 'Purchase A/c', 'group_id' => $gid('Purchase Accounts')]);
            $unit = Unit::create(['name' => 'Numbers', 'symbol' => 'Nos', 'decimal_places' => 0]);
            $item = StockItem::create(['name' => 'Widget', 'unit_id' => $unit->id, 'opening_qty' => 0, 'opening_rate' => 0, 'opening_value' => 0]);
            (new VoucherScreen())->post([
                'type' => 'purchase', 'date' => '2026-06-26', 'party_ledger_id' => $supp->id,
                'lines' => [
                    ['ledger_id' => $supp->id, 'dr_cr' => 'Cr', 'amount' => 1000],
                    ['ledger_id' => $pur->id, 'dr_cr' => 'Dr', 'amount' => 1000],
                ],
                'items' => [['stock_item_id' => $item->id, 'godown_id' => Godown::where('name', 'Main Location')->value('id'), 'qty' => 10, 'rate' => 100]],
            ]);
        });
        $cLots = StockLot::withoutGlobalScope('company')->where('company_id', $c)->count();
        $this->expect('[C/ungrouped] zero lot rows tenant-wide', $cLots, 0);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function section(string $title): void
    {
        $this->line('');
        $this->line("── {$title} ".str_repeat('─', max(1, 60 - mb_strlen($title))));
    }

    private function expect(string $label, mixed $actual, mixed $expected): void
    {
        $pass = $actual === $expected;
        if (! $pass) {
            $this->ok = false;
        }
        $this->line(sprintf(' [%s] %s = %s%s',
            $pass ? 'PASS' : 'FAIL', $label, json_encode($actual),
            $pass ? '' : ' (expected '.json_encode($expected).')'));
    }

    private function expectClose(string $label, float $actual, float $expected, float $eps = 0.01): void
    {
        $pass = abs($actual - $expected) < $eps;
        if (! $pass) {
            $this->ok = false;
        }
        $this->line(sprintf(' [%s] %s = %.4f%s', $pass ? 'PASS' : 'FAIL', $label, $actual,
            $pass ? '' : sprintf(' (expected %.4f)', $expected)));
    }
}
