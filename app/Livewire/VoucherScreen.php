<?php

namespace App\Livewire;

use App\Livewire\Concerns\GuardsActiveCompany;
use App\Livewire\Concerns\CreatesGroups;
use App\Livewire\Concerns\CreatesLedgers;
use App\Livewire\Concerns\CreatesStockItems;
use App\Models\AccountGroup;
use App\Models\Godown;
use App\Models\Ledger;
use App\Models\OrderFulfillment;
use App\Models\OrderLine;
use App\Models\StockEntry;
use App\Models\StockGroup;
use App\Models\StockItem;
use App\Models\Unit;
use App\Models\Voucher;
use App\Models\VoucherEntry;
use App\Models\CostCentre;
use App\Services\BalanceService;
use App\Services\BillService;
use App\Services\CostCentreService;
use App\Services\OrderService;
use App\Services\ForexService;
use App\Services\StockService;
use App\Services\TallyImport\BulkMode;
use App\Services\TdsService;
use App\Support\TenantGate;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class VoucherScreen extends Component
{
    use GuardsActiveCompany;
    use CreatesGroups;   // Alt+C group create (inside the quick-ledger Under picker)
    use CreatesLedgers;  // Alt+C ledger create (on a voucher line)
    use CreatesStockItems; // Alt+C stock-item create (on an item-invoice line)

    public string $initialType = 'payment';
    public ?int $editVoucherId = null;

    public function mount(?string $type = null, ?Voucher $voucher = null): void
    {
        if ($voucher && $voucher->exists) {
            $this->editVoucherId = $voucher->id;
            $this->initialType = $voucher->type;
        } elseif ($type && array_key_exists($type, Voucher::TYPES)) {
            $this->initialType = $type;
        }
    }

    /** Everything the Alpine voucher controller needs to run fully client-side. */
    public function bootData(): array
    {
        $today = Carbon::today();
        $fyStart = Voucher::fyStartFor($today);

        $nextNumbers = [];
        foreach (array_keys(Voucher::TYPES) as $t) {
            $nextNumbers[$t] = Voucher::nextNumber($t, $fyStart);
        }

        $edit = null;
        if ($this->editVoucherId) {
            $v = Voucher::with(['entries.ledger', 'partyLedger', 'stockEntries.stockItem', 'stockEntries.godown'])->find($this->editVoucherId);
            if ($v) {
                $allocByEntry = app(BillService::class)->allocationsForVoucher($v->id);
                $costByEntry = app(CostCentreService::class)->allocationsForVoucher($v->id);
                // Rebuild the item rows from the saved movement. The row's rate is the
                // SELLING rate (sale_rate) on a sale and the entered cost on a purchase
                // — the client never sees a computed cost for a sale.
                $editItems = $v->stockEntries->map(fn ($e) => [
                    'stock_item_id' => $e->stock_item_id,
                    'stock_item_label' => $e->stockItem?->name,
                    'godown_id' => $e->godown_id,
                    'godown_label' => $e->godown?->name,
                    'qty' => (float) $e->quantity,
                    'rate' => $e->direction === 'out' ? (float) $e->sale_rate : (float) $e->rate,
                ])->values()->all();
                // Rebuild the Phase 6C stock-movement body from the saved rows so the
                // stock voucher re-opens pre-filled (counted qty is book + variance).
                $editMovement = $this->rebuildEditMovement($v);
                $edit = [
                    'id' => $v->id,
                    'type' => $v->type,
                    'number' => $v->number,
                    'date' => $v->date->toDateString(),
                    'narration' => $v->narration,
                    // Phase 15C — the provisional tag, so re-opening a scenario voucher keeps it
                    // under its scenario (the selector re-shows it; blank = a real voucher).
                    'scenario_id' => $v->scenario_id,
                    // Invoice metadata — drives the As-Invoice layout on re-open.
                    'is_invoice' => $v->isInvoice(),
                    'party_ledger_id' => $v->party_ledger_id,
                    'party_ledger_label' => $v->partyLedger?->name,
                    'reference_no' => $v->reference_no,
                    'reference_voucher_id' => $v->reference_voucher_id,
                    'reference_label' => $v->referenceVoucher ? $v->referenceVoucher->displayNumber() : null,
                    'reference_date' => $v->reference_date?->toDateString(),
                    'has_items' => count($editItems) > 0,
                    'items' => $editItems,
                    'movement' => $editMovement,
                    // Phase 10A — the saved deduction, so the TDS panel re-opens engaged
                    // and the client can subtract this voucher's own contribution from the
                    // year-to-date cache (exactly as the server's excludeVoucherId does).
                    'tds_deduction' => app(TdsService::class)->deductionForVoucher($v->id),
                    // Phase 10B — the saved bank challan, so a remittance re-opens with its
                    // BSR / challan number / deposit date filled in.
                    'tds_challan' => app(TdsService::class)->challanForVoucher($v->id),
                    'lines' => $v->entries->map(fn ($e) => [
                        'ledger_id' => $e->ledger_id,
                        'ledger_label' => $e->ledger?->name,
                        'dr_cr' => $e->dr_cr,
                        'amount' => (float) $e->amount,
                        // Phase 11 — preserve the dual-currency shape through alter.
                        'currency_id' => $e->currency_id,
                        'foreign_amount' => $e->foreign_amount !== null ? (float) $e->foreign_amount : null,
                        'exchange_rate' => $e->exchange_rate !== null ? (float) $e->exchange_rate : null,
                        'allocations' => $allocByEntry[$e->id] ?? [],
                        'cost_allocations' => $costByEntry[$e->id] ?? [],
                    ])->values()->all(),
                ];
            }
        }

        return [
            'types' => Voucher::TYPES,
            'invoiceTypes' => Voucher::INVOICE_TYPES,
            'initialType' => $this->initialType,
            'nextNumbers' => $nextNumbers,
            'fyStart' => $fyStart,
            'fyLabel' => Voucher::fyLabel($fyStart),
            'today' => $today->toDateString(),
            'todayLabel' => $today->format('d-M-Y'),
            // Default groups for inline Alt+C create in invoice mode: the party
            // ledger goes under Sundry Debtors (Sales) / Creditors (Purchase),
            // and the ledger allocation under Sales / Purchase Accounts.
            'invoiceGroups' => $this->invoiceGroups(),
            // GST context: enabled + company state + the six duty-ledger ids, so
            // invoice mode computes the tax and injects the tax lines client-side.
            'gst' => \App\Support\Shell::gstConfig(),
            // Nepal VAT context (mutually exclusive with GST) — a single flat rate.
            'vat' => \App\Support\Shell::vatConfig(),
            // Bill-wise context: the F11 flag + open bills per party ledger, so the
            // allocation sub-screen (incl. the Against-Ref picker) runs 0-network.
            'billEnabled' => app(BillService::class)->enabled(),
            'openBills' => app(BillService::class)->openBillsCache(),
            // Cost-centre context: the F11 flag + the cost-centre list, so the cost
            // allocation sub-screen picks a centre 0-network (Phase 5D).
            'costEnabled' => app(CostCentreService::class)->enabled(),
            'costCentres' => CostCentre::orderBy('name')->get()->map->toCache()->all(),
            // TDS context (Phase 10A): the F11 flag, the effective rate table, the tagged
            // deductees and the running year-to-date state — so the Payment screen can show
            // the deduction live, with zero network round-trips, while the user types. The
            // server independently recomputes and re-verifies every one of these numbers on
            // accept, so nothing here is ever an authority.
            ...$this->tdsBootData(),
            // Multi-currency context (Phase 11): the currency list + latest rates, so the
            // voucher screen shows foreign amount/rate/INR live on a foreign-ledger line and
            // pre-fills the settlement gain/loss — all 0-network. The server re-verifies.
            ...app(ForexService::class)->bootData(),
            // Phase 12B — group membership + linked-company names, so the client can
            // show the Inter-Company badge and pre-fill the tag with zero network.
            ...app(\App\Services\InterCompanyService::class)->bootData(),
            // The special Profit & Loss A/c ledger is a computed account — not a
            // postable voucher line — so it is kept out of the picker.
            'ledgers' => Ledger::with(['group', 'currency'])->where('is_pl_account', false)->orderBy('name')->get()->map->toCache()->all(),
            'groups' => AccountGroup::orderBy('name')->get()->map->toCache()->all(),
            // Inventory context (Phase 6B): the item picker + godown picker run
            // 0-network from these caches; units/stockGroups back the inline Alt+C
            // stock-item create. stockEnabled gates the Item ⇄ Accounting toggle.
            'stockEnabled' => StockItem::exists(),
            'stockItems' => StockItem::with(['stockGroup', 'unit'])->orderBy('name')->get()->map->toCache()->all(),
            'godowns' => Godown::orderBy('name')->get()->map->toCache()->all(),
            'units' => Unit::orderBy('name')->get()->map->toCache()->all(),
            'stockGroups' => StockGroup::orderBy('name')->get()->map->toCache()->all(),
            'itemInvoiceDefaults' => $this->itemInvoiceDefaults(),
            // Phase 8A — the invoices a Note can reference (client-side picker; 0
            // network to filter). Only shipped for a Debit/Credit Note screen.
            'referenceInvoices' => in_array($this->initialType, Voucher::NOTE_TYPES, true) ? $this->referenceInvoiceList() : [],
            // Phase 8B — inventory-workflow metadata + the open orders/notes each
            // workflow voucher may reference. Shipped only for the relevant screen.
            'workflowTypes' => Voucher::INVENTORY_WORKFLOW_TYPES,
            'orderTypes' => Voucher::ORDER_TYPES,
            'stockWorkflowTypes' => Voucher::STOCK_WORKFLOW_TYPES,
            'referenceOrders' => in_array($this->initialType, Voucher::STOCK_WORKFLOW_TYPES, true) ? $this->workflowReferenceList($this->initialType) : [],
            // Delivery/Receipt Notes a Sales/Purchase invoice may bill against — the
            // client half of the double-stock safeguard's picker.
            'referenceDeliveries' => in_array($this->initialType, ['sales', 'purchase'], true) ? $this->deliveryReferenceList($this->initialType) : [],
            'mainGodownId' => Godown::where('name', 'Main Location')->value('id') ?? Godown::orderBy('id')->value('id'),
            'balances' => $this->currentBalances(),
            // Phase 15C — provisional scenarios: the F11 flag + the active scenarios, so the
            // entry screen can tag a NEW voucher to a what-if scenario 0-network. The server
            // re-checks the flag + company scope on accept (validatePayload), so this is a
            // convenience, never an authority. Empty when the feature is off → no selector.
            'scenariosEnabled' => (bool) \App\Models\CompanyFeature::current()->scenarios,
            'scenarios' => \App\Models\Scenario::query()->where('is_active', true)->orderBy('name')
                ->get(['id', 'name'])->map(fn ($s) => ['id' => (int) $s->id, 'name' => $s->name])->all(),
            'edit' => $edit,
        ];
    }

    /**
     * Phase 10A — everything the Payment screen's TDS panel needs to run 0-network:
     * the F11 flag, the TDS Payable duty ledger, the sections in force for the CURRENT
     * fiscal year, the deductee-tagged party ledgers, and the running year-to-date state.
     *
     * The client uses these to show a live approximation of the deduction while the user
     * types. TdsService::verifyPayload() then recomputes the whole thing server-side on
     * accept and rejects any disagreement — this cache is a convenience, never a source
     * of truth. When TDS is off, every list is empty and the panel never renders.
     */
    private function tdsBootData(): array
    {
        $tds = app(TdsService::class);
        if (! $tds->enabled()) {
            return [
                'tdsEnabled' => false,
                'tdsPayableLedgerId' => null,
                'tdsSections' => [],
                'tdsDeductees' => [],
                'tdsLedger' => (object) [],
            ];
        }

        // Phase 12A — TDS section effectivity is STATUTORY Apr–Mar, not books FY.
        $fyStart = Voucher::statutoryFyStartFor(Carbon::today());

        return [
            'tdsEnabled' => true,
            'tdsPayableLedgerId' => $tds->payableLedgerId(),
            // Only the sections legally in force this year are offered. A repealed
            // 194-series entry stays in the table (a historical voucher still needs it)
            // but never reaches the picker.
            'tdsSections' => $tds->sections($fyStart),
            'tdsDeductees' => $tds->deducteeCache(),
            // { "deducteeId:sectionId": [ {voucher_id, date, payment, deducted}, … ] }
            // The individual payments, not just their totals, so the client can reproduce
            // the monthly (194I) and single-bill (194C) windows exactly. Cast to an object
            // so an empty map serialises as {} rather than [].
            'tdsLedger' => (object) $tds->clientLedgerCache($fyStart),
        ];
    }

    /** The default revenue ledger (first under Sales/Purchase Accounts) each item
     *  invoice posts Σ item amounts to. The user can pick a different one. */
    private function itemInvoiceDefaults(): array
    {
        // [type => [group, preferred ledger name]]. A Note prefers its Return ledger;
        // Sales/Purchase pick the first non-Return nominal in the group.
        $map = [
            'sales' => ['Sales Accounts', null],
            'purchase' => ['Purchase Accounts', null],
            'credit_note' => ['Sales Accounts', 'Sales Return'],
            'debit_note' => ['Purchase Accounts', 'Purchase Return'],
        ];
        $out = [];
        foreach ($map as $type => [$groupName, $preferred]) {
            $groupId = AccountGroup::where('name', $groupName)->value('id');
            $led = null;
            if ($groupId) {
                if ($preferred) {
                    $led = Ledger::where('group_id', $groupId)->where('name', $preferred)->first();
                }
                $led = $led ?: Ledger::where('group_id', $groupId)
                    ->where('is_pl_account', false)
                    ->whereNotIn('name', ['Sales Return', 'Purchase Return'])
                    ->orderBy('name')->first();
            }
            $out[$type] = $led
                ? ['ledger_id' => $led->id, 'ledger_label' => $led->name]
                : ['ledger_id' => null, 'ledger_label' => ''];
        }

        return $out;
    }

    /** Default groups used to pre-fill inline party/ledger create in invoice mode. */
    private function invoiceGroups(): array
    {
        $byName = AccountGroup::whereIn('name', ['Sundry Debtors', 'Sundry Creditors', 'Sales Accounts', 'Purchase Accounts'])
            ->pluck('id', 'name');

        $pack = fn (?string $partyName, ?string $ledgerName) => [
            'party_group_id' => $byName[$partyName] ?? null,
            'party_group_label' => $partyName,
            'ledger_group_id' => $byName[$ledgerName] ?? null,
            'ledger_group_label' => $ledgerName,
        ];

        return [
            'sales' => $pack('Sundry Debtors', 'Sales Accounts'),
            'purchase' => $pack('Sundry Creditors', 'Purchase Accounts'),
            // Phase 8A — Credit Note is customer-side (Debtors); Debit Note supplier-side.
            'credit_note' => $pack('Sundry Debtors', 'Sales Accounts'),
            'debit_note' => $pack('Sundry Creditors', 'Purchase Accounts'),
        ];
    }

    /**
     * Phase 8A — invoices a Debit/Credit Note may reference, for the client-side
     * picker (filtering is 0-network). Credit Notes reference Sales invoices; Debit
     * Notes reference Purchase invoices. Bounded to the most recent 150.
     *
     * @return array{credit_note:array,debit_note:array}
     */
    private function referenceInvoiceList(): array
    {
        $pick = fn (string $invoiceType) => Voucher::where('type', $invoiceType)
            ->whereNotNull('party_ledger_id')
            ->with('partyLedger')
            ->orderByDesc('date')->orderByDesc('id')
            ->limit(150)->get()
            ->map(fn ($v) => [
                'id' => $v->id,
                'display_number' => $v->displayNumber(),
                'date' => $v->date->toDateString(),
                'date_label' => $v->date->format('d-M-Y'),
                'party_ledger_id' => $v->party_ledger_id,
                'party_label' => $v->partyLedger?->name,
                'amount' => $v->totalDr(),
            ])->all();

        return [
            'credit_note' => $pick('sales'),
            'debit_note' => $pick('purchase'),
        ];
    }

    /**
     * Phase 8A — the item lines of an original invoice, for pre-filling a Note that
     * references it. Server-side lookup (the client never fabricates a cost). The
     * rate shown is the SELLING/purchase rate (the return/refund amount); the cost
     * is derived server-side at post time from the original stock movement.
     *
     * @return array{ok:bool, id?:int, display_number?:string, date?:string, party_ledger_id?:int, party_ledger_label?:?string, items?:array}
     */
    public function referenceInvoice(int $voucherId): array
    {
        $this->skipRender(); // pure lookup — the client owns the screen state
        $v = Voucher::with(['stockEntries.stockItem.unit', 'stockEntries.godown', 'partyLedger'])->find($voucherId);
        if (! $v || ! in_array($v->type, ['sales', 'purchase'], true)) {
            return ['ok' => false];
        }

        $items = $v->stockEntries->map(fn ($e) => [
            'stock_item_id' => $e->stock_item_id,
            'stock_item_label' => $e->stockItem?->name,
            'godown_id' => $e->godown_id,
            'godown_label' => $e->godown?->name,
            'qty' => (float) $e->quantity,
            'rate' => $e->direction === 'out' ? (float) $e->sale_rate : (float) $e->rate,
        ])->values()->all();

        return [
            'ok' => true,
            'id' => $v->id,
            'display_number' => $v->displayNumber(),
            'date' => $v->date->toDateString(),
            'party_ledger_id' => $v->party_ledger_id,
            'party_ledger_label' => $v->partyLedger?->name,
            'items' => $items,
        ];
    }

    /**
     * Phase 8B — the item lines to pre-fill from a referenced Order or Delivery/Receipt
     * Note. For an ORDER the lines are its OUTSTANDING (pending) quantities so a Delivery
     * Note starts pre-filled with exactly what is still owed; for a NOTE they are its
     * moved quantities (a Sales invoice billing a Delivery Note, or a Rejection). One
     * server endpoint drives both the order picker and the double-stock picker.
     */
    public function referenceOrder(int $voucherId): array
    {
        $this->skipRender(); // pure lookup — the client owns the screen state
        $v = Voucher::with(['orderLines.stockItem', 'orderLines.godown', 'stockEntries.stockItem', 'stockEntries.godown', 'partyLedger'])->find($voucherId);
        if (! $v || ! in_array($v->type, Voucher::INVENTORY_WORKFLOW_TYPES, true)) {
            return ['ok' => false];
        }

        if (in_array($v->type, Voucher::ORDER_TYPES, true)) {
            $items = $v->orderLines
                ->filter(fn ($ol) => $ol->pendingQty() > 1e-9)
                ->map(fn ($ol) => [
                    'stock_item_id' => $ol->stock_item_id,
                    'stock_item_label' => $ol->stockItem?->name,
                    'godown_id' => $ol->godown_id,
                    'godown_label' => $ol->godown?->name,
                    'qty' => round($ol->pendingQty(), 4),
                    'rate' => (float) $ol->rate,
                ])->values()->all();
        } else {
            $items = $v->stockEntries->map(fn ($e) => [
                'stock_item_id' => $e->stock_item_id,
                'stock_item_label' => $e->stockItem?->name,
                'godown_id' => $e->godown_id,
                'godown_label' => $e->godown?->name,
                'qty' => (float) $e->quantity,
                'rate' => $e->direction === 'out' ? (float) $e->sale_rate : (float) $e->rate,
            ])->values()->all();
        }

        return [
            'ok' => true,
            'id' => $v->id,
            'display_number' => $v->displayNumber(),
            'date' => $v->date->toDateString(),
            'party_ledger_id' => $v->party_ledger_id,
            'party_ledger_label' => $v->partyLedger?->name,
            'items' => $items,
        ];
    }

    /**
     * Phase 8B — the open orders/notes a Delivery/Receipt Note or Rejection may
     * reference, for the client-side picker. Delivery ← open Sales Orders (pending > 0);
     * Receipt ← open Purchase Orders; Rejections ← the recent Receipt/Delivery Notes.
     *
     * @return array<string, array>
     */
    private function workflowReferenceList(string $type): array
    {
        $source = match ($type) {
            'delivery_note' => 'sales_order',
            'receipt_note' => 'purchase_order',
            'rejection_out' => 'receipt_note',
            'rejection_in' => 'delivery_note',
            default => null,
        };
        if (! $source) {
            return [];
        }

        $fmtQty = fn (float $q) => rtrim(rtrim(number_format($q, 4, '.', ''), '0'), '.');

        if (in_array($source, Voucher::ORDER_TYPES, true)) {
            // Only orders with something still outstanding are worth delivering against.
            $rows = Voucher::where('type', $source)
                ->with(['orderLines', 'partyLedger'])
                ->orderByDesc('date')->orderByDesc('id')->limit(150)->get()
                ->map(fn ($v) => ['v' => $v, 'pending' => (float) $v->orderLines->sum(fn ($ol) => max(0.0, $ol->pendingQty()))])
                ->filter(fn ($r) => $r['pending'] > 1e-9)
                ->map(fn ($r) => [
                    'id' => $r['v']->id,
                    'display_number' => $r['v']->displayNumber(),
                    'date_label' => $r['v']->date->format('d-M-Y'),
                    'party_label' => $r['v']->partyLedger?->name,
                    'pending_label' => $fmtQty($r['pending']).' pending',
                ])->values()->all();
        } else {
            $rows = Voucher::where('type', $source)
                ->with('partyLedger')
                ->orderByDesc('date')->orderByDesc('id')->limit(150)->get()
                ->map(fn ($v) => [
                    'id' => $v->id,
                    'display_number' => $v->displayNumber(),
                    'date_label' => $v->date->format('d-M-Y'),
                    'party_label' => $v->partyLedger?->name,
                    'pending_label' => 'goods note',
                ])->all();
        }

        return [$type => $rows];
    }

    /**
     * Phase 8B — the Delivery/Receipt Notes a Sales/Purchase invoice may bill against
     * (the double-stock picker). Sales ← Delivery Notes; Purchase ← Receipt Notes.
     *
     * @return array<string, array>
     */
    private function deliveryReferenceList(string $type): array
    {
        $noteType = $type === 'sales' ? 'delivery_note' : 'receipt_note';
        $rows = Voucher::where('type', $noteType)
            ->with('partyLedger')
            ->orderByDesc('date')->orderByDesc('id')->limit(150)->get()
            ->map(fn ($v) => [
                'id' => $v->id,
                'display_number' => $v->displayNumber(),
                'date_label' => $v->date->format('d-M-Y'),
                'party_label' => $v->partyLedger?->name,
                'pending_label' => 'stock already moved',
            ])->all();

        return [$type => $rows];
    }

    /** Current closing balance per ledger (as of today) for the line display. */
    private function currentBalances(): array
    {
        $closings = app(BalanceService::class)->ledgerClosings(Carbon::today());
        $out = [];
        foreach ($closings as $id => $paise) {
            $out[$id] = ['amount' => BalanceService::money($paise), 'side' => BalanceService::drcr($paise)];
        }

        return $out;
    }

    /**
     * Persist (or re-post) a voucher. Server is authoritative on the double-entry
     * balance and on numbering. $payload = { type, date, narration, lines[], voucher_id? }.
     * @return array{voucher: array, nextNumber: int}
     */
    public function post(array $payload): array
    {
        // This screen is Alpine-driven and commit-only: the client never uses the HTML
        // Livewire would send back, and re-rendering it rewrites the root element's
        // x-data attribute (bootData's nextNumbers/balances change on every post), which
        // makes Alpine tear the component down and re-initialise it mid-morph. That threw
        // away the entry state, duplicated the pushed context, and produced a burst of
        // "line is not defined" warnings from the x-for children. Skipping the render
        // leaves the client in charge of its own state, which it already was.
        $this->skipRender();

        // Phase 14A — a suspended / expired-trial tenant may READ but not post. A
        // view-only impersonation session is refused here too. Thrown before any
        // validation or write, so nothing is persisted and the client shows the reason.
        TenantGate::assertWritable();

        $data = $this->validatePayload($payload);
        $voucherId = $payload['voucher_id'] ?? null;

        // Phase 16C — capture the voucher's outgoing bill contribution BEFORE the write, because
        // an alter REPLACES its allocations and the old state is unrecoverable afterwards. A plain
        // SELECT, skipped entirely when no webhook subscription wants it, and total (it swallows
        // its own failures and returns []) — it cannot affect this post.
        $webhooks = app(\App\Services\Api\Webhooks\WebhookVoucherEvents::class);
        $billsBefore = $webhooks->captureBefore($voucherId ? (int) $voucherId : null);

        // Phase 15C — post a provisional voucher UNDER its scenario so the weighted-average cost,
        // TDS prior-state and bill reads during the write see real + this-scenario history; a real
        // voucher posts under real-books-only regardless of any ambient report picker selection.
        // On ALTER the write context follows the voucher's STORED tag (persistAlter never re-tags a
        // voucher), NOT the client payload — otherwise a client-sent scenario_id could make a REAL
        // voucher's stock/TDS/bill reads blend in provisional history and write tainted COGS.
        $sid = $voucherId
            ? Voucher::whereKey((int) $voucherId)->value('scenario_id')
            : ($data['scenario_id'] ?? null);
        $result = \App\Support\ScenarioContext::runWith($sid ? [(int) $sid] : [], fn () => $voucherId
            ? $this->persistAlter((int) $voucherId, $data)
            : $this->persistNew($data));

        // Phase 16C — queue the outbound webhook event. Registered through DB::afterCommit, so it
        // fires ONLY if the voucher actually commits: a rolled-back post emits nothing, a
        // rolled-back numbering RETRY emits nothing (its callback is discarded while the committed
        // attempt's fires once), and the importer's dry-run emits nothing. The delivery-row INSERT
        // therefore lands OUTSIDE this transaction, where it cannot roll the voucher back. The call
        // is total — it swallows its own failures — so a webhook problem can never break a post.
        $webhooks->afterWrite($result, (bool) $voucherId, $billsBefore);

        return [
            'voucher' => $result->toRow(),
            'nextNumber' => Voucher::nextNumber($result->type, $result->fy_start),
            // Phase 10A — hand the just-recorded deduction back so a continuous-entry
            // session can fold it into its year-to-date cache. Without it, the NEXT
            // payment to the same deductee would be computed against a stale aggregate,
            // the client would under-deduct, and the server would (correctly) refuse it.
            'tds' => app(TdsService::class)->deductionForVoucher($result->id),
        ];
    }

    /**
     * Create a new voucher. Each attempt is its own transaction (fresh snapshot),
     * so a numbering collision from a concurrent post is retried with the next
     * number rather than surfacing a 500.
     */
    private function persistNew(array $data): Voucher
    {
        $fyStart = Voucher::fyStartFor(Carbon::parse($data['date']));

        // Bulk import: one process, one transaction, numbers pre-assigned by the
        // importer's per-(type,fy) cursor. There is no concurrent poster to race, so
        // the SELECT-MAX numbering + duplicate-key retry loop is pure overhead and is
        // skipped; the graph is written straight into the importer's own surrounding
        // transaction (no nested transaction) and rolled back wholesale on any error.
        // Every validation gate already ran in validatePayload() — nothing here is
        // bypassed but the concurrency machinery.
        if (BulkMode::isActive()) {
            $number = $data['number'] ?? Voucher::nextNumber($data['type'], $fyStart);

            return $this->writeVoucherGraph($data, $fyStart, (int) $number);
        }

        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(fn () => $this->writeVoucherGraph(
                    $data,
                    $fyStart,
                    Voucher::nextNumber($data['type'], $fyStart),
                ));
            } catch (QueryException $e) {
                if ($attempt >= 5 || ! $this->isDuplicateKey($e)) {
                    throw $e;
                }
                usleep(2000 * $attempt); // brief backoff, then retry with a fresh number
            }
        }
    }

    /**
     * Write the full voucher graph — header, balanced entries, bill/cost allocations
     * and any stock movement — with a caller-supplied number. Shared verbatim by the
     * interactive path (each call wrapped in its own retrying transaction) and the
     * bulk-import path (written into the importer's surrounding transaction). The
     * ONLY things that differ between the two callers are who owns the transaction
     * and where the number comes from; every downstream service call is identical,
     * so the balance/tax/bill/cost invariants apply equally to imported vouchers.
     */
    private function writeVoucherGraph(array $data, int $fyStart, int $number): Voucher
    {
        $voucher = Voucher::create([
            'type' => $data['type'],
            'number' => $number,
            'fy_start' => $fyStart,
            'date' => $data['date'],
            'narration' => $data['narration'],
            'party_ledger_id' => $data['party_ledger_id'],
            'reference_no' => $data['reference_no'],
            'reference_date' => $data['reference_date'],
            'reference_voucher_id' => $data['reference_voucher_id'],
            // Phase 15C — the provisional tag (null = real). A provisional voucher is otherwise a
            // fully-formed voucher: same balance gate, tax authority, bill-wise, stock — just this tag.
            'scenario_id' => $data['scenario_id'] ?? null,
        ]);
        // Phase 8B — Sales/Purchase Orders are pure COMMITMENTS: the item lines become
        // order_lines and nothing else. No ledger entries, no stock, no bill/cost, no
        // tax — the Trial Balance and stock ledger are untouched.
        if (in_array($voucher->type, Voucher::ORDER_TYPES, true)) {
            app(OrderService::class)->persistOrderLines($voucher, $data['items'] ?? []);

            return $voucher->fresh();
        }

        // Phase 8B — Delivery/Receipt Notes and Rejections move REAL stock but post NO
        // ledger entries. Write the stock rows, then reconcile against any order the
        // note references (order_fulfillments + delivered_qty; over-delivery gated).
        if (in_array($voucher->type, Voucher::STOCK_WORKFLOW_TYPES, true)) {
            app(StockService::class)->persistItems($voucher, $data['items'] ?? []);
            app(OrderService::class)->applyFulfillment($voucher);

            return $voucher->fresh();
        }

        $entryIds = $this->writeEntries($voucher, $data['lines']);
        app(BillService::class)->persist($voucher, $data['lines'], $entryIds);
        app(CostCentreService::class)->persist($voucher, $data['lines'], $entryIds);
        // Phase 10A — record the TDS deduction and roll the deductee's year-to-date
        // threshold state forward, inside this same transaction. The ledger postings and
        // the threshold state therefore commit or roll back together: a concurrent post
        // can never read an aggregate that includes a voucher that failed.
        app(TdsService::class)->persist($voucher, $data, $entryIds);
        // Phase 10B — record the bank challan identifier for a remittance (Dr TDS Payable),
        // in the same transaction. A no-op unless the voucher debits TDS Payable.
        app(TdsService::class)->persistChallan($voucher, $data);
        // Phase 12B — record the inter-company tag (re-derived from the persisted
        // lines, never from the client's declaration) in the same transaction.
        app(\App\Services\InterCompanyService::class)->persist($voucher, $data);
        // Stock movement rides the SAME transaction; it does not touch the Dr/Cr
        // balance (items are not voucher entries). Phase 8B double-stock safeguard:
        // an invoice that references a Delivery/Receipt Note must NOT move stock again
        // — the note already did — so persistItems is SKIPPED for it, and the assertion
        // then verifies not one stock row slipped through.
        if (! $this->referencesStockMovement($voucher)) {
            app(StockService::class)->persistItems($voucher, $data['items'] ?? []);
        }
        $this->assertNoDoubleStock($voucher);
        $this->persistStockMovement($voucher, $data);

        return $voucher->fresh('entries.ledger');
    }

    /**
     * Re-post an existing voucher. Type, number and fy_start stay stable (so the
     * audit sequence is never disturbed); only date/narration and the balanced
     * entry set are replaced. Locks the row and handles a concurrent cancel.
     */
    private function persistAlter(int $voucherId, array $data): Voucher
    {
        return DB::transaction(function () use ($voucherId, $data) {
            $voucher = Voucher::lockForUpdate()->find($voucherId);
            if (! $voucher) {
                throw ValidationException::withMessages([
                    'voucher' => 'This voucher no longer exists — it may have been cancelled.',
                ]);
            }

            // Phase 12C-1 — remember which items this voucher touched BEFORE any
            // branch deletes its stock rows (the stale lots cascade away with them);
            // EVERY branch below must refold these items after its rewrite. The
            // workflow branch (Delivery/Receipt Notes, Rejections) is the mainline
            // inter-company receiving flow — skipping it double-depleted on
            // delivery-note alters and resurrected full lots on receipt-note alters.
            $lotItemIds = $voucher->stockEntries()->pluck('stock_item_id')->all();
            $voucher->update([
                'date' => $data['date'],
                'narration' => $data['narration'],
                'party_ledger_id' => $data['party_ledger_id'],
                'reference_no' => $data['reference_no'],
                'reference_date' => $data['reference_date'],
                'reference_voucher_id' => $data['reference_voucher_id'],
            ]);

            // Phase 8B — Order alter: replace the commitment lines. Refuse if anything
            // has already been delivered against this order (deleting the order_lines
            // would cascade away the order_fulfillments and silently reset delivered_qty
            // — the audit trail must not be destroyed). Cancel the notes first.
            if (in_array($voucher->type, Voucher::ORDER_TYPES, true)) {
                $hasDeliveries = OrderFulfillment::whereIn(
                    'order_line_id',
                    OrderLine::where('voucher_id', $voucher->id)->pluck('id')
                )->exists();
                if ($hasDeliveries) {
                    throw ValidationException::withMessages([
                        'voucher' => 'This order already has deliveries against it and cannot be altered. Cancel the Delivery/Receipt Notes first.',
                    ]);
                }
                $voucher->orderLines()->delete();
                app(OrderService::class)->persistOrderLines($voucher, $data['items'] ?? []);

                return $voucher->fresh();
            }

            // Phase 8B — Delivery/Receipt Note (or Rejection) alter: reverse this note's
            // fulfillments (delivered_qty recomputed on the affected order lines),
            // re-post the stock fresh (cost re-locked), then re-apply against the order.
            if (in_array($voucher->type, Voucher::STOCK_WORKFLOW_TYPES, true)) {
                app(OrderService::class)->reverseFulfillment($voucher);
                $voucher->stockEntries()->delete();
                app(StockService::class)->persistItems($voucher, $data['items'] ?? []);
                app(OrderService::class)->applyFulfillment($voucher);

                // Phase 12C-1 — same exact repair as the accounting branch below.
                app(\App\Services\StockLotService::class)->refoldItems(array_merge(
                    $lotItemIds,
                    $voucher->stockEntries()->pluck('stock_item_id')->all(),
                ));

                return $voucher->fresh();
            }

            // Phase 10A — take this voucher's OLD deduction back out of the deductee's
            // running year-to-date state and delete its record BEFORE anything is
            // rewritten. persist() below then recomputes the deduction against a state
            // that no longer contains this voucher, so an altered base re-derives cleanly
            // (mirrors the bill-wise / cost-centre delete-then-rewrite discipline).
            app(TdsService::class)->reverseFor($voucher);
            app(TdsService::class)->reverseChallanFor($voucher); // Phase 10B — drop the old challan too
            app(\App\Services\InterCompanyService::class)->reverseFor($voucher); // Phase 12B — re-derived below
            $voucher->entries()->delete();
            $voucher->billAllocations()->delete(); // replaced wholesale with the entries
            $voucher->costAllocations()->delete();
            $voucher->stockEntries()->delete();    // re-posted fresh below (cost re-locked)
            $entryIds = $this->writeEntries($voucher, $data['lines']);
            app(BillService::class)->persist($voucher, $data['lines'], $entryIds);
            app(CostCentreService::class)->persist($voucher, $data['lines'], $entryIds);
            app(TdsService::class)->persist($voucher, $data, $entryIds);
            app(TdsService::class)->persistChallan($voucher, $data); // Phase 10B
            app(\App\Services\InterCompanyService::class)->persist($voucher, $data); // Phase 12B
            // Re-post stock movement; the OUT cost is recomputed (re-locked) from the
            // current ledger, excluding this voucher's own now-deleted rows. The Phase 8B
            // double-stock safeguard holds on alter too: an invoice that references a
            // Delivery/Receipt Note skips persistItems, then the assertion verifies it.
            if (! $this->referencesStockMovement($voucher)) {
                app(StockService::class)->persistItems($voucher, $data['items'] ?? []);
            }
            $this->assertNoDoubleStock($voucher);
            $this->persistStockMovement($voucher, $data);

            // Phase 12C-1 — the exact repair: replay the affected items' lot state
            // (old items + whatever the rewrite touched) inside this transaction.
            app(\App\Services\StockLotService::class)->refoldItems(array_merge(
                $lotItemIds,
                $voucher->stockEntries()->pluck('stock_item_id')->all(),
            ));

            return $voucher->fresh('entries.ledger');
        });
    }

    /**
     * Persist a Phase 6C stock-movement voucher (Stock Journal / Physical Stock)
     * through StockService's single locking/costing discipline. A no-op for every
     * other voucher type. Runs inside the caller's post transaction.
     */
    private function persistStockMovement(Voucher $voucher, array $data): void
    {
        $m = $data['movement'] ?? null;
        if (! $m) {
            return;
        }
        $stock = app(StockService::class);
        $itemId = (int) $m['stock_item_id'];

        if ($data['type'] === 'stock_journal') {
            if ($m['mode'] === 'transfer') {
                $stock->persistTransfer($voucher, $itemId, $m['from_godown_id'], $m['to_godown_id'], (float) $m['qty']);
            } else { // consumption
                $stock->persistConsumption($voucher, $itemId, $m['godown_id'], (float) $m['qty']);
            }
        } elseif ($data['type'] === 'physical_stock') {
            $stock->persistPhysicalStock($voucher, $itemId, $m['godown_id'], (float) $m['counted_qty']);
        }
    }

    /**
     * Phase 8B double-stock safeguard, half 1: does this invoice reference a stock
     * BEARING note (Delivery / Receipt Note) that has ALREADY moved the goods? If so,
     * the invoice must not move stock a second time. Only invoice types can reference
     * a goods note; a Credit/Debit Note references the original invoice (a sale /
     * purchase), so it is correctly excluded and still posts its own return movement.
     */
    private function referencesStockMovement(Voucher $voucher): bool
    {
        if (! in_array($voucher->type, Voucher::INVOICE_TYPES, true) || ! $voucher->reference_voucher_id) {
            return false;
        }
        $ref = Voucher::find($voucher->reference_voucher_id);

        return $ref !== null && in_array($ref->type, ['delivery_note', 'receipt_note'], true);
    }

    /**
     * Phase 8B double-stock safeguard, half 2 (the paranoid assertion): if this invoice
     * references a goods note, verify that NOT ONE stock_entries row was written for it.
     * persistItems is skipped for such an invoice; this is the belt-and-suspenders guard
     * so a future refactor can never silently reintroduce double stock. Throws inside the
     * post transaction (rolls the whole voucher back) if the invariant is ever violated.
     */
    private function assertNoDoubleStock(Voucher $voucher): void
    {
        if ($this->referencesStockMovement($voucher)
            && StockEntry::where('voucher_id', $voucher->id)->exists()) {
            throw new \RuntimeException(sprintf(
                'Double-stock safeguard tripped: voucher #%d (%s) references a Delivery/Receipt Note yet '
                .'wrote stock entries — the note already moved this stock.',
                $voucher->id,
                $voucher->type,
            ));
        }
    }

    /**
     * Live book-quantity lookup for the Physical Stock screen (the one permitted
     * server touch — the variance is then computed client-side as the user types).
     * Returns the godown-level book quantity of an item as of a date.
     */
    public function bookQty(int $stockItemId, ?int $godownId, ?string $date = null, ?int $excludeVoucherId = null): float
    {
        $this->skipRender(); // a lookup mid-typing must never re-render the screen
        $asOf = $date ? Carbon::parse($date) : Carbon::today();

        // Exclude the voucher being altered so its own not-yet-deleted variance row
        // doesn't double-count into the book figure (matches rebuildEditMovement /
        // persistPhysicalStock, which both exclude it).
        return app(StockService::class)->godownQuantity($stockItemId, $godownId, $asOf, $excludeVoucherId, false);
    }

    /**
     * Reconstruct the Phase 6C movement body from a saved stock voucher's rows so
     * the screen re-opens pre-filled. Transfer = OUT(source)+IN(dest); consumption =
     * one OUT; physical stock = one variance row (counted = book + signed variance).
     * A zero-variance physical voucher stored no row and isn't reconstructable.
     */
    private function rebuildEditMovement(Voucher $v): ?array
    {
        if (! in_array($v->type, Voucher::STOCK_TYPES, true)) {
            return null;
        }
        $rows = $v->stockEntries;
        if ($rows->isEmpty()) {
            return null;
        }
        $first = $rows->first();
        $itemId = $first->stock_item_id;
        $itemLabel = $first->stockItem?->name;

        if ($v->type === 'stock_journal') {
            if ($rows->count() >= 2) {
                $out = $rows->firstWhere('direction', 'out');
                $in = $rows->firstWhere('direction', 'in');

                return [
                    'mode' => 'transfer',
                    'stock_item_id' => $itemId,
                    'stock_item_label' => $itemLabel,
                    'qty' => (float) ($out->quantity ?? $in->quantity),
                    'from_godown_id' => $out?->godown_id,
                    'from_godown_label' => $out?->godown?->name,
                    'to_godown_id' => $in?->godown_id,
                    'to_godown_label' => $in?->godown?->name,
                ];
            }

            return [
                'mode' => 'consumption',
                'stock_item_id' => $itemId,
                'stock_item_label' => $itemLabel,
                'qty' => (float) $first->quantity,
                'godown_id' => $first->godown_id,
                'godown_label' => $first->godown?->name,
            ];
        }

        // physical_stock: the single row is the variance; recover the counted qty.
        $signed = $first->direction === 'in' ? (float) $first->quantity : -(float) $first->quantity;
        $book = app(StockService::class)->godownQuantity($itemId, $first->godown_id, $v->date, $v->id, false);

        return [
            'stock_item_id' => $itemId,
            'stock_item_label' => $itemLabel,
            'godown_id' => $first->godown_id,
            'godown_label' => $first->godown?->name,
            'counted_qty' => $book + $signed,
            'book_qty' => $book,
        ];
    }

    /**
     * Write the voucher entries and return their ids keyed by line index, so the
     * bill allocations can be linked to the exact entry they belong to.
     *
     * @return array<int,int>
     */
    private function writeEntries(Voucher $voucher, array $lines): array
    {
        $ids = [];
        foreach ($lines as $i => $line) {
            $entry = VoucherEntry::create([
                'voucher_id' => $voucher->id,
                'ledger_id' => $line['ledger_id'],
                'dr_cr' => $line['dr_cr'],
                'amount' => $line['amount'],
                'line_no' => $i + 1,
                // Phase 11 — the dual-currency shape rides on the entry. Null on a base line.
                'currency_id' => $line['currency_id'] ?? null,
                'foreign_amount' => $line['foreign_amount'] ?? null,
                'exchange_rate' => $line['exchange_rate'] ?? null,
            ]);
            $ids[$i] = $entry->id;
        }

        return $ids;
    }

    private function isDuplicateKey(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062
            || str_contains(strtolower($e->getMessage()), 'duplicate');
    }

    /**
     * Phase 12A — a company-scoped replacement for the raw `exists:{table},id`
     * rules. Laravel's exists: hits the table directly and NEVER sees Eloquent
     * global scopes, so without this a crafted payload could reference another
     * company's ledger/voucher/item/godown/centre/currency/section id and pass
     * validation. Every id a payload names must belong to the ACTIVE company.
     */
    private function existsInCompany(string $table): \Illuminate\Validation\Rules\Exists
    {
        return \Illuminate\Validation\Rule::exists($table, 'id')
            ->where('company_id', \App\Support\ActiveCompany::check());
    }

    private function validatePayload(array $payload): array
    {
        // Stock Journal / Physical Stock (Phase 6C) post ONLY stock movement — zero
        // ledger lines, no Dr/Cr balance. Their `lines` are empty and the balance
        // gate is skipped; a `movement` object is validated instead.
        $isStock = in_array($payload['type'] ?? null, Voucher::STOCK_TYPES, true);
        // Phase 8B — inventory-workflow vouchers (Orders / Delivery-Receipt Notes /
        // Rejections): item lines only, NO ledger side, no balance, no tax.
        $isWorkflow = in_array($payload['type'] ?? null, Voucher::INVENTORY_WORKFLOW_TYPES, true);

        $v = Validator::make($payload, [
            'type' => ['required', Rule::in(array_keys(Voucher::TYPES))],
            'date' => ['required', 'date'],
            'narration' => ['nullable', 'string', 'max:5000'],
            'voucher_id' => ['nullable', 'integer', $this->existsInCompany('vouchers')],
            // Phase 15C — the provisional tag (null = real). Must be a scenario of THIS company;
            // honoured only when the scenarios feature is on (enforced in normalisation below).
            'scenario_id' => ['nullable', 'integer', $this->existsInCompany('scenarios')],
            // A pre-assigned voucher number, honoured ONLY under an import bulk run
            // (server stays authoritative on numbering for interactive posts). The
            // importer supplies it from an in-memory per-(type,fy) cursor; ignored
            // entirely on the normal path — see persistNew().
            'number' => ['nullable', 'integer', 'min:1'],
            // Invoice-mode header metadata (nullable so voucher-mode entries and
            // Contra/Payment/Receipt/Journal are unaffected).
            'party_ledger_id' => ['nullable', 'integer', $this->existsInCompany('ledgers')],
            'reference_no' => ['nullable', 'string', 'max:191'],
            'reference_date' => ['nullable', 'date'],
            // Phase 8A — the original invoice a Debit/Credit Note adjusts (nullable = free-standing).
            'reference_voucher_id' => ['nullable', 'integer', $this->existsInCompany('vouchers')],
            // A stock voucher carries no ledger lines; every other type needs the
            // balanced pair. (The per-line rules below only fire on present rows.)
            'lines' => ($isStock || $isWorkflow) ? ['nullable', 'array'] : ['required', 'array', 'min:2'],
            'lines.*.ledger_id' => ['required', 'integer', $this->existsInCompany('ledgers')],
            'lines.*.dr_cr' => ['required', Rule::in(['Dr', 'Cr'])],
            'lines.*.amount' => ['required', 'numeric', 'gt:0'],
            // Bill-wise allocations (Phase 5C) — optional per line; deep-validated
            // (sum == line amount) by BillService in the after() hook below.
            'lines.*.allocations' => ['nullable', 'array'],
            'lines.*.allocations.*.ref_type' => ['required_with:lines.*.allocations', Rule::in(\App\Models\BillAllocation::TYPES)],
            'lines.*.allocations.*.ref_name' => ['required_with:lines.*.allocations', 'string', 'max:191'],
            'lines.*.allocations.*.amount' => ['required_with:lines.*.allocations', 'numeric'],
            'lines.*.allocations.*.due_date' => ['nullable', 'date'],
            // Cost-centre allocations (Phase 5D) — analytical tag, deep-validated
            // (sum == line amount) by CostCentreService in the after() hook.
            'lines.*.cost_allocations' => ['nullable', 'array'],
            'lines.*.cost_allocations.*.cost_centre_id' => ['required_with:lines.*.cost_allocations', 'integer', $this->existsInCompany('cost_centres')],
            'lines.*.cost_allocations.*.amount' => ['required_with:lines.*.cost_allocations', 'numeric'],
            // Multi-currency (Phase 11) — the foreign amount + rate + currency that ride
            // alongside the base `amount` on a foreign-currency line. All nullable; a base
            // line carries none. ForexService re-derives `amount = round(foreign × rate)`
            // and rejects a mismatch in the after() hook.
            'lines.*.currency_id' => ['nullable', 'integer', $this->existsInCompany('currencies')],
            'lines.*.foreign_amount' => ['nullable', 'numeric'],
            'lines.*.exchange_rate' => ['nullable', 'numeric'],
            'lines.*.rate_override' => ['nullable', 'boolean'],
            'rate_override' => ['nullable', 'boolean'],
            'rate_override_reason' => ['nullable', 'string', 'max:191'],
            // A period-end revaluation journal adjusts foreign ledgers' INR value only, so
            // its foreign-ledger lines are allowed to be base-only (ForexService honours it).
            'forex_revaluation' => ['nullable', 'boolean'],
            // Forex settlement declarations (Phase 11) — one per foreign bill settled on
            // this voucher. The server recomputes the realised gain/loss from the bill's
            // booked rate and verifies the posted Forex Gain/Loss line.
            'forex_settlement' => ['nullable', 'array'],
            // Phase 12B — the inter-company tag. Server-DERIVED: InterCompanyService
            // verifies the declaration against the lines' linked ledgers below.
            'intercompany' => ['nullable', 'array'],
            'intercompany.counterparty_company_id' => ['nullable', 'integer'],
            'forex_settlement.*.ledger_id' => ['required_with:forex_settlement', 'integer', $this->existsInCompany('ledgers')],
            'forex_settlement.*.ref_name' => ['required_with:forex_settlement', 'string', 'max:191'],
            'forex_settlement.*.foreign_amount' => ['required_with:forex_settlement', 'numeric', 'gt:0'],
            'forex_settlement.*.settlement_rate' => ['required_with:forex_settlement', 'numeric', 'gt:0'],
            'forex_settlement.*.gain_loss_ledger_id' => ['nullable', 'integer', $this->existsInCompany('ledgers')],
            // Item-invoice stock lines (Phase 6B) — optional; only Sales/Purchase.
            // The client sends qty + rate (the SELLING rate); the server recomputes
            // the amount, derives direction from the voucher type, and computes the
            // COST itself. No cost ever arrives from the client.
            'items' => [Rule::requiredIf($isWorkflow), 'array'],
            'items.*.stock_item_id' => ['required_with:items', 'integer', $this->existsInCompany('stock_items')],
            'items.*.godown_id' => ['nullable', 'integer', $this->existsInCompany('godowns')],
            'items.*.qty' => ['required_with:items', 'numeric', 'gt:0'],
            'items.*.rate' => ['required_with:items', 'numeric', 'gte:0'],
            // TDS deduction declaration (Phase 10A) — only ever on a Payment. The client
            // sends WHO is being paid, under WHICH section, and what it believes the
            // taxable base to be. It never sends the deducted amount: TdsService derives
            // that from the section rate and the deductee's threshold state, and rejects
            // the voucher if the posted TDS Payable line disagrees.
            'tds_deduction' => ['nullable', 'array'],
            'tds_deduction.deductee_ledger_id' => ['required_with:tds_deduction', 'integer', $this->existsInCompany('ledgers')],
            'tds_deduction.tds_section_id' => ['required_with:tds_deduction', 'integer', $this->existsInCompany('tds_sections')],
            'tds_deduction.base_amount' => ['required_with:tds_deduction', 'numeric', 'gt:0'],
            // TDS challan capture (Phase 10B) — the real bank identifiers, only on a
            // remittance that debits TDS Payable. TdsService::verifyChallanPayload enforces
            // the remittance shape and the BSR/challan formats server-side.
            'tds_challan' => ['nullable', 'array'],
            'tds_challan.bsr_code' => ['nullable', 'string', 'max:7'],
            'tds_challan.challan_number' => ['nullable', 'string', 'max:5'],
            'tds_challan.deposit_date' => ['nullable', 'date'],
            // Stock-movement voucher body (Phase 6C) — validated deeply below. `nullable`
            // matters: the client sends `movement: null` on every NON-stock voucher, and
            // without it the `array` rule rejects the null and no Payment/Journal can post
            // through the screen at all. The prove-* battery omits the key entirely, which
            // is why this only surfaced under a real browser post.
            'movement' => ['nullable', Rule::requiredIf($isStock), 'array'],
            'movement.stock_item_id' => [Rule::requiredIf($isStock), 'integer', $this->existsInCompany('stock_items')],
            'movement.from_godown_id' => ['nullable', 'integer', $this->existsInCompany('godowns')],
            'movement.to_godown_id' => ['nullable', 'integer', $this->existsInCompany('godowns')],
            'movement.godown_id' => ['nullable', 'integer', $this->existsInCompany('godowns')],
            'movement.qty' => ['nullable', 'numeric'],
            'movement.counted_qty' => ['nullable', 'numeric'],
        ]);

        $v->after(function ($validator) use ($payload, $isStock, $isWorkflow) {
            $lines = $payload['lines'] ?? [];

            // Stock vouchers: validate the movement, and SKIP the ledger balance,
            // P&L-guard, tax and bill/cost checks entirely (there are no ledger
            // lines). Everything else runs the existing accounting path unchanged.
            if ($isStock) {
                $this->validateStockMovement($validator, $payload);

                return;
            }

            // Inventory-workflow vouchers (Orders / Delivery-Receipt Notes /
            // Rejections): item lines only, ZERO accounting impact. There is no
            // ledger balance to test, no tax, no bill-wise, no cost-centre — just
            // require at least one positive-qty item line, then return before any
            // accounting check runs.
            if ($isWorkflow) {
                $this->validateWorkflowItems($validator, $payload);

                return;
            }

            // The special Profit & Loss A/c ledger cannot be posted directly (the
            // balance engine represents it as computed profit, not a postable row).
            $plIds = Ledger::where('is_pl_account', true)->pluck('id')->all();
            foreach ($lines as $line) {
                if (in_array((int) ($line['ledger_id'] ?? 0), $plIds, true)) {
                    $validator->errors()->add('lines', 'The Profit & Loss A/c ledger cannot be used on a voucher line.');
                    break;
                }
            }
            // Accumulate in integer paise so the balance test is exact (no IEEE-754
            // drift or large-magnitude float-equality collapse).
            $dr = 0;
            $cr = 0;
            foreach ($lines as $line) {
                $paise = (int) round(((float) ($line['amount'] ?? 0)) * 100);
                if (($line['dr_cr'] ?? null) === 'Dr') {
                    $dr += $paise;
                } elseif (($line['dr_cr'] ?? null) === 'Cr') {
                    $cr += $paise;
                }
            }
            if ($dr !== $cr) {
                $validator->errors()->add('balance', 'Voucher is out of balance: Dr '.number_format($dr / 100, 2).' vs Cr '.number_format($cr / 100, 2).'.');
            }
            if ($dr <= 0) {
                $validator->errors()->add('balance', 'Voucher total must be greater than zero.');
            }

            // Server-side GST authority: for a GST-enabled invoice, recompute the
            // tax from the taxable lines + ledger rate + party/company state and
            // reject if the posted tax lines don't match (client tax is never
            // blindly trusted). No-op when GST is off or it isn't an invoice.
            $gstError = app(\App\Services\GstService::class)->verifyInvoicePayload($payload);
            if ($gstError !== null) {
                $validator->errors()->add('gst', $gstError);
            }

            // Nepal VAT authority — the mutually-exclusive sibling of GST. Always
            // invoked; a no-op (returns null) unless VAT is the active regime.
            $vatError = app(\App\Services\VatService::class)->verifyInvoicePayload($payload);
            if ($vatError !== null) {
                $validator->errors()->add('vat', $vatError);
            }

            // Server-side bill-wise authority: every bill-wise line's allocations
            // must sum to the line amount to the paise (and the refs be valid).
            $billError = app(BillService::class)->validatePayload($payload);
            if ($billError !== null) {
                $validator->errors()->add('billwise', $billError);
            }

            // Server-side cost-centre authority: every cost-applicable line's cost
            // allocations must sum to the line amount to the paise.
            $costError = app(CostCentreService::class)->validatePayload($payload);
            if ($costError !== null) {
                $validator->errors()->add('costcentre', $costError);
            }

            // Server-side TDS authority (Phase 10A): recompute the deduction from the
            // section's rate, the deductee's year-to-date threshold state and the taxable
            // base derived from the voucher's OWN non-duty debits (so GST is excluded by
            // construction), and reject if the posted TDS Payable line doesn't match to
            // the paise. It also refuses a TDS Payable credit that arrives with no
            // declaration, and any TDS at all while the feature is off — so a crafted
            // payload can never manufacture a liability. No-op otherwise.
            $tdsError = app(TdsService::class)->verifyPayload($payload);
            if ($tdsError !== null) {
                $validator->errors()->add('tds', $tdsError);
            }

            // Server-side TDS challan authority (Phase 10B): a captured bank challan may
            // only ride a remittance that debits TDS Payable, with a well-formed BSR code
            // and challan number. No-op when no challan is captured.
            $challanError = app(TdsService::class)->verifyChallanPayload($payload);
            if ($challanError !== null) {
                $validator->errors()->add('tds_challan', $challanError);
            }

            // Server-side multi-currency authority (Phase 11): for every foreign-currency
            // line, re-derive the base amount from foreign × rate and reject a mismatch;
            // verify the rate is the recorded rate (or an explicit override); and on a
            // settlement, recompute the realised gain/loss and verify the Forex Gain/Loss
            // line. Refuses any foreign line while the feature is off. No-op otherwise.
            $forexError = app(ForexService::class)->verifyPayload($payload);
            if ($forexError !== null) {
                $validator->errors()->add('forex', $forexError);
            }

            // Server-side inter-company authority (Phase 12B): if any line posts to a
            // party ledger linked to a company in the ACTIVE company's group, the
            // payload must declare that exact counterparty — missing, mismatched, or
            // unjustified tags are refused. Inert for ungrouped tenants (CA firms).
            $icError = app(\App\Services\InterCompanyService::class)->verifyPayload($payload);
            if ($icError !== null) {
                $validator->errors()->add('intercompany', $icError);
            }

            // Item-invoice authority (Phase 6B): stock lines only belong on a
            // Sales/Purchase invoice, and the stock revenue (Σ qty×rate) must equal
            // the taxable ledger revenue (the non-party, non-tax ledger lines) to
            // the paise — so the two representations of the same sale never diverge.
            // Tax is verified separately (item-sourced) by GST/VAT above; cost is
            // never in the payload.
            $items = $payload['items'] ?? [];
            if (! empty($items)) {
                if (! in_array($payload['type'] ?? '', Voucher::INVOICE_TYPES, true)) {
                    $validator->errors()->add('items', 'Stock items can only be entered on a Sales/Purchase invoice or a Debit/Credit Note.');
                } else {
                    $itemsBaseP = 0;
                    foreach ($items as $it) {
                        $itemsBaseP += (int) round(((float) ($it['qty'] ?? 0)) * ((float) ($it['rate'] ?? 0)) * 100);
                    }
                    $partyId = (int) ($payload['party_ledger_id'] ?? 0);
                    $taxLedgerIds = Ledger::whereNotNull('tax_type')->pluck('id')->all();
                    $ledgerRevenueP = 0;
                    foreach ($lines as $line) {
                        $lid = (int) ($line['ledger_id'] ?? 0);
                        if ($lid === $partyId || in_array($lid, $taxLedgerIds, true)) {
                            continue;
                        }
                        $ledgerRevenueP += (int) round(((float) ($line['amount'] ?? 0)) * 100);
                    }
                    if ($ledgerRevenueP !== $itemsBaseP) {
                        $validator->errors()->add('items', 'Item total ('.number_format($itemsBaseP / 100, 2).') does not match the sales/purchase ledger amount ('.number_format($ledgerRevenueP / 100, 2).').');
                    }
                }
            }
        });

        if ($v->fails()) {
            throw new ValidationException($v);
        }

        // Normalise
        $data = $v->validated();
        $data['narration'] = $data['narration'] ?? null;
        // Phase 15C — the provisional tag is honoured only when the scenarios feature is on; a
        // crafted scenario_id on a scenarios-disabled company is dropped (the voucher stays real).
        $data['scenario_id'] = (! empty($payload['scenario_id']) && (bool) \App\Models\CompanyFeature::current()->scenarios)
            ? (int) $payload['scenario_id']
            : null;
        // Phase 11 — a forex rate override is an audit-worthy decision (a contract rate
        // differing from the recorded market rate). Stamp the reason into the narration so
        // the override is visible on the voucher, not silently accepted.
        if (! empty($payload['rate_override']) && ! empty($payload['rate_override_reason'])) {
            $reason = trim((string) $payload['rate_override_reason']);
            $data['narration'] = trim(($data['narration'] ? $data['narration'].' · ' : '').'Forex rate override: '.$reason);
        }
        // A pre-assigned number is carried through for the bulk-import path only;
        // persistNew() ignores it entirely unless BulkMode is active.
        $data['number'] = isset($payload['number']) ? (int) $payload['number'] : null;
        // Invoice metadata only belongs on Sales/Purchase; ignore it otherwise
        // so the one posting path stays clean for the other voucher types.
        $isInvoiceType = in_array($data['type'], Voucher::INVOICE_TYPES, true);
        // Party + external-doc metadata belong on invoices AND on Phase 8B inventory-
        // workflow vouchers (a Sales Order has a customer, a Delivery Note a consignee),
        // and on nothing else — so the one posting path stays clean for the rest.
        $carriesParty = $isInvoiceType || $isWorkflow;
        $data['party_ledger_id'] = $carriesParty ? ($data['party_ledger_id'] ?? null) : null;
        $data['reference_no'] = $carriesParty ? ($data['reference_no'] ?? null) : null;
        $data['reference_date'] = $carriesParty ? ($data['reference_date'] ?? null) : null;
        // reference_voucher_id chains the inventory/return workflow: a Debit/Credit Note
        // → its original invoice; a Delivery/Receipt Note → its Order; and — the pillar
        // of the double-stock safeguard — a Sales/Purchase invoice → its Delivery/Receipt
        // Note. So it is admitted on every invoice AND every workflow voucher, null else.
        $data['reference_voucher_id'] = $carriesParty
            ? (! empty($payload['reference_voucher_id']) ? (int) $payload['reference_voucher_id'] : null)
            : null;
        // Re-attach allocations from the raw payload by index (validated() may not
        // carry the nested array), normalised to the persisted shape.
        $rawLines = $payload['lines'] ?? [];
        $data['lines'] = [];
        // A stock voucher OR an inventory-workflow voucher (Order / Delivery-Receipt
        // Note / Rejection) has NO ledger side. Force it empty here (mirroring the item
        // gate below) so a crafted payload cannot smuggle ledger lines past the skipped
        // balance gate and unbalance the Trial Balance.
        foreach ((($isStock || $isWorkflow) ? [] : ($v->validated()['lines'] ?? [])) as $i => $l) {
            $allocs = $rawLines[$i]['allocations'] ?? [];
            $costAllocs = $rawLines[$i]['cost_allocations'] ?? [];
            // Phase 11 — carry the forex fields through when the line declares a currency.
            // The base `amount` above stays the source of truth for the balance gate;
            // ForexService has already verified amount == round(foreign × rate).
            $raw = $rawLines[$i] ?? [];
            $hasCurrency = ! empty($raw['currency_id']);
            $data['lines'][] = [
                'ledger_id' => (int) $l['ledger_id'],
                'dr_cr' => $l['dr_cr'],
                'amount' => round((float) $l['amount'], 2),
                'currency_id' => $hasCurrency ? (int) $raw['currency_id'] : null,
                'foreign_amount' => $hasCurrency ? round((float) ($raw['foreign_amount'] ?? 0), 4) : null,
                'exchange_rate' => $hasCurrency ? round((float) ($raw['exchange_rate'] ?? 0), 6) : null,
                'allocations' => array_map(fn ($a) => [
                    'ref_type' => $a['ref_type'],
                    'ref_name' => trim((string) $a['ref_name']),
                    'amount' => round((float) $a['amount'], 2),
                    'due_date' => ! empty($a['due_date']) ? $a['due_date'] : null,
                ], is_array($allocs) ? $allocs : []),
                'cost_allocations' => array_map(fn ($a) => [
                    'cost_centre_id' => (int) $a['cost_centre_id'],
                    'amount' => round((float) $a['amount'], 2),
                ], is_array($costAllocs) ? $costAllocs : []),
            ];
        }

        // Normalise item-invoice stock lines. Direction is derived from the voucher
        // type (never trusted from the client): Sales = OUT, Purchase = IN. The
        // amount is recomputed from qty × rate so a tampered amount is ignored; the
        // cost is left entirely to StockService at post time.
        $data['items'] = [];
        if ($isInvoiceType || $isWorkflow) {
            // Sales & Debit Note (purchase return) & Delivery Note & Rejection Out send
            // stock OUT; Purchase & Credit Note (sales return) & Receipt Note & Rejection
            // In bring stock IN. One rule (Voucher::stockDirection), one gate. Orders
            // carry items too (as commitments); their direction is inert — persistOrderLines
            // ignores it — so it costs nothing to normalise them the same way.
            $dir = Voucher::stockDirection($data['type']);
            foreach ($payload['items'] ?? [] as $it) {
                if (empty($it['stock_item_id'])) {
                    continue;
                }
                $qty = (float) ($it['qty'] ?? 0);
                $rate = (float) ($it['rate'] ?? 0);
                $data['items'][] = [
                    'stock_item_id' => (int) $it['stock_item_id'],
                    'godown_id' => ! empty($it['godown_id']) ? (int) $it['godown_id'] : null,
                    'qty' => $qty,
                    'rate' => $rate,
                    'amount' => round($qty * $rate, 2),
                    'direction' => $dir,
                ];
            }
        }

        // Normalise the Phase 10A TDS declaration. It rides only on a Payment — forcing
        // it null elsewhere keeps the one posting path clean and means a crafted payload
        // cannot attach a deduction to, say, a Sales invoice. The deducted AMOUNT is not
        // carried here at all: TdsService::persist() recomputes it from the section and
        // the deductee's threshold state, so there is nothing for a client to tamper with.
        $data['tds_deduction'] = null;
        if ($data['type'] === 'payment' && ! empty($payload['tds_deduction']['deductee_ledger_id'])) {
            $td = $payload['tds_deduction'];
            $data['tds_deduction'] = [
                'deductee_ledger_id' => (int) $td['deductee_ledger_id'],
                'tds_section_id' => (int) $td['tds_section_id'],
                'base_amount' => round((float) $td['base_amount'], 2),
            ];
        }

        // Normalise the Phase 10B challan capture — carried only on a Payment; the deposit
        // amount is derived server-side from the Dr TDS Payable line, never the client.
        $data['tds_challan'] = null;
        if ($data['type'] === 'payment' && ! empty($payload['tds_challan']['bsr_code'])) {
            $tc = $payload['tds_challan'];
            $data['tds_challan'] = [
                'bsr_code' => trim((string) $tc['bsr_code']),
                'challan_number' => trim((string) ($tc['challan_number'] ?? '')),
                'deposit_date' => $tc['deposit_date'] ?? null,
                'minor_head' => $tc['minor_head'] ?? '200',
            ];
        }

        // Normalise the Phase 6C stock-movement body (no ledger side). The server
        // computes every cost; the client sends only item/godown(s)/qty/counted.
        $data['movement'] = null;
        if ($isStock) {
            $m = $payload['movement'] ?? [];
            $g = fn ($k) => ! empty($m[$k]) ? (int) $m[$k] : null;
            $data['movement'] = [
                'mode' => in_array($m['mode'] ?? '', ['transfer', 'consumption'], true) ? $m['mode'] : null,
                'stock_item_id' => (int) ($m['stock_item_id'] ?? 0),
                'from_godown_id' => $g('from_godown_id'),
                'to_godown_id' => $g('to_godown_id'),
                'godown_id' => $g('godown_id'),
                'qty' => round((float) ($m['qty'] ?? 0), 4),
                'counted_qty' => round((float) ($m['counted_qty'] ?? 0), 4),
            ];
        }

        return $data;
    }

    /**
     * Deep-validate a Phase 6C stock-movement voucher (called from the after-hook,
     * only for stock_journal / physical_stock). No ledger balance is involved.
     */
    private function validateStockMovement($validator, array $payload): void
    {
        $type = $payload['type'] ?? null;
        $m = $payload['movement'] ?? [];
        if (! is_array($m)) {
            $validator->errors()->add('movement', 'Stock movement details are required.');

            return;
        }

        if ($type === 'stock_journal') {
            $mode = $m['mode'] ?? null;
            if (! in_array($mode, ['transfer', 'consumption'], true)) {
                $validator->errors()->add('movement', 'Choose Transfer or Consumption.');

                return;
            }
            if ((float) ($m['qty'] ?? 0) <= 0) {
                $validator->errors()->add('movement', 'Quantity must be greater than zero.');
            }
            if ($mode === 'transfer') {
                $from = ! empty($m['from_godown_id']) ? (int) $m['from_godown_id'] : null;
                $to = ! empty($m['to_godown_id']) ? (int) $m['to_godown_id'] : null;
                if (! $from || ! $to) {
                    $validator->errors()->add('movement', 'A transfer needs a source and a destination godown.');
                } elseif ($from === $to) {
                    $validator->errors()->add('movement', 'Source and destination godowns must differ.');
                }
            }
        } elseif ($type === 'physical_stock') {
            if ((float) ($m['counted_qty'] ?? -1) < 0) {
                $validator->errors()->add('movement', 'Counted quantity cannot be negative.');
            }
        }
    }

    /**
     * Phase 8B — an inventory-workflow voucher (Order / Delivery-Receipt Note /
     * Rejection) needs at least one item line carrying a positive quantity. The
     * per-line rules already enforce stock_item_id / qty>0 / rate>=0; this guard
     * gives a single clear message when the item grid is empty and, by returning
     * here, keeps the balance/tax/bill/cost checks from ever running on a voucher
     * that has NO ledger side at all.
     */
    private function validateWorkflowItems($validator, array $payload): void
    {
        $items = $payload['items'] ?? [];
        $hasLine = collect(is_array($items) ? $items : [])
            ->contains(fn ($it) => ! empty($it['stock_item_id']) && (float) ($it['qty'] ?? 0) > 0);

        if (! $hasLine) {
            $validator->errors()->add('items', 'Add at least one item with a quantity.');
        }
    }

    /** Cancel/delete the voucher currently being altered — cascade clears postings. */
    public function cancelVoucher(): array
    {
        $this->skipRender(); // the client navigates away; no HTML is needed back

        // Phase 14A — cancelling a voucher is a write; block it in read-only states.
        TenantGate::assertWritable();

        if (! $this->editVoucherId) {
            return ['ok' => false, 'message' => 'Nothing to cancel.'];
        }
        $v = Voucher::find($this->editVoucherId);
        if (! $v) {
            return ['ok' => false, 'message' => 'Voucher not found.'];
        }
        $label = $v->displayNumber();
        // Phase 16C — snapshot the event BEFORE the delete, for the same reason $label is captured
        // here: the row and its bill allocations are about to cascade away, so afterwards there is
        // nothing left to build a payload from. Total (swallows its own failures, returns null).
        $webhooks = app(\App\Services\Api\Webhooks\WebhookVoucherEvents::class);
        $cancelCtx = $webhooks->beforeCancel($v);
        // Phase 12C-1 — the delete's FK cascade AND the deleted-hook's lot refold
        // must commit or roll back TOGETHER (the refold rewrites other vouchers'
        // depletion traces; a half-applied cancel would leave them stale).
        DB::transaction(fn () => $v->delete());
        // Post-commit, and deliberately NOT enrolled in the atomicity above — a webhook must never
        // be part of what the cancel commits or rolls back.
        $webhooks->afterCancel($cancelCtx);

        return ['ok' => true, 'message' => 'Cancelled '.$label];
    }

    public function render()
    {
        return view('livewire.voucher-screen');
    }
}
