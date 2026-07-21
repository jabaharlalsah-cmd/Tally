/* =========================================================================
   ZeroBook — voucher entry controller (Alpine 'voucherScreen') + Day Book.
   The entire entry loop (lines, Dr/Cr, picker, amounts, running totals,
   add/remove line, type switching) is client-side. The server (Livewire) is
   touched only on accept (Ctrl+A) to post + validate the double-entry balance.
   Reuses the Phase 2 masters cache + zbSelect picker (event sink) for ledgers.
   ========================================================================= */

import { registerDirty } from '../engine/keys.js';

export function voucherScreen(cfg) {
    return {
        cfg,
        types: cfg.types,
        typeKeys: Object.keys(cfg.types),
        invoiceTypes: cfg.invoiceTypes || ['sales', 'purchase'],
        // Phase 8B — inventory-workflow vouchers: item lines only, ZERO accounting.
        workflowTypes: cfg.workflowTypes || ['sales_order', 'purchase_order', 'delivery_note', 'receipt_note', 'rejection_out', 'rejection_in'],
        orderTypes: cfg.orderTypes || ['sales_order', 'purchase_order'],
        stockWorkflowTypes: cfg.stockWorkflowTypes || ['delivery_note', 'receipt_note', 'rejection_out', 'rejection_in'],
        // Open orders/notes this voucher may reference. { delivery_note:[open SOs], receipt_note:[open POs], rejection_out:[receipt notes], rejection_in:[delivery notes] }
        referenceOrders: cfg.referenceOrders || {},
        // Delivery/Receipt notes a Sales/Purchase invoice may bill against (drives the double-stock skip). { sales:[delivery notes], purchase:[receipt notes] }
        referenceDeliveries: cfg.referenceDeliveries || {},
        invoiceGroups: cfg.invoiceGroups || {},
        gst: cfg.gst || { enabled: false, tax_ledgers: {} },
        vat: cfg.vat || { enabled: false, tax_ledgers: {} }, // Nepal VAT (Phase 5E)
        // bill-wise (Phase 5C)
        billEnabled: !!cfg.billEnabled,
        openBills: cfg.openBills || {}, // { ledgerId: [ {ref_name,pending,pending_paise,side,due_date,original} ] }
        allocs: {}, // targetKey -> [ {ref_type,ref_name,amount,due_date} ]
        showBillAlloc: false,
        billTarget: null,
        billRows: [],
        // cost-centre (Phase 5D)
        costEnabled: !!cfg.costEnabled,
        costCentres: cfg.costCentres || [],
        costAllocs: {}, // targetKey -> [ {cost_centre_id, cost_centre_label, amount} ]
        showCostAlloc: false,
        costTarget: null,
        costRows: [],
        costRowSeq: 1, // stable per-row id so a middle-row removal never desyncs the pickers
        // TDS (Phase 10A) — a Payment to a tagged deductee withholds tax at source. The
        // deduction shown here is a client-side APPROXIMATION of the server's rule, so the
        // figure updates with zero network as the user types; TdsService recomputes it from
        // the deductee's real threshold state on accept and rejects any disagreement.
        tdsEnabled: !!cfg.tdsEnabled,
        tdsPayableLedgerId: cfg.tdsPayableLedgerId || null,
        tdsSections: cfg.tdsSections || [], // only the sections in force this fiscal year
        tdsDeductees: cfg.tdsDeductees || [],
        // { "deducteeId:sectionId": [ {voucher_id, date, payment, deducted}, … ] } — the
        // year's individual payments, so the monthly (194I) and single-bill (194C) windows
        // can be reproduced client-side exactly as the server computes them.
        tdsLedger: cfg.tdsLedger || {},
        tdsOn: false, // is the deduction engaged on THIS voucher?
        tdsDeducteeId: null,
        tdsDeducteeLabel: '',
        tdsSectionId: null,
        tdsSectionLabel: '',
        // Phase 10B — bank challan identity, captured on a remittance (Dr TDS Payable).
        challanBsr: '',
        challanNumber: '',
        challanDate: '',
        // Phase 11 — multi-currency. A line whose ledger is a foreign-currency ledger
        // shows foreign amount + rate; the base `amount` is derived live (foreign × rate).
        forexEnabled: !!cfg.forexEnabled,
        // Phase 12B — inter-company boot (group membership + linked-company names)
        interCompanyEnabled: !!cfg.interCompanyEnabled,
        interCompanyGroupCompanyIds: cfg.interCompanyGroupCompanyIds || [],
        interCompanyCompanyNames: cfg.interCompanyCompanyNames || {},
        currencies: cfg.currencies || [],
        latestRates: cfg.latestRates || {}, // { currency_id: {rate, date} }
        rateHistory: cfg.rateHistory || {}, // { currency_id: [ [date, rate], … ] } ascending
        baseCurrencyId: cfg.baseCurrencyId || null,
        forexGainLedgerId: cfg.forexGainLedgerId || null,
        forexLossLedgerId: cfg.forexLossLedgerId || null,
        type: (cfg.edit ? cfg.edit.type : cfg.initialType) || 'payment',
        editId: cfg.edit ? cfg.edit.id : null,
        number: 0,
        nextNumbers: Object.assign({}, cfg.nextNumbers),
        fyLabel: cfg.fyLabel,
        date: cfg.edit ? cfg.edit.date : cfg.today,
        narration: cfg.edit ? cfg.edit.narration || '' : '',
        // Phase 15C — the provisional-scenario tag ('' = a real voucher). Sticky across
        // consecutive new entries so a run of what-if vouchers lands in the same scenario.
        scenariosEnabled: !!cfg.scenariosEnabled,
        scenarios: cfg.scenarios || [],
        scenarioId: cfg.edit ? (cfg.edit.scenario_id || '') : '',
        lines: [],
        lineSeq: 1,
        lineActive: 0,
        balances: cfg.balances || {},
        accountLedgerId: null,
        accountLedgerLabel: '',
        // invoice (as-Invoice) mode fields
        invoiceMode: false,
        partyLedgerId: null,
        partyLedgerLabel: '',
        referenceNo: '',
        referenceDate: '',
        // Phase 8A — the original invoice a Debit/Credit Note adjusts.
        referenceInvoices: cfg.referenceInvoices || {}, // { credit_note:[...], debit_note:[...] }
        referenceVoucherId: null,
        referenceLabel: '',
        // item-invoice (Phase 6B): stock lines feed a single revenue ledger + tax.
        // The rate captured here is always the SELLING rate; the COST is computed
        // server-side and never lives on the client.
        stockEnabled: !!cfg.stockEnabled,
        itemInvoiceDefaults: cfg.itemInvoiceDefaults || {}, // { sales:{ledger_id,label}, purchase:{...} }
        mainGodownId: cfg.mainGodownId || null,
        itemMode: false,
        items: [], // [ {uid, stock_item_id, stock_item_label, godown_id, godown_label, qty, rate} ]
        itemSeq: 1,
        itemLedgerId: null,   // the single Sales/Purchase ledger Σ item amounts post to
        itemLedgerLabel: '',
        showQuickStockItem: false,
        // stock-movement vouchers (Phase 6C): Stock Journal (transfer/consumption)
        // + Physical Stock. No ledger side; the server computes every cost.
        mv: {
            mode: 'transfer',
            item_id: null, item_label: '',
            qty: '',
            from_godown_id: null, from_godown_label: '',
            to_godown_id: null, to_godown_label: '',
            godown_id: null, godown_label: '',
            counted_qty: '',
            book_qty: null, // Physical Stock: fetched once per item/godown, then live
        },
        lastPostedId: null,
        lastPostedNumber: '',
        flash: '',
        flashKind: 'ok',
        accepting: false,
        showQuickLedger: false,
        showQuickGroup: false,
        // One pending-combo slot PER quick-create sub-screen. They nest — creating a
        // group from the quick-ledger's Under picker opens quick-group ON TOP of
        // quick-ledger — so a single shared slot would be overwritten by the inner
        // screen and the outer ledger would fill nothing on save.
        pendingLedgerCombo: null,
        pendingGroupCombo: null,
        pendingStockItemCombo: null,
        confirmingCancel: false,

        /* Voucher date, split into the three segments the user types (Tally order). */
        dp: { dd: '', mm: '', yyyy: '' },

        init() {
            this.syncDateParts();
            this.$store.masters.seed(cfg.groups, cfg.ledgers);
            this.$store.masters.seedCostCentres(cfg.costCentres || []);
            this.$store.masters.seedInventory('stockItems', cfg.stockItems || []);
            this.$store.masters.seedInventory('godowns', cfg.godowns || []);
            this.$store.masters.seedInventory('units', cfg.units || []);
            this.$store.masters.seedInventory('stockGroups', cfg.stockGroups || []);
            // Phase 10A — the TDS panel's two pickers run 0-network off these caches.
            this.$store.masters.seedInventory('tdsSections', cfg.tdsSections || []);
            this.$store.masters.seedInventory('tdsDeductees', cfg.tdsDeductees || []);
            this.number = this.editId ? cfg.edit.number : this.nextNumbers[this.type];
            // Sales/Purchase default to invoice mode (Tally's default); an edited
            // voucher re-opens in whichever mode it was captured in.
            this.invoiceMode = this.editId ? !!cfg.edit.is_invoice : this.isInvoiceType;
            // Item invoice is the default for Sales/Purchase when the company keeps
            // stock (Tally's Item Invoice default); an edited voucher re-opens in the
            // mode it was captured in (item if it carried stock lines).
            this.itemMode = this.editId ? !!(cfg.edit && cfg.edit.has_items) : (this.stockEnabled && this.isInvoiceType);
            this.setDefaultItemLedger();
            if (cfg.edit) {
                this.lines = cfg.edit.lines.map((l) => this.mkLine(l));
                this.partyLedgerId = cfg.edit.party_ledger_id || null;
                this.partyLedgerLabel = cfg.edit.party_ledger_label || '';
                this.referenceNo = cfg.edit.reference_no || '';
                this.scenarioId = cfg.edit.scenario_id || '';
                this.referenceVoucherId = cfg.edit.reference_voucher_id || null;
                this.referenceLabel = cfg.edit.reference_label || '';
                this.referenceDate = cfg.edit.reference_date || '';
                // Re-seed bill + cost allocations from the saved entries so the
                // sub-screens re-open pre-filled. The party leg's bill allocations
                // key on 'party'; cost allocations are always per line.
                cfg.edit.lines.forEach((el, i) => {
                    const uid = this.lines[i].uid;
                    const isParty = this.invoiceMode && el.ledger_id === this.partyLedgerId && el.dr_cr === this.invoicePartySide();
                    if (el.allocations && el.allocations.length) {
                        this.allocs[isParty ? 'party' : 'line:' + uid] = el.allocations.map((a) => ({ ref_type: a.ref_type, ref_name: a.ref_name, amount: a.amount, due_date: a.due_date || '' }));
                    }
                    if (el.cost_allocations && el.cost_allocations.length) {
                        this.costAllocs['line:' + uid] = el.cost_allocations.map((a) => {
                            const cc = (this.costCentres || []).find((c) => c.id === a.cost_centre_id);
                            return { cost_centre_id: a.cost_centre_id, cost_centre_label: cc ? cc.name : '', amount: a.amount };
                        });
                    }
                });
                // In invoice mode the party leg + the computed tax legs are shown
                // separately, not as allocation lines — strip them (tax is recomputed).
                if (this.invoiceMode) {
                    this.stripPartyLine();
                    this.stripTaxLines();
                }
                // Item invoice: rebuild the stock rows from the saved movement and
                // lift the single revenue ledger out of the leftover lines (its cost
                // is re-locked on re-post — never carried from the client).
                if (this.itemMode && cfg.edit.items && cfg.edit.items.length) {
                    this.items = cfg.edit.items.map((it) => this.mkItem(it));
                    const rev = this.lines.find((l) => l.ledger_id && l.dr_cr === this.invoiceLedgerSide());
                    if (rev) {
                        this.itemLedgerId = rev.ledger_id;
                        this.itemLedgerLabel = rev.ledger_label;
                        // The revenue ledger's cost allocations were seeded under
                        // 'line:<uid>' by the generic loop; in item mode they key on
                        // 'itemledger' — re-home them so the sub-screen re-opens filled.
                        const revKey = 'line:' + rev.uid;
                        if (this.costAllocs[revKey]) {
                            this.costAllocs['itemledger'] = this.costAllocs[revKey];
                            delete this.costAllocs[revKey];
                        }
                    }
                }
                // TDS (Phase 10A): re-open a saved deduction engaged, showing the GROSS
                // the user originally typed. The stored voucher carries the TDS Payable
                // credit and an already-reduced bank leg; strip the one and restore the
                // other, then the panel re-derives the deduction from a year-to-date
                // state that excludes this voucher — exactly as the server does.
                if (this.showTdsPanel && cfg.edit.tds_deduction) {
                    const td = cfg.edit.tds_deduction;
                    this.tdsDeducteeId = td.deductee_ledger_id;
                    this.tdsDeducteeLabel = td.deductee_label || '';
                    this.tdsSectionId = td.tds_section_id;
                    this.tdsSectionLabel = td.section_label || '';
                    this.tdsOn = true;
                    this.tdsRestoreGross();
                }
                // Phase 10B — re-open a saved remittance with its challan filled in.
                if (cfg.edit.tds_challan) {
                    this.challanBsr = cfg.edit.tds_challan.bsr_code || '';
                    this.challanNumber = cfg.edit.tds_challan.challan_number || '';
                    this.challanDate = cfg.edit.tds_challan.deposit_date || '';
                }
                // Stock voucher (Phase 6C): rebuild the movement body from the saved
                // rows so it re-opens pre-filled (the cost is re-locked on re-post).
                this.resetMovement();
                if (this.isStockVoucher && cfg.edit.movement) {
                    const m = cfg.edit.movement;
                    this.mv.mode = m.mode || 'transfer';
                    this.mv.item_id = m.stock_item_id || null;
                    this.mv.item_label = m.stock_item_label || '';
                    this.mv.qty = m.qty != null ? String(m.qty) : '';
                    this.mv.from_godown_id = m.from_godown_id || null;
                    this.mv.from_godown_label = m.from_godown_label || '';
                    this.mv.to_godown_id = m.to_godown_id || null;
                    this.mv.to_godown_label = m.to_godown_label || '';
                    this.mv.godown_id = m.godown_id != null ? m.godown_id : this.mainGodownId;
                    this.mv.godown_label = m.godown_label || '';
                    this.mv.counted_qty = m.counted_qty != null ? String(m.counted_qty) : '';
                    this.mv.book_qty = m.book_qty != null ? Number(m.book_qty) : null;
                    if (this.showPhysicalStock && this.mv.book_qty === null) this.refreshBookQty();
                }
            } else {
                this.resetLines();
            }

            // Phase 8B — a workflow voucher always enters through the item grid: on
            // alter, rebuild the saved item rows (and keep the reference label); on a
            // fresh voucher resetLines already seeded a blank row. Always keep one
            // trailing blank so the next item is one keystroke away.
            if (this.isWorkflowType) {
                if (this.editId && cfg.edit && cfg.edit.items && cfg.edit.items.length) {
                    this.items = cfg.edit.items.map((it) => this.mkItem(it));
                }
                this.ensureItemRow();
            }

            window.ZB_VOUCHER = this;
            // Switching single<->double mid-entry would silently reinterpret the
            // already-entered lines — so start a fresh blank voucher when it flips.
            this.$watch('single', () => {
                this.narration = '';
                this.resetLines();
                this.$nextTick(() => this.focusFirstField());
            });
            // Physical Stock's book figure is as-of-date — refresh it when the date moves.
            this.$watch('date', () => {
                if (this.showPhysicalStock && this.mv.item_id) this.refreshBookQty();
            });
            this._onPick = (e) => this.onComboPick(e.detail);
            this._onCreate = (e) => this.onComboCreate(e.detail);
            window.addEventListener('zb:combo-pick', this._onPick);
            window.addEventListener('zb:combo-create', this._onCreate);

            // Unsaved-work guard. The voucher screen had NO protection of any
            // kind: a reload, a tab close, Esc, or an F4-F9 type switch all
            // discarded an in-progress voucher silently. See hasUnsavedWork().
            this._offDirty = registerDirty('voucher', () => this.hasUnsavedWork());

            this.pushContext();
            this.$nextTick(() => this.focusStartField());
        },
        destroy() {
            window.removeEventListener('zb:combo-pick', this._onPick);
            window.removeEventListener('zb:combo-create', this._onCreate);
            if (window.ZB_VOUCHER === this) window.ZB_VOUCHER = null;
            if (this._offDirty) this._offDirty();
        },

        /**
         * Has the operator typed anything into this voucher yet?
         *
         * Keyed on event.isTrusted — true only for events the browser raised
         * from a real key press or click, false for anything a framework
         * dispatches. The masters screens learned this the hard way: probes
         * based on "a field is non-empty" or "values differ from a snapshot"
         * both warned on forms nobody had touched, because Livewire hydrates
         * fields after render and several ship with defaults. A warning that
         * cries wolf is worse than none — it teaches people to click through
         * the one that matters.
         */
        hasUnsavedWork() {
            return !!this._touched;
        },

        /** Bound to input/change on the voucher root (see the blade). */
        noteUserInput(e) {
            if (e && e.isTrusted) this._touched = true;
        },

        /** Called after a successful save, and after a deliberate discard. */
        markVoucherPristine() {
            this._touched = false;
        },

        /**
         * Tally's Quit gate: leaving a voucher that has been typed into asks
         * first. Used by Esc and by anything else that abandons the screen.
         */
        confirmDiscard(what, onYes) {
            if (!this.hasUnsavedWork()) {
                onYes();

                return;
            }
            this.closePickers();
            this.$store.zb.askAccept({
                title: 'Quit?',
                body: what + ' — this voucher has not been saved. Quit and lose it?',
                onYes: () => {
                    this.markVoucherPristine();
                    onYes();
                },
            });
        },

        typeLabel() {
            return this.types[this.type] ? this.types[this.type].label : this.type;
        },
        displayNumber() {
            const abbr = this.types[this.type] ? this.types[this.type].abbr : this.type;
            return abbr.toUpperCase() + '-' + this.number;
        },

        pushContext() {
            this.$store.zb.pushContext({
                name: 'voucher',
                label: this.typeLabel() + ' Voucher',
                focusEl: null,
                actions: [
                    { key: 'enter', label: 'Next', run: (e) => this.onEnter(e) },
                    // Enter's mirror: walk the same chain backwards. Yields the key
                    // back to the browser while the focused field still has something
                    // to delete, so Backspace keeps its normal editing meaning.
                    {
                        key: 'backspace',
                        label: 'Back',
                        hidden: true,
                        yieldWhen: (e) => !this.$store.zb.fieldSpent(e.target),
                        run: (e) => this.onBackspace(e),
                    },
                    { key: 'ctrl+a', label: 'Accept', allowInInput: true, run: () => this.accept() },
                    { key: 'arrowdown', label: 'Next line', hint: '↓', run: () => this.lineMove(1) },
                    { key: 'arrowup', label: 'Prev line', hint: '↑', run: () => this.lineMove(-1) },
                    { key: 'f2', label: 'Change date', allowInInput: true, run: () => this.changeDate() },
                    // Ctrl+V is Tally's As-Invoice/As-Voucher toggle. It yields to a
                    // textarea so a narration paste is never eaten; elsewhere on the
                    // screen it flips the entry layout for Sales/Purchase.
                    { key: 'ctrl+v', label: this.invoiceToggleLabel(), allowInInput: true, yieldInTextarea: true, run: () => this.toggleInvoiceMode() },
                    // Ctrl+I toggles Item ⇄ Accounting invoice (Tally's Change Mode).
                    // Yields in a textarea so a narration is never eaten.
                    { key: 'ctrl+i', label: this.itemToggleLabel(), allowInInput: true, yieldInTextarea: true, run: () => this.toggleItemMode() },
                    { key: 'alt+p', label: 'Print', allowInInput: true, run: () => this.printCurrent() },
                    { key: 'alt+i', label: 'Add line', allowInInput: true, run: () => this.insertLine() },
                    { key: 'alt+r', label: 'Remove line', allowInInput: true, run: () => this.removeCurrentLine() },
                    // Phase 10A — engage/disengage the TDS deduction. Hidden on every
                    // voucher but a Payment with TDS switched on, so the button bar always
                    // reflects whether a deduction is live on THIS voucher.
                    { key: 'alt+t', label: this.tdsToggleLabel(), allowInInput: true, hidden: !this.showTdsPanel, run: () => this.tdsToggle() },
                    { key: 'alt+d', label: 'Cancel voucher', allowInInput: true, run: () => this.askCancel() },
                ],
                onEsc: () => {
                    if (this.confirmingCancel) return;
                    // Tally asks before abandoning a voucher that has been typed
                    // into. This used to navigate away unconditionally, so Esc
                    // silently destroyed a half-entered voucher.
                    const back = this.editId ? cfg.dayBookUrl : cfg.gatewayUrl;
                    this.confirmDiscard('Leaving this screen', () => {
                        window.location.href = back;
                    });
                },
            });
        },
        updateContextLabel() {
            const top = this.$store.zb.peek();
            if (top && top.name === 'voucher') {
                top.label =
                    this.typeLabel() +
                    (this.showInvoice ? (this.showItemInvoice ? ' Item Invoice' : ' Invoice') : ' Voucher') +
                    // The right button bar names the active context — including whether a
                    // TDS deduction is engaged on the voucher currently being entered.
                    (this.tdsEngaged ? ' · TDS' : '');
                if (top.map && top.map['ctrl+v']) top.map['ctrl+v'].label = this.invoiceToggleLabel();
                if (top.map && top.map['ctrl+i']) top.map['ctrl+i'].label = this.itemToggleLabel();
                if (top.map && top.map['alt+t']) {
                    top.map['alt+t'].label = this.tdsToggleLabel();
                    top.map['alt+t'].hidden = !this.showTdsPanel;
                }
                this.$store.zb.rev++;
            }
        },
        tdsToggleLabel() {
            return this.tdsOn ? 'Don’t deduct TDS' : 'Deduct TDS';
        },

        /* ---- invoice (as-Invoice) mode helpers ---- */
        get isInvoiceType() {
            return this.invoiceTypes.includes(this.type);
        },
        get gstOn() {
            return !!(this.gst && this.gst.enabled);
        },
        get vatOn() {
            return !!(this.vat && this.vat.enabled);
        },
        // Either tax regime active (they are mutually exclusive at the company level).
        get taxOn() {
            return this.gstOn || this.vatOn;
        },
        get taxRegime() {
            return this.gstOn ? 'gst' : this.vatOn ? 'vat' : 'none';
        },
        get quickLedgerIsParty() {
            return this.pendingLedgerCombo === 'vparty';
        },
        get showInvoice() {
            return this.isInvoiceType && this.invoiceMode;
        },
        get showDouble() {
            return !this.single && !this.showInvoice && !this.isStockVoucher && !this.isWorkflowType;
        },
        invoiceToggleLabel() {
            return this.showInvoice ? 'As Voucher' : 'As Invoice';
        },
        // Phase 8A — party side per type. Sales debits the customer; a Credit Note
        // (sales return) credits them. Purchase credits the supplier; a Debit Note
        // (purchase return) debits them. The ledger/tax side is the opposite, so the
        // tax computations below reverse correctly just from this.
        invoicePartySide() {
            return this.type === 'sales' || this.type === 'debit_note' ? 'Dr' : 'Cr';
        },
        invoiceLedgerSide() {
            return this.invoicePartySide() === 'Dr' ? 'Cr' : 'Dr';
        },
        invoiceLedgerLabel() {
            return {
                sales: 'Sales ledger', purchase: 'Purchase ledger',
                credit_note: 'Sales Return ledger', debit_note: 'Purchase Return ledger',
            }[this.type] || 'Sales ledger';
        },
        partyFieldLabel() {
            const supplier = this.type === 'purchase' || this.type === 'debit_note';
            return supplier ? 'Party A/c name (supplier)' : 'Party A/c name (customer)';
        },
        // Phase 8A — the big heading so a Note is never mistaken for an invoice.
        get isNoteType() {
            return this.type === 'credit_note' || this.type === 'debit_note';
        },
        noteHeading() {
            return this.type === 'credit_note' ? 'Credit Note' : this.type === 'debit_note' ? 'Debit Note' : '';
        },
        noteSideLabel() {
            return this.type === 'credit_note' ? 'Sales Return' : this.type === 'debit_note' ? 'Purchase Return' : '';
        },
        // The invoices this Note may reference (client-side list; filtering is 0-network).
        get referenceOptions() {
            return (this.referenceInvoices && this.referenceInvoices[this.type]) || [];
        },
        // Picking a reference does ONE server lookup and pre-fills the party + item
        // lines from the original invoice (the user then adjusts quantities/rates).
        async applyReference(id) {
            id = id ? Number(id) : null;
            this.referenceVoucherId = id;
            if (!id) {
                this.referenceLabel = '';
                return;
            }
            const ref = await this.$wire.referenceInvoice(id);
            if (!ref || !ref.ok) {
                this.referenceVoucherId = null;
                this.referenceLabel = '';
                return;
            }
            this.referenceLabel = ref.display_number + (ref.party_ledger_label ? ' · ' + ref.party_ledger_label : '');
            if (ref.party_ledger_id) {
                this.partyLedgerId = ref.party_ledger_id;
                this.partyLedgerLabel = ref.party_ledger_label || '';
            }
            if (ref.items && ref.items.length && this.stockEnabled) {
                this.itemMode = true;
                this.items = ref.items.map((it) => this.mkItem(it));
            }
        },

        /* ---- Phase 8B: inventory-workflow vouchers (item lines, zero accounting) ---- */
        get isWorkflowType() {
            return this.workflowTypes.includes(this.type);
        },
        get isOrderType() {
            return this.orderTypes.includes(this.type);
        },
        get isStockWorkflowType() {
            return this.stockWorkflowTypes.includes(this.type);
        },
        // The whole workflow layout (heading + party + reference + item grid, NO ledger).
        get showWorkflow() {
            return this.isWorkflowType;
        },
        workflowHeading() {
            return (this.types[this.type] && this.types[this.type].label) || '';
        },
        // Sub-line under the heading, so the movement's meaning is unmistakable.
        workflowSubLabel() {
            return {
                sales_order: 'Order received · commitment only (no stock, no accounting)',
                purchase_order: 'Order placed · commitment only (no stock, no accounting)',
                delivery_note: 'Goods delivered OUT · moves stock, no accounting',
                receipt_note: 'Goods received IN · moves stock, no accounting',
                rejection_out: 'Goods returned to supplier · stock OUT, no accounting',
                rejection_in: 'Goods returned by customer · stock IN, no accounting',
            }[this.type] || '';
        },
        // Supplier-side (Creditors): Purchase Order, Receipt Note, Rejections Out.
        // Customer-side (Debtors): Sales Order, Delivery Note, Rejections In.
        get workflowSupplierSide() {
            return this.type === 'purchase_order' || this.type === 'receipt_note' || this.type === 'rejection_out';
        },
        workflowPartyLabel() {
            return this.workflowSupplierSide ? 'Party A/c name (supplier)' : 'Party A/c name (customer)';
        },
        // What this voucher may reference: a Delivery/Receipt Note fulfils an Order;
        // a Rejection reverses a Delivery/Receipt Note. Orders reference nothing.
        get workflowReferenceOptions() {
            return (this.referenceOrders && this.referenceOrders[this.type]) || [];
        },
        workflowReferenceLabel() {
            return {
                delivery_note: 'Against Sales Order',
                receipt_note: 'Against Purchase Order',
                rejection_out: 'Against Receipt Note',
                rejection_in: 'Against Delivery Note',
            }[this.type] || '';
        },
        get workflowHasReference() {
            return !this.isOrderType && this.workflowReferenceOptions.length > 0;
        },
        // Pre-fill party + PENDING item lines from the referenced order/note. Server
        // lookup — the client never invents a cost. The user then adjusts quantities.
        async applyWorkflowReference(id) {
            id = id ? Number(id) : null;
            this.referenceVoucherId = id;
            if (!id) {
                this.referenceLabel = '';
                return;
            }
            const ref = await this.$wire.referenceOrder(id);
            if (!ref || !ref.ok) {
                this.referenceVoucherId = null;
                this.referenceLabel = '';
                return;
            }
            this.referenceLabel = ref.display_number + (ref.party_ledger_label ? ' · ' + ref.party_ledger_label : '');
            if (ref.party_ledger_id) {
                this.partyLedgerId = ref.party_ledger_id;
                this.partyLedgerLabel = ref.party_ledger_label || '';
            }
            // Pre-fill the outstanding lines (pending qty), then a blank row to continue.
            if (ref.items && ref.items.length) {
                this.items = ref.items.map((it) => this.mkItem(it));
            }
            this.ensureItemRow();
        },
        // A Sales/Purchase invoice may bill against a Delivery/Receipt Note that ALREADY
        // moved the goods. Options are the open notes; picking one drives the safeguard.
        get invoiceDeliveryOptions() {
            return (this.referenceDeliveries && this.referenceDeliveries[this.type]) || [];
        },
        get invoiceCanReferenceDelivery() {
            return (this.type === 'sales' || this.type === 'purchase') && this.invoiceDeliveryOptions.length > 0;
        },
        // True once a Sales/Purchase invoice is tied to a Delivery/Receipt Note: the
        // CLIENT skip — this invoice will NOT move stock (the note already did). The
        // server independently enforces and asserts the same thing.
        get invoiceSkipsStock() {
            return (this.type === 'sales' || this.type === 'purchase') && !!this.referenceVoucherId;
        },
        noteLabelForDelivery() {
            return this.type === 'sales' ? 'Against Delivery Note' : 'Against Receipt Note';
        },
        // An invoice picking the Delivery/Receipt Note it bills against: pre-fill party +
        // the delivered item lines (same server endpoint), and flag the stock skip.
        async applyDeliveryReference(id) {
            id = id ? Number(id) : null;
            this.referenceVoucherId = id;
            if (!id) {
                this.referenceLabel = '';
                return;
            }
            const ref = await this.$wire.referenceOrder(id);
            if (!ref || !ref.ok) {
                this.referenceVoucherId = null;
                this.referenceLabel = '';
                return;
            }
            this.referenceLabel = ref.display_number + (ref.party_ledger_label ? ' · ' + ref.party_ledger_label : '');
            if (ref.party_ledger_id) {
                this.partyLedgerId = ref.party_ledger_id;
                this.partyLedgerLabel = ref.party_ledger_label || '';
            }
            if (ref.items && ref.items.length && this.stockEnabled) {
                this.itemMode = true;
                this.items = ref.items.map((it) => this.mkItem(it));
            }
        },
        // Guarantee a trailing blank row so the grid is always ready for the next item.
        ensureItemRow() {
            if (!this.items.some((it) => !it.stock_item_id)) {
                this.items.push(this.mkItem());
            }
        },

        /* ---- item invoice (Phase 6B) ---- */
        // Item invoice is only offered for Sales/Purchase when the company keeps
        // stock. showAcctInvoice / showItemInvoice partition the invoice layout.
        get showItemInvoice() {
            return this.showInvoice && this.itemMode && this.stockEnabled;
        },
        get showAcctInvoice() {
            return this.showInvoice && !this.showItemInvoice;
        },
        itemToggleLabel() {
            return this.showItemInvoice ? 'Accounting Invoice' : 'Item Invoice';
        },
        mkItem(it) {
            return {
                uid: this.itemSeq++,
                stock_item_id: (it && it.stock_item_id) || null,
                stock_item_label: (it && it.stock_item_label) || '',
                godown_id: it && it.godown_id != null ? it.godown_id : this.mainGodownId,
                godown_label: (it && it.godown_label) || this.mainGodownLabel(),
                qty: it && it.qty != null && it.qty !== '' ? String(it.qty) : '',
                rate: it && it.rate != null && it.rate !== '' ? String(it.rate) : '',
            };
        },
        mainGodownLabel() {
            const g = (this.$store.masters.godowns || []).find((x) => x.id === this.mainGodownId);
            return g ? g.name : '';
        },
        masterStockItem(id) {
            return (this.$store.masters.stockItems || []).find((s) => s.id === id) || null;
        },
        // The item's own tax rate (used for item-sourced GST/VAT; overrides ledger).
        itemTaxRate(it) {
            const s = this.masterStockItem(it.stock_item_id);
            return s && s.gst_rate != null ? Number(s.gst_rate) : 0;
        },
        itemUnitSymbol(it) {
            const s = this.masterStockItem(it.stock_item_id);
            return s && s.unit_symbol ? s.unit_symbol : '';
        },
        itemAmount(it) {
            return Math.round(this.num(it.qty) * this.num(it.rate) * 100) / 100;
        },
        get filledItems() {
            return this.items.filter((it) => it.stock_item_id && this.num(it.qty) > 0);
        },
        get itemSubtotal() {
            return this.filledItems.reduce((s, it) => s + this.itemAmount(it), 0);
        },
        setDefaultItemLedger() {
            const d = this.itemInvoiceDefaults[this.type];
            this.itemLedgerId = d ? d.ledger_id || null : null;
            this.itemLedgerLabel = d ? d.ledger_label || '' : '';
        },

        /* ---- stock-movement vouchers (Phase 6C) ---- */
        get isStockVoucher() {
            return this.type === 'stock_journal' || this.type === 'physical_stock';
        },
        get showStockJournal() {
            return this.type === 'stock_journal';
        },
        get showPhysicalStock() {
            return this.type === 'physical_stock';
        },
        get sjIsTransfer() {
            return this.mv.mode === 'transfer';
        },
        // Physical Stock live variance = counted − book (book fetched once per item/godown).
        get physVariance() {
            if (this.mv.book_qty === null) return null;
            return Math.round((this.num(this.mv.counted_qty) - this.mv.book_qty) * 10000) / 10000;
        },
        physVarianceLabel() {
            const v = this.physVariance;
            if (v === null) return '';
            if (Math.abs(v) < 1e-9) return 'No variance — nothing to adjust';
            return v > 0 ? ('Excess +' + v + ' (stock IN)') : ('Shortage ' + v + ' (stock OUT)');
        },
        resetMovement() {
            this.mv = {
                mode: 'transfer',
                item_id: null, item_label: '',
                qty: '',
                from_godown_id: this.mainGodownId, from_godown_label: this.mainGodownLabel(),
                to_godown_id: null, to_godown_label: '',
                godown_id: this.mainGodownId, godown_label: this.mainGodownLabel(),
                counted_qty: '',
                book_qty: null,
            };
        },
        // Fetch the book quantity for Physical Stock — the one permitted server touch
        // (the variance is then computed client-side as the user types the count).
        async refreshBookQty() {
            if (!this.showPhysicalStock || !this.mv.item_id) {
                this.mv.book_qty = null;
                return;
            }
            try {
                // When altering, exclude this voucher's own variance row from the book.
                const q = await this.$wire.bookQty(this.mv.item_id, this.mv.godown_id, this.date, this.editId || null);
                this.mv.book_qty = Number(q);
            } catch (e) {
                this.mv.book_qty = null;
            }
        },
        get stockBalanced() {
            if (this.showStockJournal) {
                if (this.sjIsTransfer) {
                    return !!this.mv.item_id && !!this.mv.from_godown_id && !!this.mv.to_godown_id
                        && this.mv.from_godown_id !== this.mv.to_godown_id && this.num(this.mv.qty) > 0;
                }
                return !!this.mv.item_id && this.num(this.mv.qty) > 0; // consumption
            }
            // physical stock: needs an item + a fetched book figure + a counted qty
            return !!this.mv.item_id && this.mv.book_qty !== null && String(this.mv.counted_qty).trim() !== '';
        },
        buildMovementPayload() {
            if (this.showStockJournal) {
                if (this.sjIsTransfer) {
                    return { mode: 'transfer', stock_item_id: this.mv.item_id, qty: this.num(this.mv.qty),
                        from_godown_id: this.mv.from_godown_id, to_godown_id: this.mv.to_godown_id };
                }
                return { mode: 'consumption', stock_item_id: this.mv.item_id, qty: this.num(this.mv.qty), godown_id: this.mv.godown_id };
            }
            return { stock_item_id: this.mv.item_id, godown_id: this.mv.godown_id, counted_qty: this.num(this.mv.counted_qty) };
        },

        get invoiceTotal() {
            if (this.showItemInvoice) return this.itemSubtotal;
            return this.filledLines.reduce((s, l) => s + this.num(l.amount), 0);
        },

        /* ---- GST (Phase 5B): tax computed client-side, mirrored on the server ---- */
        masterLedger(id) {
            return (this.$store.masters.ledgers || []).find((l) => l.id === id) || null;
        },
        partyState() {
            const p = this.masterLedger(this.partyLedgerId);
            return p ? p.state : null;
        },
        // A supply is intra-state when party.state == company.state. When either is
        // blank we default to intra-state (CGST+SGST) — mirrors GstService.
        isIntraState() {
            const cs = ((this.gst && this.gst.company_state) || '').trim().toLowerCase();
            const ps = (this.partyState() || '').trim().toLowerCase();
            if (!cs || !ps) return true;
            return cs === ps;
        },
        /**
         * Compute the tax for the current invoice, grouped by rate slab, in integer
         * paise — the exact same arithmetic as the server (round(baseP*r/200) for the
         * two halves, round(baseP*r/100) for IGST). Returns the tax lines to post +
         * the live-display rows.
         */
        get taxComputation() {
            if (!this.showInvoice || !this.taxOn) {
                return { taxLines: [], taxTotalPaise: 0, slabs: [], intra: true };
            }
            return this.vatOn ? this._vatComputation() : this._gstComputation();
        },
        /**
         * Taxable base grouped by rate slab (paise). In item mode the base and its
         * rate come from each Stock Item (its own rate overrides any ledger rate);
         * in accounting mode from the ledger allocation lines. The single seam both
         * tax engines read, mirroring the server's item-vs-ledger split.
         */
        taxBaseBySlab() {
            const baseBySlab = {};
            if (this.showItemInvoice) {
                this.filledItems.forEach((it) => {
                    const rate = this.itemTaxRate(it);
                    baseBySlab[rate] = (baseBySlab[rate] || 0) + Math.round(this.itemAmount(it) * 100);
                });
            } else {
                this.filledLines.forEach((l) => {
                    const led = this.masterLedger(l.ledger_id);
                    const rate = led && led.gst_rate != null ? Number(led.gst_rate) : 0;
                    baseBySlab[rate] = (baseBySlab[rate] || 0) + Math.round(this.num(l.amount) * 100);
                });
            }
            return baseBySlab;
        },
        /** GST: CGST/SGST (intra) or IGST (inter), grouped by rate slab. */
        _gstComputation() {
            const intra = this.isIntraState();
            // Sales & Credit Note are output-tax; Purchase & Debit Note are input-tax.
            const role = this.type === 'sales' || this.type === 'credit_note' ? 'output' : 'input';
            const lSide = this.invoiceLedgerSide();
            const tl = (this.gst && this.gst.tax_ledgers) || {};

            const baseBySlab = this.taxBaseBySlab();

            const slabs = [];
            const taxLines = [];
            let taxTotal = 0;
            Object.keys(baseBySlab)
                .map(Number)
                .sort((a, b) => a - b)
                .forEach((rate) => {
                    const baseP = baseBySlab[rate];
                    let cgst = 0,
                        sgst = 0,
                        igst = 0;
                    if (rate > 0) {
                        if (intra) {
                            const half = Math.round((baseP * rate) / 200);
                            cgst = half;
                            sgst = half;
                        } else {
                            igst = Math.round((baseP * rate) / 100);
                        }
                    }
                    slabs.push({ rate, baseP, cgst, sgst, igst });
                    const push = (type, label, paise) => {
                        if (paise <= 0) return;
                        const lid = tl[role + '.' + type];
                        if (!lid) return;
                        taxLines.push({ ledger_id: lid, dr_cr: lSide, amount: Math.round(paise) / 100, tax_type: type, label, rate });
                        taxTotal += paise;
                    };
                    push('central', 'CGST', cgst);
                    push('state', 'SGST', sgst);
                    push('integrated', 'IGST', igst);
                });
            return { taxLines, taxTotalPaise: taxTotal, slabs, intra };
        },
        /** VAT (Nepal): a single flat VAT line per rate slab — no intra/inter split. */
        _vatComputation() {
            const role = this.type === 'sales' || this.type === 'credit_note' ? 'output' : 'input';
            const lSide = this.invoiceLedgerSide();
            const lid = ((this.vat && this.vat.tax_ledgers) || {})[role + '.vat'];
            const baseBySlab = this.taxBaseBySlab();
            const slabs = [];
            const taxLines = [];
            let taxTotal = 0;
            Object.keys(baseBySlab)
                .map(Number)
                .sort((a, b) => a - b)
                .forEach((rate) => {
                    const baseP = baseBySlab[rate];
                    const vat = rate > 0 ? Math.round((baseP * rate) / 100) : 0;
                    slabs.push({ rate, baseP, vat });
                    if (vat > 0 && lid) {
                        taxLines.push({ ledger_id: lid, dr_cr: lSide, amount: Math.round(vat) / 100, tax_type: 'vat', label: 'VAT', rate });
                        taxTotal += vat;
                    }
                });
            return { taxLines, taxTotalPaise: taxTotal, slabs, intra: true };
        },
        get taxDisplayLines() {
            return this.taxComputation.taxLines;
        },
        get invoiceTaxTotal() {
            return this.taxComputation.taxTotalPaise / 100;
        },
        get invoiceGrandTotal() {
            const taxableP = Math.round(this.invoiceTotal * 100);
            return (taxableP + this.taxComputation.taxTotalPaise) / 100;
        },
        supplyKindLabel() {
            if (!this.showInvoice) return '';
            if (this.vatOn) return 'VAT (Nepal · single rate)';
            if (this.gstOn) return this.isIntraState() ? 'Intra-state (CGST + SGST)' : 'Inter-state (IGST)';
            return '';
        },
        // Label for the tax-inclusive total + hint, contextual to the active regime.
        taxRegimeLabel() {
            return this.vatOn ? 'VAT' : 'GST';
        },

        /* ---- bill-wise (Phase 5C): allocation sub-screen, 0-network ---- */
        isBillWise(id) {
            const l = this.masterLedger(id);
            return !!this.billEnabled && !!(l && l.maintain_bill_by_bill);
        },
        allocSumPaise(key) {
            return (this.allocs[key] || []).reduce((s, r) => s + Math.round(this.num(r.amount) * 100), 0);
        },
        /** Every bill-wise target on the current voucher that must be allocated. */
        billTargets() {
            const P = (n) => Math.round(n * 100);
            const out = [];
            if (this.showInvoice) {
                if (this.partyLedgerId && this.isBillWise(this.partyLedgerId)) {
                    out.push({ key: 'party', ledgerId: this.partyLedgerId, label: this.partyLedgerLabel, side: this.invoicePartySide(), amountPaise: Math.round(this.invoiceGrandTotal * 100) });
                }
            } else if (this.single) {
                if (this.accountLedgerId && this.isBillWise(this.accountLedgerId)) {
                    out.push({ key: 'account', ledgerId: this.accountLedgerId, label: this.accountLedgerLabel, side: this.accountSide(), amountPaise: P(this.singleTotal) });
                }
                this.filledLines.forEach((l) => {
                    if (this.isBillWise(l.ledger_id)) out.push({ key: 'line:' + l.uid, ledgerId: l.ledger_id, label: l.ledger_label, side: this.partSide(), amountPaise: P(this.num(l.amount)) });
                });
            } else {
                this.filledLines.forEach((l) => {
                    if (this.isBillWise(l.ledger_id)) out.push({ key: 'line:' + l.uid, ledgerId: l.ledger_id, label: l.ledger_label, side: l.dr_cr, amountPaise: P(this.num(l.amount)) });
                });
            }
            return out;
        },
        isTargetAllocated(t) {
            return t.amountPaise > 0 && this.allocSumPaise(t.key) === t.amountPaise;
        },
        billTypeLabel(t) {
            return { new: 'New Ref', against: 'Against Ref', advance: 'Advance', onaccount: 'On Account' }[t] || t;
        },
        openBillAlloc(t) {
            this.closePickers();
            this.billTarget = t;
            const existing = this.allocs[t.key];
            if (existing && existing.length) {
                this.billRows = existing.map((r) => ({ ref_type: r.ref_type, ref_name: r.ref_name, amount: String(r.amount), due_date: r.due_date || '' }));
            } else {
                // default: one New Ref for the whole amount (invoice number pre-filled)
                const defName = t.key === 'party' ? this.referenceNo || this.displayNumber() : '';
                this.billRows = [{ ref_type: 'new', ref_name: defName, amount: String(t.amountPaise / 100), due_date: '' }];
            }
            this.showBillAlloc = true;
            this.$store.zb.pushContext({
                name: 'bill-alloc',
                label: 'Bill Allocation — ' + t.label,
                focusEl: '#bill-row-0-type',
                actions: [
                    { key: 'alt+i', label: 'Add ref', allowInInput: true, run: () => this.billAddRow() },
                    { key: 'alt+r', label: 'Remove ref', allowInInput: true, run: () => this.billRemoveRow() },
                    { key: 'ctrl+a', label: 'Accept allocation', allowInInput: true, run: () => this.billAccept() },
                ],
                onEsc: () => this.billCancel(),
                onPop: () => {
                    this.showBillAlloc = false;
                },
            });
            this.$nextTick(() => {
                const el = document.querySelector('#bill-row-0-type');
                if (el) el.focus();
            });
        },
        get billTargetAmount() {
            return this.billTarget ? this.billTarget.amountPaise / 100 : 0;
        },
        get billAllocated() {
            return this.billRows.reduce((s, r) => s + this.num(r.amount), 0);
        },
        get billRemaining() {
            return Math.round((this.billTargetAmount - this.billAllocated) * 100) / 100;
        },
        get billFullyAllocated() {
            return Math.abs(this.billRemaining) < 0.005 && this.billRows.every((r) => this.num(r.amount) > 0 && (r.ref_name || '').trim() !== '');
        },
        /** Open bills of the target ledger on the opposite side (settleable via Against Ref). */
        againstOptions() {
            const list = this.openBills[this.billTarget ? this.billTarget.ledgerId : -1] || [];
            const opp = this.billTarget && this.billTarget.side === 'Dr' ? 'Cr' : 'Dr';
            return list.filter((b) => b.side === opp && b.pending_paise > 0);
        },
        onBillRefTypeChange(i) {
            const row = this.billRows[i];
            if (row.ref_type !== 'new' && row.ref_type !== 'advance') row.due_date = '';
            if (row.ref_type === 'against') {
                const opts = this.againstOptions();
                if (opts.length && !opts.some((o) => o.ref_name === row.ref_name)) row.ref_name = '';
            }
            if (row.ref_type === 'onaccount' && !row.ref_name) row.ref_name = 'On Account';
        },
        onBillAgainstPick(i) {
            const row = this.billRows[i];
            const bill = this.againstOptions().find((o) => o.ref_name === row.ref_name);
            if (bill) {
                const rem = Math.max(0, this.billRemaining + this.num(row.amount));
                row.amount = String(Math.min(bill.pending, rem));
            }
        },
        billAddRow() {
            const rem = this.billRemaining;
            this.billRows.push({ ref_type: 'new', ref_name: '', amount: rem > 0 ? String(rem) : '', due_date: '' });
            this.$nextTick(() => {
                const el = document.querySelector('#bill-row-' + (this.billRows.length - 1) + '-type');
                if (el) el.focus();
            });
        },
        billRemoveRow() {
            if (this.billRows.length > 1) this.billRows.pop();
        },
        billAccept() {
            if (!this.billFullyAllocated) {
                this.flashMsg('Allocate the full amount — ' + this.fmt(Math.abs(this.billRemaining)) + ' remaining', 'warn');
                return;
            }
            this.allocs[this.billTarget.key] = this.billRows.map((r) => ({
                ref_type: r.ref_type,
                ref_name: (r.ref_name || '').trim(),
                amount: this.num(r.amount),
                due_date: r.ref_type === 'new' || r.ref_type === 'advance' ? r.due_date || null : null,
            }));
            if (this.$store.zb.activeName() === 'bill-alloc') this.$store.zb.popContext();
            this.showBillAlloc = false;
            this.billTarget = null;
            // continue: allocate the next target, or post
            this.$nextTick(() => this.accept());
        },
        billCancel() {
            if (this.$store.zb.activeName() === 'bill-alloc') this.$store.zb.popContext();
            this.showBillAlloc = false;
            this.billTarget = null;
        },
        /** After a post, mirror the server's bill movement into the local cache. */
        updateOpenBillsFromPayload(lines) {
            (lines || []).forEach((l) => {
                if (!l.allocations || !l.allocations.length) return;
                const lid = l.ledger_id;
                const list = (this.openBills[lid] = this.openBills[lid] || []);
                l.allocations.forEach((a) => {
                    const signed = (l.dr_cr === 'Dr' ? 1 : -1) * Math.round(this.num(a.amount) * 100);
                    let bill = list.find((b) => b.ref_name === a.ref_name);
                    if (!bill) {
                        bill = { ref_name: a.ref_name, pending_paise: 0, pending: 0, side: 'Dr', due_date: a.due_date || null, original: this.num(a.amount) };
                        list.push(bill);
                    }
                    const cur = (bill.side === 'Dr' ? 1 : -1) * bill.pending_paise + signed;
                    bill.pending_paise = Math.abs(cur);
                    bill.side = cur >= 0 ? 'Dr' : 'Cr';
                    bill.pending = bill.pending_paise / 100;
                });
                this.openBills[lid] = list.filter((b) => b.pending_paise > 0);
            });
        },

        /* ---- cost centres (Phase 5D): analytical allocation sub-screen ---- */
        isCostApplicable(id) {
            const l = this.masterLedger(id);
            return !!this.costEnabled && !!(l && l.cost_centres_applicable);
        },
        costSumPaise(key) {
            return (this.costAllocs[key] || []).reduce((s, r) => s + Math.round(this.num(r.amount) * 100), 0);
        },
        /** Every cost-applicable ledger line that must be allocated to cost centres. */
        costTargets() {
            const P = (n) => Math.round(n * 100);
            const out = [];
            // Item invoice: the single revenue ledger lives in itemLedgerId (outside
            // this.lines), so it must be added explicitly — otherwise a cost-applicable
            // Sales/Purchase ledger would be un-postable (the server demands an
            // allocation the client never collects).
            if (this.showItemInvoice && this.itemLedgerId && this.isCostApplicable(this.itemLedgerId)) {
                out.push({ key: 'itemledger', ledgerId: this.itemLedgerId, label: this.itemLedgerLabel, side: this.invoiceLedgerSide(), amountPaise: P(this.itemSubtotal) });
            }
            if (this.single && this.accountLedgerId && this.isCostApplicable(this.accountLedgerId)) {
                out.push({ key: 'account', ledgerId: this.accountLedgerId, label: this.accountLedgerLabel, side: this.accountSide(), amountPaise: P(this.singleTotal) });
            }
            this.filledLines.forEach((l) => {
                if (this.isCostApplicable(l.ledger_id)) {
                    const side = this.showInvoice ? this.invoiceLedgerSide() : this.single ? this.partSide() : l.dr_cr;
                    out.push({ key: 'line:' + l.uid, ledgerId: l.ledger_id, label: l.ledger_label, side, amountPaise: P(this.num(l.amount)) });
                }
            });
            return out;
        },
        isCostTargetAllocated(t) {
            return t.amountPaise > 0 && this.costSumPaise(t.key) === t.amountPaise;
        },
        openCostAlloc(t) {
            this.closePickers();
            this.costTarget = t;
            const existing = this.costAllocs[t.key];
            if (existing && existing.length) {
                this.costRows = existing.map((r) => ({ _uid: this.costRowSeq++, cost_centre_id: r.cost_centre_id, cost_centre_label: r.cost_centre_label || '', amount: String(r.amount) }));
            } else {
                this.costRows = [{ _uid: this.costRowSeq++, cost_centre_id: null, cost_centre_label: '', amount: String(t.amountPaise / 100) }];
            }
            this.showCostAlloc = true;
            this.$store.zb.pushContext({
                name: 'cost-alloc',
                label: 'Cost Allocation — ' + t.label,
                focusEl: '.zb-cost-card .zb-combo-input',
                actions: [
                    { key: 'alt+i', label: 'Add centre', allowInInput: true, run: () => this.costAddRow() },
                    { key: 'alt+r', label: 'Remove centre', allowInInput: true, run: () => this.costRemoveRow() },
                    { key: 'ctrl+a', label: 'Accept allocation', allowInInput: true, run: () => this.costAccept() },
                ],
                onEsc: () => this.costCancel(),
                onPop: () => {
                    this.showCostAlloc = false;
                },
            });
            this.$nextTick(() => this.focusField('.zb-cost-card .zb-combo-input'));
        },
        get costTargetAmount() {
            return this.costTarget ? this.costTarget.amountPaise / 100 : 0;
        },
        get costAllocated() {
            return this.costRows.reduce((s, r) => s + this.num(r.amount), 0);
        },
        get costRemaining() {
            return Math.round((this.costTargetAmount - this.costAllocated) * 100) / 100;
        },
        get costFullyAllocated() {
            return Math.abs(this.costRemaining) < 0.005 && this.costRows.every((r) => this.num(r.amount) > 0 && r.cost_centre_id != null);
        },
        costAddRow() {
            const rem = this.costRemaining;
            const uid = this.costRowSeq++;
            this.costRows.push({ _uid: uid, cost_centre_id: null, cost_centre_label: '', amount: rem > 0 ? String(rem) : '' });
            this.$nextTick(() => this.focusField('#cost-row-' + uid + ' .zb-combo-input'));
        },
        costRemoveRow() {
            if (this.costRows.length > 1) this.costRows.pop();
        },
        costAccept() {
            if (!this.costFullyAllocated) {
                this.flashMsg('Allocate the full amount to cost centres — ' + this.fmt(Math.abs(this.costRemaining)) + ' remaining', 'warn');
                return;
            }
            this.costAllocs[this.costTarget.key] = this.costRows.map((r) => ({
                cost_centre_id: r.cost_centre_id,
                cost_centre_label: r.cost_centre_label || '',
                amount: this.num(r.amount),
            }));
            if (this.$store.zb.activeName() === 'cost-alloc') this.$store.zb.popContext();
            this.showCostAlloc = false;
            this.costTarget = null;
            this.$nextTick(() => this.accept());
        },
        costCancel() {
            if (this.$store.zb.activeName() === 'cost-alloc') this.$store.zb.popContext();
            this.showCostAlloc = false;
            this.costTarget = null;
        },
        attachCostAllocs(obj, key) {
            const a = this.costAllocs[key];
            if (a && a.length && this.ledgerCostFlag(obj.ledger_id)) obj.cost_allocations = a.map((r) => ({ cost_centre_id: r.cost_centre_id, amount: r.amount }));
            return obj;
        },

        /* ---- TDS (Phase 10A) --------------------------------------------------
           Everything below MIRRORS TdsService, in integer paise, so the figure the
           user watches while typing is byte-for-byte the figure the server will
           accept. The server recomputes all of it on accept and rejects a mismatch,
           so nothing here is ever trusted — it only has to be right, not authoritative. */

        // The panel exists for a Payment when the company deducts TDS. A Payment is
        // never an invoice, a stock voucher or a workflow voucher, so this is exclusive.
        get showTdsPanel() {
            return this.tdsEnabled && this.type === 'payment' && !!this.tdsPayableLedgerId;
        },
        masterTdsSection(id) {
            return this.tdsSections.find((s) => s.id === id) || null;
        },
        masterDeductee(id) {
            return this.tdsDeductees.find((d) => d.id === id) || null;
        },
        // A GST/VAT/TDS duty ledger. Never part of the TDS base — which is exactly how
        // "TDS is deducted on the value excluding GST" falls out with no special case.
        isDutyLedger(id) {
            const l = this.masterLedger(id);
            return !!(l && l.tax_type);
        },
        // A Payment debits its expenses and credits the bank. In single-entry mode a fresh
        // particular defaults to Dr and the bank leg lives outside `lines`; on alter the
        // saved credit legs are present. Excluding the credits covers both without a branch.
        tdsDebitLines() {
            return this.filledLines.filter((l) => l.dr_cr !== 'Cr');
        },
        // The taxable base: the debits that are NOT duty ledgers.
        get tdsBasePaise() {
            if (!this.showTdsPanel) return 0;
            return this.tdsDebitLines()
                .filter((l) => !this.isDutyLedger(l.ledger_id))
                .reduce((s, l) => s + Math.round(this.num(l.amount) * 100), 0);
        },
        // Everything debited, GST included — what leaves the company before the deduction.
        get tdsGrossPaise() {
            if (!this.showTdsPanel) return 0;
            return this.tdsDebitLines().reduce((s, l) => s + Math.round(this.num(l.amount) * 100), 0);
        },
        get tdsGstPaise() {
            return this.tdsGrossPaise - this.tdsBasePaise;
        },
        get tdsCanEngage() {
            return this.showTdsPanel && !!this.tdsDeducteeId && !!this.tdsSectionId && this.tdsBasePaise > 0;
        },
        get tdsEngaged() {
            return this.tdsOn && this.tdsCanEngage;
        },
        // The rate after the deductee-type choice and after Section 206AA (no PAN ⇒ the
        // section's floor, 20% by default and 5% for 194Q — never below the section rate).
        get tdsRate() {
            const sec = this.masterTdsSection(this.tdsSectionId);
            if (!sec) return 0;
            const d = this.masterDeductee(this.tdsDeducteeId);
            let rate = d && d.deductee_type === 'company_firm_llp' && sec.rate_company != null ? sec.rate_company : sec.rate;
            if (!d || !d.deductee_pan || !String(d.deductee_pan).trim()) {
                rate = Math.max(rate, sec.no_pan_rate != null ? sec.no_pan_rate : 20);
            }
            return rate;
        },
        get tdsNoPan() {
            const d = this.masterDeductee(this.tdsDeducteeId);
            return !!this.tdsDeducteeId && (!d || !d.deductee_pan || !String(d.deductee_pan).trim());
        },
        // This year's payments to this deductee under this section. On alter, the voucher's
        // own prior contribution is dropped — the server's excludeVoucherId, client-side.
        tdsPriorRows() {
            const rows = this.tdsLedger[this.tdsDeducteeId + ':' + this.tdsSectionId] || [];
            const inWindow = this.editId ? rows.filter((r) => r.voucher_id !== this.editId) : rows;
            const sec = this.masterTdsSection(this.tdsSectionId);
            // 194I aggregates over the payment's CALENDAR MONTH; everything else over the year.
            if (sec && sec.threshold_period === 'monthly') {
                const month = (this.date || '').slice(0, 7);
                return inWindow.filter((r) => (r.date || '').slice(0, 7) === month);
            }
            return inWindow;
        },
        tdsPrior() {
            return this.tdsPriorRows().reduce(
                (a, r) => ({ paid: a.paid + Math.round(r.payment * 100), deducted: a.deducted + Math.round(r.deducted * 100) }),
                { paid: 0, deducted: 0 },
            );
        },
        // Σ of the prior bills that individually exceeded the single-bill threshold (194C).
        tdsQualifyingPriorPaise(singlePaise) {
            return this.tdsPriorRows()
                .map((r) => Math.round(r.payment * 100))
                .filter((p) => p > singlePaise)
                .reduce((s, p) => s + p, 0);
        },
        /**
         * The deduction, in paise. Mirrors TdsService::computeDetailed():
         *   • nothing until the window's aggregate crosses the threshold;
         *   • on crossing, tax the WHOLE aggregate and subtract what was already
         *     withheld — which yields the catch-up and then the steady state;
         *   • 194C taxes only the individually-large bills until the annual line falls;
         *   • 194Q taxes only the excess above the threshold.
         */
        get tdsDeductedPaise() {
            if (!this.tdsEngaged) return 0;
            const sec = this.masterTdsSection(this.tdsSectionId);
            if (!sec) return 0;

            const base = this.tdsBasePaise;
            if (base <= 0) return 0;

            const rate = this.tdsRate;
            const tax = (p) => Math.round((p * rate) / 100);
            const prior = this.tdsPrior();
            const annual = sec.threshold_annual != null ? Math.round(sec.threshold_annual * 100) : null;
            const single = sec.threshold_single != null ? Math.round(sec.threshold_single * 100) : null;
            const aggregate = prior.paid + base;

            if (sec.deduct_basis === 'excess') {
                if (annual === null) return Math.max(0, tax(aggregate) - prior.deducted);
                if (aggregate <= annual) return 0;
                return Math.max(0, tax(aggregate - annual) - prior.deducted);
            }
            if (annual !== null && aggregate > annual) return Math.max(0, tax(aggregate) - prior.deducted);
            if (single !== null) {
                const qualifying = this.tdsQualifyingPriorPaise(single) + (base > single ? base : 0);
                if (qualifying === 0) return 0;
                return Math.max(0, tax(qualifying) - prior.deducted);
            }
            if (annual === null) return Math.max(0, tax(aggregate) - prior.deducted);
            return 0;
        },
        get tdsDeducted() {
            return this.tdsDeductedPaise / 100;
        },
        get tdsNetPaid() {
            return (this.tdsGrossPaise - this.tdsDeductedPaise) / 100;
        },
        // Plain-language reason, so a user staring at ₹5,500 withheld from a ₹15,000 bill
        // is told why before they post it, not after the vendor calls.
        get tdsReason() {
            if (!this.tdsCanEngage) return '';
            const sec = this.masterTdsSection(this.tdsSectionId);
            if (!sec) return '';
            const prior = this.tdsPrior();
            const aggregate = prior.paid + this.tdsBasePaise;
            const annual = sec.threshold_annual != null ? Math.round(sec.threshold_annual * 100) : null;
            const window = sec.threshold_period === 'monthly' ? 'this month' : 'this year';

            if (this.tdsDeductedPaise === 0) {
                return annual === null
                    ? 'No TDS on this payment.'
                    : 'Aggregate ' + this.fmt(aggregate / 100) + ' ' + window + ' is within the ' + this.fmt(annual / 100) + ' threshold — no TDS.';
            }
            if (prior.deducted === 0 && prior.paid > 0) {
                return (
                    'Aggregate ' + this.fmt(aggregate / 100) + ' ' + window + ' crosses the ' + this.fmt(annual / 100) +
                    ' threshold — deducted on the full aggregate, catching up on ' + this.fmt(prior.paid / 100) + ' paid earlier with no deduction.'
                );
            }
            return 'Threshold already crossed ' + window + ' — deducted at ' + this.tdsRate + '% on this payment.';
        },
        // The bank/cash leg is the credit side that is not a duty ledger. TDS reduces it,
        // so there has to be exactly one — otherwise we cannot know which one to short-pay.
        tdsBankLegs(lines) {
            return lines.filter((l) => l.dr_cr === 'Cr' && !this.isDutyLedger(l.ledger_id));
        },
        /**
         * Turn a balanced two-sided Payment into the three-line TDS shape:
         *   Dr Expense (unchanged) · Cr TDS Payable (the deduction) · Cr Bank (net).
         * Balanced by construction — the bank leg gives up exactly what the TDS line takes.
         * A zero deduction leaves the voucher untouched, so a below-threshold payment posts
         * the ordinary two lines it always did.
         */
        applyTdsToLines(lines) {
            if (!this.tdsEngaged) return lines;
            const tds = this.tdsDeductedPaise;
            if (tds <= 0) return lines;

            const out = lines.map((l) => Object.assign({}, l));
            const bankIdx = out.findIndex((l) => l.dr_cr === 'Cr' && !this.isDutyLedger(l.ledger_id));
            if (bankIdx < 0) return lines; // accept() blocks this case before we get here

            out[bankIdx].amount = Math.round(out[bankIdx].amount * 100 - tds) / 100;
            out.push({ ledger_id: this.tdsPayableLedgerId, dr_cr: 'Cr', amount: tds / 100 });
            return out;
        },
        tdsToggle() {
            if (!this.showTdsPanel) return;
            this.tdsOn = !this.tdsOn;
            if (this.tdsOn && !this.tdsSectionId && this.tdsDeducteeId) this.tdsDefaultSection();
            this.updateContextLabel();
        },
        // Picking a deductee defaults its usual section (a lawyer → 393-194J) and, unless
        // the user has opted out in F12, engages the deduction straight away.
        tdsDefaultSection() {
            const d = this.masterDeductee(this.tdsDeducteeId);
            if (!d || !d.default_tds_section_id) return;
            const sec = this.masterTdsSection(d.default_tds_section_id);
            if (!sec) return; // its default section is not in force this year — user picks one
            this.tdsSectionId = sec.id;
            this.tdsSectionLabel = sec.name;
        },
        tdsOnDeducteePicked() {
            this.tdsDefaultSection();
            if (this.$store.config && this.$store.config.get('tdsAuto') && this.tdsSectionId) this.tdsOn = true;
            this.updateContextLabel();
        },
        tdsReset() {
            this.tdsOn = false;
            this.tdsDeducteeId = null;
            this.tdsDeducteeLabel = '';
            this.tdsSectionId = null;
            this.tdsSectionLabel = '';
            // Phase 10B — the challan number and date belong to one remittance, so they
            // reset; the BSR is the deductor's bank branch, unchanged bill-to-bill, so a
            // continuous-entry session PRESERVES it (set only in resetLines' caller).
            this.challanNumber = '';
            this.challanDate = '';
        },

        /* ---- TDS challan capture (Phase 10B) ----
           A Payment that DEBITS the TDS Payable ledger is a remittance to the government;
           it carries the real bank challan identity the Form 26Q return needs. */
        get isTdsRemittance() {
            if (this.type !== 'payment') return false;
            const debits = this.single
                ? (this.accountLedgerId ? [{ ledger_id: this.accountLedgerId, dr_cr: this.accountSide() }] : [])
                : this.lines;
            return debits.some((l) => l.ledger_id === this.tdsPayableLedgerId && l.dr_cr === 'Dr');
        },
        get showChallanPanel() {
            return this.tdsEnabled && !!this.tdsPayableLedgerId && this.isTdsRemittance;
        },
        get challanReady() {
            return /^[0-9]{7}$/.test(this.challanBsr || '') && /^[0-9]{1,5}$/.test(this.challanNumber || '') && !!this.challanDate;
        },
        challanPayload() {
            if (!this.showChallanPanel || !this.challanBsr) return null;
            return { bsr_code: this.challanBsr, challan_number: this.challanNumber, deposit_date: this.challanDate || this.date };
        },
        // Re-opening a saved TDS voucher: the stored lines carry the TDS Payable credit and
        // an already-reduced bank leg. Strip the one and restore the other, so the screen
        // shows the GROSS the user originally typed and the panel re-derives the deduction.
        /**
         * Fold a just-posted deduction into the year-to-date cache, so a continuous-entry
         * session stays accurate without a reload. The screen never re-renders after a post
         * (VoucherScreen::post() skips it), so nothing else would refresh this.
         *
         * Without it, the second payment to a deductee would be computed against an
         * aggregate that omits the first — the client would under-deduct, and the server
         * would refuse the voucher. The user would see a rejection they could not explain.
         */
        tdsAppendToLedger(voucherId, date, td) {
            if (!td || !td.deductee_ledger_id) return;
            const key = td.deductee_ledger_id + ':' + td.tds_section_id;
            if (!this.tdsLedger[key]) this.tdsLedger[key] = [];
            // On alter the voucher already has a row — replace it rather than double-count.
            const rows = this.tdsLedger[key].filter((r) => r.voucher_id !== voucherId);
            rows.push({ voucher_id: voucherId, date, payment: td.payment_amount, deducted: td.deducted_amount });
            rows.sort((a, b) => (a.date < b.date ? -1 : a.date > b.date ? 1 : a.voucher_id - b.voucher_id));
            this.tdsLedger[key] = rows;
        },
        tdsRestoreGross() {
            const idx = this.lines.findIndex((l) => l.ledger_id === this.tdsPayableLedgerId && l.dr_cr === 'Cr');
            if (idx < 0) return;
            const tds = this.num(this.lines[idx].amount);
            this.lines.splice(idx, 1);
            const bank = this.lines.find((l) => l.dr_cr === 'Cr' && !this.isDutyLedger(l.ledger_id));
            if (bank) bank.amount = String(Math.round((this.num(bank.amount) + tds) * 100) / 100);
            this.lineActive = 0;
        },

        currentGroupDefaults() {
            return this.invoiceGroups[this.type] || null;
        },
        stripPartyLine() {
            const pSide = this.invoicePartySide();
            const idx = this.lines.findIndex((l) => l.ledger_id === this.partyLedgerId && l.dr_cr === pSide);
            if (idx >= 0) this.lines.splice(idx, 1);
            if (!this.lines.length) this.lines = [this.mkLine({ dr_cr: this.invoiceLedgerSide() })];
            this.lineActive = 0;
        },
        stripTaxLines() {
            const taxIds = new Set(Object.values((this.gst && this.gst.tax_ledgers) || {}));
            this.lines = this.lines.filter((l) => !taxIds.has(l.ledger_id));
            if (!this.lines.length) this.lines = [this.mkLine({ dr_cr: this.invoiceLedgerSide() })];
            this.lineActive = 0;
        },
        /** Default group (id+label) to pre-fill when inline-creating from a combo. */
        quickLedgerGroupFor(comboId) {
            const g = this.currentGroupDefaults();
            if (!g || !this.showInvoice) return { id: null, label: '' };
            if (comboId === 'vparty') return { id: g.party_group_id, label: g.party_group_label || '' };
            if (/^vline-\d+$/.test(comboId || '')) return { id: g.ledger_group_id, label: g.ledger_group_label || '' };
            return { id: null, label: '' };
        },

        /* ---- lines ---- */
        mkLine(l) {
            return {
                uid: this.lineSeq++,
                dr_cr: (l && l.dr_cr) || 'Dr',
                ledger_id: (l && l.ledger_id) || null,
                ledger_label: (l && l.ledger_label) || '',
                amount: l && l.amount != null && l.amount !== '' ? String(l.amount) : '',
                // Phase 11 — foreign amount + rate (only used when the ledger is foreign).
                foreign_amount: l && l.foreign_amount != null && l.foreign_amount !== '' ? String(l.foreign_amount) : '',
                exchange_rate: l && l.exchange_rate != null && l.exchange_rate !== '' ? String(l.exchange_rate) : '',
            };
        },

        /* ---- multi-currency line helpers (Phase 11) ---- */
        // The currency a ledger transacts in (null = base). Read from the masters cache.
        lineCurrencyId(line) {
            const l = this.masterLedger(line.ledger_id);
            return l && l.currency_id ? l.currency_id : null;
        },
        lineIsForeign(line) {
            return this.forexEnabled && !!this.lineCurrencyId(line);
        },
        /* ---- Phase 12B — inter-company derivation (client mirror; server enforces) ----
           A line is inter-company when its ledger is linked to a company that is in
           the ACTIVE company's group. All data ships in bootData — zero network. */
        lineInterCompanyId(line) {
            if (!this.interCompanyEnabled) return null;
            const l = this.masterLedger(line.ledger_id);
            const linked = l && l.linked_company_id ? l.linked_company_id : null;
            return linked && this.interCompanyGroupCompanyIds.includes(linked) ? linked : null;
        },
        lineInterCompanyName(line) {
            const id = this.lineInterCompanyId(line);
            return id ? (this.interCompanyCompanyNames[id] || '#' + id) : '';
        },
        /* The voucher's single derived counterparty (null when none; the server
           rejects a voucher touching two different group companies). Mirrors the
           payload EXACTLY: in invoice mode the party ledger is not in this.lines —
           buildPayloadLines() adds it — so it is considered explicitly here; stock
           and workflow vouchers post no ledger lines, so they never carry a tag. */
        interCompanyCounterparty() {
            if (!this.interCompanyEnabled || this.isStockVoucher || this.isWorkflowType) return null;
            const ids = new Set();
            // FILLED lines only — the payload ships filledLines, and a leftover
            // zero-amount line must not make the client declare what the server
            // (deriving from the shipped lines) will refuse.
            for (const l of this.filledLines) {
                if (!l.ledger_id) continue;
                const id = this.lineInterCompanyId(l);
                if (id) ids.add(id);
            }
            if (this.showInvoice && this.partyLedgerId) {
                const id = this.lineInterCompanyId({ ledger_id: this.partyLedgerId });
                if (id) ids.add(id);
            }
            // Single-entry mode synthesizes the Account leg at payload build — it is
            // a real payload line, so it derives here too.
            if (this.single && this.accountLedgerId) {
                const id = this.lineInterCompanyId({ ledger_id: this.accountLedgerId });
                if (id) ids.add(id);
            }
            return ids.size === 1 ? [...ids][0] : null;
        },
        lineCurrencyCode(line) {
            const l = this.masterLedger(line.ledger_id);
            return (l && l.currency_code) || '';
        },
        // The recorded rate on-or-before a date for a currency — mirrors the server's
        // rateOn() exactly, so a back-dated foreign voucher gets the right historical rate.
        rateOnDate(currencyId, date) {
            const hist = this.rateHistory[currencyId] || [];
            let found = null;
            for (const [d, r] of hist) {
                if (d <= date) found = r;
                else break; // ascending — nothing later can be on-or-before
            }
            return found;
        },
        // The rate to default a fresh foreign line to — the rate on the voucher date.
        defaultRateFor(line) {
            const cid = this.lineCurrencyId(line);
            if (!cid) return '';
            const r = this.rateOnDate(cid, this.date);
            if (r != null) return r;
            const latest = this.latestRates[cid];
            return latest ? latest.rate : '';
        },
        // Ensure a foreign line has a rate defaulted the moment its ledger is picked.
        ensureLineRate(line) {
            if (this.lineIsForeign(line) && !line.exchange_rate) {
                const d = this.defaultRateFor(line);
                if (d) line.exchange_rate = String(d);
            }
        },
        // The live INR equivalent of a foreign line (foreign × rate), to the paise.
        lineInr(line) {
            if (!this.lineIsForeign(line)) return this.num(line.amount);
            return Math.round(this.num(line.foreign_amount) * this.num(line.exchange_rate) * 100) / 100;
        },
        lineInrLabel(line) {
            return this.fmt(this.lineInr(line));
        },
        // Attach the forex fields to a payload line when its ledger is foreign. The base
        // `amount` is set to the derived foreign×rate so the server's invariant holds.
        attachForex(obj, line) {
            if (this.lineIsForeign(line)) {
                obj.amount = this.lineInr(line);
                obj.currency_id = this.lineCurrencyId(line);
                obj.foreign_amount = this.num(line.foreign_amount);
                obj.exchange_rate = this.num(line.exchange_rate);
            }
            return obj;
        },
        // Does any line touch a foreign-currency ledger? (Drives the rate-override banner.)
        get hasForeignLine() {
            return this.forexEnabled && this.lines.some((l) => l.ledger_id && this.lineIsForeign(l));
        },
        // True when a foreign line's rate differs from the recorded rate for its date — the
        // client shows a warning and requires the override reason before posting.
        get forexRateWarning() {
            if (!this.hasForeignLine) return false;
            return this.lines.some((l) => {
                if (!this.lineIsForeign(l) || !this.num(l.exchange_rate)) return false;
                const cid = this.lineCurrencyId(l);
                const onDate = this.rateOnDate(cid, this.date);
                if (onDate == null) return false; // no rate on/before the date — server will flag
                // Warn only when the line's rate differs from the recorded rate FOR THE
                // VOUCHER DATE — exactly what the server checks. A settlement line (Against
                // Ref to a foreign bill) legitimately uses the bill's booked rate, so skip it.
                if (this.lineSettlesForeignBill(l)) return false;
                return Math.round(this.num(l.exchange_rate) * 1e6) !== Math.round(onDate * 1e6);
            });
        },
        // Does a line close a foreign bill via Against-Ref? (Its rate is the booked rate.)
        lineSettlesForeignBill(line) {
            const allocs = this.allocs['line:' + line.uid] || [];
            return allocs.some((a) => a.ref_type === 'against');
        },
        forexRateOverride: false,
        forexRateReason: '',
        get baseSymbol() {
            const b = (this.currencies || []).find((c) => c.id === this.baseCurrencyId);
            return (b && b.symbol) || '₹';
        },
        resetLines() {
            // Start with a single line; the balancing line is auto-added (with the
            // difference pre-filled) when the user presses Enter past the amount —
            // the Tally continuous-entry rhythm.
            this.lines = [this.mkLine({ dr_cr: this.defaultFirstSide() })];
            this.lineActive = 0;
            this.accountLedgerId = null;
            this.accountLedgerLabel = '';
            // invoice party header is part of a fresh voucher too
            this.partyLedgerId = null;
            this.partyLedgerLabel = '';
            this.referenceNo = '';
            this.referenceDate = '';
            this.referenceVoucherId = null; // Phase 8A — fresh Note has no reference
            this.referenceLabel = '';
            this.allocs = {}; // bill allocations belong to this voucher only
            this.costAllocs = {}; // cost allocations too
            this.tdsReset(); // the deduction belongs to this voucher only
            // Item-invoice AND every Phase 8B workflow voucher start with one blank
            // stock line; the item grid is their whole body.
            this.items = this.showItemInvoice || this.isWorkflowType ? [this.mkItem()] : [];
            this.setDefaultItemLedger();
            // Stock-movement voucher: a fresh blank movement.
            this.resetMovement();
        },
        defaultFirstSide() {
            // In invoice mode the ledger allocation sits on the ledger side
            // (Cr for Sales, Dr for Purchase); Receipt starts Cr; others Dr.
            if (this.showInvoice) return this.invoiceLedgerSide();
            return this.type === 'receipt' ? 'Cr' : 'Dr';
        },
        /**
         * Where a newly-opened voucher puts the cursor: the date, as in Tally.
         * Enter chains day → month → year → the first entry field (onDateEnter), so
         * the date is confirmed on the way in rather than skipped. Routed through the
         * engine's focus helper because it retries until the field is actually
         * revealed. Continuous entry after a post keeps landing in the entry grid —
         * the date carries over, so re-confirming it every voucher would only slow
         * the operator down.
         */
        focusStartField() {
            if (document.querySelector('#v-date-dd')) {
                this.$store.zb._focusInto('#v-date-dd');
                return;
            }
            this.focusFirstField();
        },
        focusFirstField() {
            if (this.isStockVoucher) {
                this.focusField('[data-col="mvitem"]');
            } else if (this.isWorkflowType) {
                // Start on the reference picker when one exists (Delivery/Receipt/
                // Rejection), else straight on the party.
                this.focusField(this.workflowHasReference ? '#v-wfref' : '[data-col="party"]');
            } else if (this.showInvoice) {
                this.focusField('[data-col="party"]');
            } else if (this.single) {
                this.focusField('[data-col="account"]');
            } else {
                this.focusLine(0, 'drcr');
            }
        },
        /**
         * The first VISIBLE element matching a selector. Every entry layout —
         * invoice, workflow, double-entry, single, stock — is in the DOM at once
         * (x-show only hides them) and they reuse the same data-col names, so a
         * bare querySelector returns whichever mode happens to come first in the
         * markup, not the mode on screen. Focusing a display:none field is a
         * silent no-op, which is what stalls the Enter chain.
         */
        visibleEl(sel) {
            return Array.from(document.querySelectorAll(sel)).find((el) => el.offsetParent !== null) || null;
        },
        focusOn(el) {
            if (!el) return;
            el.focus();
            if (el.select) {
                try {
                    el.select();
                } catch (_) {}
            }
            if (el.hasAttribute('data-zb-combo-input')) el.dispatchEvent(new CustomEvent('zb-open'));
        },
        focusField(sel) {
            this.focusOn(this.visibleEl(sel));
        },
        num(v) {
            const n = parseFloat(v);
            return isNaN(n) ? 0 : n;
        },
        fmt(n) {
            return (Math.round(n * 100) / 100).toLocaleString(undefined, {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            });
        },
        // The base (INR) amount of a line — the derived foreign×rate on a foreign line,
        // else the entered amount. Everything that balances the voucher uses this.
        lineBase(line) {
            return this.lineIsForeign(line) ? this.lineInr(line) : this.num(line.amount);
        },
        get totalDr() {
            return this.lines.reduce((s, l) => s + (l.dr_cr === 'Dr' ? this.lineBase(l) : 0), 0);
        },
        get totalCr() {
            return this.lines.reduce((s, l) => s + (l.dr_cr === 'Cr' ? this.lineBase(l) : 0), 0);
        },
        get difference() {
            return Math.round((this.totalDr - this.totalCr) * 100) / 100;
        },
        get filledLines() {
            return this.lines.filter((l) => l.ledger_id && this.lineBase(l) > 0);
        },
        get balanced() {
            if (this.isStockVoucher) {
                return this.stockBalanced;
            }
            // Phase 8B — a workflow voucher is "ready" when it has a party and at least
            // one item line with a positive quantity. No balance/ledger to satisfy.
            if (this.isWorkflowType) {
                return !!this.partyLedgerId && this.filledItems.length >= 1 && this.itemSubtotal > 0;
            }
            if (this.showItemInvoice) {
                return !!this.partyLedgerId && !!this.itemLedgerId && this.filledItems.length >= 1 && this.itemSubtotal > 0;
            }
            if (this.showInvoice) {
                return !!this.partyLedgerId && this.filledLines.length >= 1 && this.invoiceTotal > 0;
            }
            if (this.single) {
                return !!this.accountLedgerId && this.filledLines.length >= 1 && this.singleTotal > 0;
            }
            return this.difference === 0 && this.totalDr > 0 && this.filledLines.length >= 2;
        },

        /* ---- single-entry mode (F12) ---- */
        get single() {
            // Single-entry is for Payment/Receipt/Contra only — never Journal, and
            // never Sales/Purchase (those use the As-Invoice/As-Voucher toggle).
            return (
                this.$store.config &&
                this.$store.config.get('singleEntry') &&
                this.type !== 'journal' &&
                !this.isInvoiceType &&
                !this.isStockVoucher &&
                !this.isWorkflowType
            );
        },
        accountSide() {
            // Payment & Contra credit the account (money out); Receipt debits it.
            return this.type === 'receipt' ? 'Dr' : 'Cr';
        },
        partSide() {
            return this.accountSide() === 'Dr' ? 'Cr' : 'Dr';
        },
        get singleTotal() {
            return this.filledLines.reduce((s, l) => s + this.num(l.amount), 0);
        },
        accountLabel() {
            if (this.type === 'receipt') return 'Account (received to)';
            if (this.type === 'contra') return 'Account (transferred from)';
            return 'Account (paid from)';
        },
        balFor(id) {
            const b = id != null ? this.balances[id] : null;
            return b && b.side ? b.side + ' ' + b.amount : b ? b.amount : '';
        },
        addBlankLine() {
            this.lines.push(this.mkLine({ dr_cr: this.partSide() }));
            return this.lines.length - 1;
        },

        // Per-ledger flags (independent of the F11 feature) so a stale allocation is
        // never re-sent for a ledger that is no longer bill-wise / cost-applicable.
        ledgerBillFlag(id) {
            const l = this.masterLedger(id);
            return !!(l && l.maintain_bill_by_bill);
        },
        ledgerCostFlag(id) {
            const l = this.masterLedger(id);
            return !!(l && l.cost_centres_applicable);
        },
        /** Attach any stored bill + cost allocations for a target key onto a payload line. */
        attachAllocs(obj, key) {
            const a = this.allocs[key];
            if (a && a.length && this.ledgerBillFlag(obj.ledger_id)) obj.allocations = a;
            return this.attachCostAllocs(obj, key);
        },
        /** The stock movement sent alongside the ledger lines (Phase 6B). Carries
         *  only the SELLING rate + qty; the server derives direction and computes
         *  the weighted-average COST. */
        buildItemsPayload() {
            return this.filledItems.map((it) => ({
                stock_item_id: it.stock_item_id,
                godown_id: it.godown_id || null,
                qty: this.num(it.qty),
                rate: this.num(it.rate),
            }));
        },
        /** Build the balanced double-entry rows sent to the server. */
        buildPayloadLines() {
            const r2 = (n) => Math.round(n * 100) / 100;
            if (this.showItemInvoice) {
                // Item invoice → the same balanced double-entry an accounting invoice
                // produces, but the single revenue leg = Σ item (qty × selling-rate)
                // and the tax is computed from each item's own rate. The `items`
                // array (sent separately) drives the stock ledger + COST server-side.
                const pSide = this.invoicePartySide();
                const lSide = this.invoiceLedgerSide();
                const revLine = this.attachAllocs({ ledger_id: this.itemLedgerId, dr_cr: lSide, amount: r2(this.itemSubtotal) }, 'itemledger');
                const taxParts = this.taxComputation.taxLines.map((t) => ({ ledger_id: t.ledger_id, dr_cr: t.dr_cr, amount: t.amount }));
                const total = (Math.round(this.itemSubtotal * 100) + this.taxComputation.taxTotalPaise) / 100;
                return [this.attachAllocs({ ledger_id: this.partyLedgerId, dr_cr: pSide, amount: r2(total) }, 'party'), revLine, ...taxParts];
            }
            if (this.showInvoice) {
                // Invoice mode → derive the balanced double-entry:
                //   Sales:    Dr Party (incl tax) · Cr Sales line(s) · Cr Output CGST/SGST or IGST
                //   Purchase: Dr Purchase line(s) · Dr Input CGST/SGST or IGST · Cr Party (incl tax)
                // The party leg = taxable + tax to the paise, so the voucher balances;
                // the server independently recomputes and verifies the tax lines.
                const pSide = this.invoicePartySide();
                const lSide = this.invoiceLedgerSide();
                const parts = this.filledLines.map((l) => this.attachAllocs({ ledger_id: l.ledger_id, dr_cr: lSide, amount: r2(this.num(l.amount)) }, 'line:' + l.uid));
                const taxParts = this.taxComputation.taxLines.map((t) => ({ ledger_id: t.ledger_id, dr_cr: t.dr_cr, amount: t.amount }));
                const taxableP = parts.reduce((s, p) => s + Math.round(p.amount * 100), 0);
                const taxP = taxParts.reduce((s, p) => s + Math.round(p.amount * 100), 0);
                const total = (taxableP + taxP) / 100;
                return [this.attachAllocs({ ledger_id: this.partyLedgerId, dr_cr: pSide, amount: total }, 'party'), ...parts, ...taxParts];
            }
            if (this.single) {
                const aSide = this.accountSide();
                const pSide = aSide === 'Dr' ? 'Cr' : 'Dr';
                // Round each particular to paise, then make the account leg the exact
                // sum of those rounded values so the two sides balance to the paise.
                const parts = this.filledLines.map((l) => this.attachForex(this.attachAllocs({ ledger_id: l.ledger_id, dr_cr: pSide, amount: r2(this.lineBase(l)) }, 'line:' + l.uid), l));
                const total = r2(parts.reduce((s, p) => s + p.amount, 0));
                // TDS (Phase 10A): the account leg gives up exactly what the TDS Payable
                // line takes, so the voucher stays balanced and the vendor is short-paid
                // by the deduction. A no-op unless a deduction is engaged and non-zero.
                return this.applyTdsToLines([this.attachAllocs({ ledger_id: this.accountLedgerId, dr_cr: aSide, amount: total }, 'account'), ...parts]);
            }
            return this.applyTdsToLines(
                this.filledLines.map((l) => this.attachForex(this.attachAllocs({ ledger_id: l.ledger_id, dr_cr: l.dr_cr, amount: r2(this.lineBase(l)) }, 'line:' + l.uid), l)),
            );
        },

        insertLine() {
            // In item mode (and every Phase 8B workflow voucher) Alt+I adds a stock
            // line, not a ledger line.
            if (this.showItemInvoice || this.isWorkflowType) {
                this.addItem();
                return;
            }
            // In single-entry and invoice modes there is no Dr/Cr difference to
            // balance, so add a plain blank particulars line rather than a
            // pre-filled balancing line.
            const i = this.single || this.showInvoice ? this.addBlankLine() : this.addBalancingLine();
            this.$nextTick(() => this.focusLine(i, 'ledger'));
        },
        addBalancingLine() {
            const diff = this.difference; // Dr - Cr
            const side = diff > 0 ? 'Cr' : 'Dr';
            const amt = Math.abs(diff);
            this.lines.push(this.mkLine({ dr_cr: side, amount: amt > 0 ? amt : '' }));
            return this.lines.length - 1;
        },
        removeLine(i) {
            if (this.lines.length <= 1) {
                this.flashMsg('A voucher needs at least one line', 'warn');
                return;
            }
            this.lines.splice(i, 1);
            if (this.lineActive >= this.lines.length) this.lineActive = this.lines.length - 1;
        },
        removeCurrentLine() {
            const el = document.activeElement;
            // In item mode (and every Phase 8B workflow voucher) Alt+R removes the
            // focused stock line, not a ledger line.
            if ((this.showItemInvoice || this.isWorkflowType) && el && el.dataset && el.dataset.item != null) {
                this.removeCurrentItem();
                return;
            }
            const i = el && el.dataset && el.dataset.line != null ? parseInt(el.dataset.line, 10) : this.lineActive;
            this.removeLine(i);
            this.$nextTick(() => this.focusLine(Math.min(i, this.lines.length - 1), 'ledger'));
        },

        /* ---- focus / flow ---- */
        focusLine(i, col) {
            this.focusField('[data-line="' + i + '"][data-col="' + col + '"]');
        },
        focusNarration() {
            const el = document.querySelector('#v-narration');
            if (el) el.focus();
        },
        onEnter(e) {
            const el = e.target;
            // The header date runs its own segment chain before the form's fields.
            const part = el && el.dataset ? el.dataset.datepart : null;
            if (part) {
                this.onDateEnter(part);
                return;
            }
            const col = el && el.dataset ? el.dataset.col : null;
            // Item-invoice stock rows carry data-item and flow through their own
            // Enter rhythm (item → godown → qty → rate → next row).
            if (el && el.dataset && el.dataset.item != null) {
                this.onItemEnter(e);
                return;
            }
            const i = el && el.dataset && el.dataset.line != null ? parseInt(el.dataset.line, 10) : null;
            if (col === 'itemledger') {
                this.focusNarration();
                return;
            }
            if (col === 'account') {
                this.focusLine(0, 'ledger');
                return;
            }
            if (col === 'drcr' && i != null) {
                this.focusLine(i, 'ledger');
                return;
            }
            if (col === 'amount' && i != null) {
                if (i === this.lines.length - 1) {
                    if (this.single || this.showInvoice) {
                        const line = this.lines[i];
                        if (line.ledger_id && this.num(line.amount) > 0) {
                            const ni = this.addBlankLine();
                            this.$nextTick(() => this.focusLine(ni, 'ledger'));
                        } else {
                            this.focusNarration();
                        }
                    } else if (this.difference !== 0) {
                        const ni = this.addBalancingLine();
                        this.$nextTick(() => this.focusLine(ni, 'ledger'));
                    } else {
                        this.focusNarration();
                    }
                } else {
                    this.focusLine(i + 1, 'ledger');
                }
                return;
            }
            if (col === 'narration') {
                this.accept();
                return;
            }
            this.$store.zb.fieldAdvance(e);
        },
        /**
         * Backspace — onEnter walked backwards, step for step, so the two are exact
         * opposites at every stop on the chain. Reached only once the focused field
         * has nothing left to erase (see the context's yieldWhen), so this never
         * costs the user a character.
         */
        onBackspace(e) {
            const el = e.target;
            // The header date runs its own segment chain: year → month → day.
            const part = el && el.dataset ? el.dataset.datepart : null;
            if (part) {
                const prev = this.datePrev(part);
                if (prev) this.focusDatePart(prev);
                return;
            }
            const col = el && el.dataset ? el.dataset.col : null;
            if (el && el.dataset && el.dataset.item != null) {
                this.onItemBack(e);
                return;
            }
            const i = el && el.dataset && el.dataset.line != null ? parseInt(el.dataset.line, 10) : null;
            // Narration sits after the last line, so it steps back onto its amount.
            if (col === 'narration') {
                const last = this.lines.length - 1;
                if (this.showItemInvoice) this.focusField('[data-col="itemledger"]');
                else if (last >= 0) this.focusLine(last, 'amount');
                else this.focusFirstField();
                return;
            }
            if (col === 'itemledger') {
                this.focusLastItemField();
                return;
            }
            if (col === 'amount' && i != null) {
                this.focusLine(i, 'ledger');
                return;
            }
            if (col === 'ledger' && i != null) {
                // Single-entry and invoice rows have no Dr/Cr cell to step onto.
                if (this.single || this.showInvoice) {
                    if (i > 0) this.focusLine(i - 1, 'amount');
                    else this.focusFieldBeforeLines();
                    return;
                }
                this.focusLine(i, 'drcr');
                return;
            }
            if (col === 'drcr' && i != null) {
                if (i > 0) this.focusLine(i - 1, 'amount');
                else this.focusDateEnd();
                return;
            }
            if (col === 'account') {
                this.focusDateEnd();
                return;
            }
            // Anything else (party, reference, workflow header) walks the form's own
            // field order; falling off its start lands back on the header date.
            if (!this.$store.zb.fieldRetreat(e)) this.focusDateEnd();
        },
        /** The last field of the header date — where the entry grid steps back to. */
        focusDateEnd() {
            this.focusDatePart('yyyy');
        },
        /** The field the first entry line steps back onto in invoice/single layouts. */
        focusFieldBeforeLines() {
            if (this.showInvoice && this.visibleEl('[data-col="party"]')) {
                this.focusField('[data-col="party"]');
                return;
            }
            if (this.single && this.visibleEl('[data-col="account"]')) {
                this.focusField('[data-col="account"]');
                return;
            }
            this.focusDateEnd();
        },
        /** Last field of the last item row — where the item-invoice ledger steps back to. */
        focusLastItemField() {
            const rows = Array.from(document.querySelectorAll('[data-col="rate"][data-item]')).filter(
                (el) => el.offsetParent !== null
            );
            if (rows.length) this.focusOn(rows[rows.length - 1]);
            else this.focusFirstField();
        },
        lineMove(d) {
            const el = document.activeElement;
            // ↑/↓ in the header date bumps that segment rather than jumping to a line.
            const part = el && el.dataset ? el.dataset.datepart : null;
            if (part) {
                this.dateStep(part, -d);
                return;
            }
            let col = el && el.dataset ? el.dataset.col : 'ledger';
            if (!col || col === 'drcr') col = 'ledger';
            const cur = el && el.dataset && el.dataset.line != null ? parseInt(el.dataset.line, 10) : this.lineActive;
            const ni = Math.max(0, Math.min(this.lines.length - 1, cur + d));
            this.lineActive = ni;
            this.focusLine(ni, col);
        },

        /** Pop any open ledger-picker contexts sitting on top of the stack. */
        closePickers() {
            let guard = 0;
            while (this.$store.zb.activeName().startsWith('combo:') && guard++ < 8) {
                this.$store.zb.popContext();
            }
        },

        /* ---- type switch (F4–F7) ---- */
        switchType(t) {
            if (!this.typeKeys.includes(t)) return;
            if (t === this.type) return;
            if (this.editId) {
                this.flashMsg('Voucher type is fixed while altering', 'warn');
                return;
            }
            // Switching type calls resetLines() and clears the narration, so on a
            // voucher that has been typed into it is a discard — and it used to
            // happen silently. A mistyped F8 on a half-entered Payment threw the
            // work away with no prompt.
            if (this.hasUnsavedWork()) {
                this.confirmDiscard('Switching to ' + (this.types[t] ? this.types[t].label : t), () =>
                    this.applyTypeSwitch(t)
                );

                return;
            }
            this.applyTypeSwitch(t);
        },

        /** The type switch itself, once any discard has been agreed to. */
        applyTypeSwitch(t) {
            this.closePickers(); // a line's picker may be open; don't orphan its context
            this.type = t;
            this.number = this.nextNumbers[t];
            this.narration = '';
            // Sales/Purchase adopt invoice mode by default (Tally's default entry),
            // and item mode when the company keeps stock.
            this.invoiceMode = this.invoiceTypes.includes(t);
            this.itemMode = this.stockEnabled && this.invoiceTypes.includes(t);
            this.resetLines();
            this.updateContextLabel();
            const kind = this.showInvoice ? 'invoice' : 'voucher';
            this.flashMsg(this.typeLabel() + ' ' + kind + ' (' + this.types[t].key + ')', 'ok');
            this.$nextTick(() => this.focusFirstField());
        },

        /* ---- As-Invoice / As-Voucher toggle (Ctrl+V) ---- */
        toggleInvoiceMode() {
            if (!this.isInvoiceType) {
                this.flashMsg('As Invoice / As Voucher applies to Sales (F8) and Purchase (F9)', 'warn');
                return;
            }
            if (this.editId) {
                this.flashMsg('Entry mode is fixed while altering a saved voucher', 'warn');
                return;
            }
            this.closePickers();
            // Item lines can't be represented as raw double-entry rows, so As-Voucher
            // from an item invoice first steps down to the accounting invoice (Ctrl+I)
            // — losslessly — rather than silently discarding the stock lines.
            if (this.showItemInvoice) {
                this.toggleItemMode();
                return;
            }
            if (this.invoiceMode) {
                // Invoice → Voucher: expand the derived legs into explicit lines so
                // nothing entered is lost.
                this.convertInvoiceToVoucher();
                this.invoiceMode = false;
            } else {
                // Voucher → Invoice: land in the accounting invoice (converting raw
                // voucher lines into item lines is not possible); Ctrl+I for items.
                this.invoiceMode = true;
                this.itemMode = false;
                this.convertVoucherToInvoice();
            }
            this.updateContextLabel();
            this.flashMsg(this.showInvoice ? 'As Invoice (Ctrl+V to switch)' : 'As Voucher (Ctrl+V to switch)', 'ok');
            this.$nextTick(() => this.focusFirstField());
        },
        convertInvoiceToVoucher() {
            // Build explicit lines: party leg first, then the allocation lines on
            // the ledger side. Keeps whatever the user has entered so far.
            const lSide = this.invoiceLedgerSide();
            const pSide = this.invoicePartySide();
            const alloc = this.filledLines.map((l) => this.mkLine({ dr_cr: lSide, ledger_id: l.ledger_id, ledger_label: l.ledger_label, amount: l.amount }));
            const out = [];
            if (this.partyLedgerId) {
                out.push(this.mkLine({ dr_cr: pSide, ledger_id: this.partyLedgerId, ledger_label: this.partyLedgerLabel, amount: this.invoiceTotal > 0 ? this.invoiceTotal : '' }));
            }
            this.lines = out.concat(alloc.length ? alloc : [this.mkLine({ dr_cr: lSide })]);
            this.lineActive = 0;
        },
        convertVoucherToInvoice() {
            // Find a single party-side line to lift into the invoice header.
            const pSide = this.invoicePartySide();
            const partyIdx = this.lines.findIndex((l) => l.dr_cr === pSide && l.ledger_id);
            if (partyIdx >= 0) {
                const p = this.lines[partyIdx];
                this.partyLedgerId = p.ledger_id;
                this.partyLedgerLabel = p.ledger_label;
                this.lines.splice(partyIdx, 1);
            }
            if (!this.lines.length) this.lines = [this.mkLine({ dr_cr: this.invoiceLedgerSide() })];
            this.lineActive = 0;
        },
        /* ---- Item / Accounting invoice toggle (Ctrl+I) ---- */
        toggleItemMode() {
            if (!this.showInvoice) {
                this.flashMsg('Item / Accounting invoice applies to Sales (F8) and Purchase (F9)', 'warn');
                return;
            }
            if (!this.stockEnabled) {
                this.flashMsg('Create a Stock Item first to enter an item invoice', 'warn');
                return;
            }
            if (this.editId) {
                this.flashMsg('Entry mode is fixed while altering a saved voucher', 'warn');
                return;
            }
            this.closePickers();
            this.itemMode = !this.itemMode;
            if (this.itemMode && !this.items.length) this.items = [this.mkItem()];
            this.setDefaultItemLedger();
            this.updateContextLabel();
            this.flashMsg(this.showItemInvoice ? 'Item Invoice (Ctrl+I to switch)' : 'Accounting Invoice (Ctrl+I to switch)', 'ok');
            this.$nextTick(() => this.focusFirstField());
        },
        /* ---- item rows ---- */
        addItem() {
            this.items.push(this.mkItem());
            const ni = this.items.length - 1;
            this.$nextTick(() => this.focusItem(ni, 'item'));
            return ni;
        },
        removeItem(i) {
            if (this.items.length <= 1) {
                this.flashMsg('An item invoice needs at least one stock line', 'warn');
                return;
            }
            this.items.splice(i, 1);
        },
        removeCurrentItem() {
            const el = document.activeElement;
            const i = el && el.dataset && el.dataset.item != null ? parseInt(el.dataset.item, 10) : this.items.length - 1;
            this.removeItem(i);
        },
        focusItem(i, col) {
            this.focusField('[data-item="' + i + '"][data-col="' + col + '"]');
        },
        onItemEnter(e) {
            // Enter flow within an item row: item → godown → qty → rate → (next row
            // or the ledger/narration). Mirrors the ledger-line rhythm.
            const el = e.target;
            const col = el && el.dataset ? el.dataset.col : null;
            const i = el && el.dataset && el.dataset.item != null ? parseInt(el.dataset.item, 10) : null;
            if (i == null) {
                this.$store.zb.fieldAdvance(e);
                return;
            }
            if (col === 'item') return this.focusItem(i, 'godown');
            if (col === 'godown') return this.focusItem(i, 'qty');
            if (col === 'qty') return this.focusItem(i, 'rate');
            if (col === 'rate') {
                const it = this.items[i];
                if (i === this.items.length - 1) {
                    if (it.stock_item_id && this.num(it.qty) > 0) {
                        this.addItem();
                    } else if (this.isWorkflowType) {
                        // Workflow vouchers have no revenue ledger — jump to narration.
                        this.focusNarration();
                    } else {
                        this.focusItemLedger();
                    }
                } else {
                    this.focusItem(i + 1, 'item');
                }
                return;
            }
            this.$store.zb.fieldAdvance(e);
        },
        focusItemLedger() {
            this.focusField('[data-col="itemledger"]');
        },
        printCurrent() {
            const id = this.editId || this.lastPostedId;
            if (!id) {
                this.flashMsg('Accept the voucher first (Ctrl+A), then Alt+P to print', 'warn');
                return;
            }
            const url = (cfg.printUrl || '').replace('__id__', id);
            if (url) window.open(url, '_blank', 'noopener');
        },

        /* ---- picker events ---- */
        onComboPick(detail) {
            if (detail.comboId === 'vacct') {
                this.accountLedgerId = detail.id;
                this.accountLedgerLabel = detail.label;
                return;
            }
            if (detail.comboId === 'vparty') {
                this.partyLedgerId = detail.id;
                this.partyLedgerLabel = detail.label;
                return;
            }
            if (detail.comboId === 'vitemledger') {
                this.itemLedgerId = detail.id;
                this.itemLedgerLabel = detail.label;
                return;
            }
            // Phase 10A — the TDS panel. Naming the deductee defaults its usual section
            // and (unless the user opted out in F12) engages the deduction immediately.
            if (detail.comboId === 'vtdsdeductee') {
                this.tdsDeducteeId = detail.id;
                this.tdsDeducteeLabel = detail.label;
                this.tdsOnDeducteePicked();
                return;
            }
            if (detail.comboId === 'vtdssection') {
                this.tdsSectionId = detail.id;
                this.tdsSectionLabel = detail.label;
                this.updateContextLabel();
                return;
            }
            // Phase 6C stock-movement pickers.
            if (detail.comboId === 'mvitem') {
                this.mv.item_id = detail.id;
                this.mv.item_label = detail.label;
                this.refreshBookQty();
                return;
            }
            if (detail.comboId === 'mvgod') {
                this.mv.godown_id = detail.id;
                this.mv.godown_label = detail.label;
                this.refreshBookQty();
                return;
            }
            if (detail.comboId === 'mvfrom') {
                this.mv.from_godown_id = detail.id;
                this.mv.from_godown_label = detail.label;
                return;
            }
            if (detail.comboId === 'mvto') {
                this.mv.to_godown_id = detail.id;
                this.mv.to_godown_label = detail.label;
                return;
            }
            const im = /^istem-(\d+)$/.exec(detail.comboId || '');
            if (im) {
                const it = this.items.find((x) => x.uid === parseInt(im[1], 10));
                if (it) {
                    it.stock_item_id = detail.id;
                    it.stock_item_label = detail.label;
                }
                return;
            }
            const gm = /^igod-(\d+)$/.exec(detail.comboId || '');
            if (gm) {
                const it = this.items.find((x) => x.uid === parseInt(gm[1], 10));
                if (it) {
                    it.godown_id = detail.id;
                    it.godown_label = detail.label;
                }
                return;
            }
            const cm = /^costrow-(\d+)$/.exec(detail.comboId || '');
            if (cm) {
                const uid = parseInt(cm[1], 10);
                const row = this.costRows.find((r) => r._uid === uid);
                if (row) {
                    row.cost_centre_id = detail.id;
                    row.cost_centre_label = detail.label;
                }
                return;
            }
            const m = /^vline-(\d+)$/.exec(detail.comboId || '');
            if (!m) return;
            const uid = parseInt(m[1], 10);
            const line = this.lines.find((l) => l.uid === uid);
            if (line) {
                line.ledger_id = detail.id;
                line.ledger_label = detail.label;
                // Phase 11 — default the rate the moment a foreign-currency ledger is picked.
                this.ensureLineRate(line);
            }
        },
        onComboCreate(detail) {
            if (detail.createType === 'ledger') this.openQuickLedger(detail);
            else if (detail.createType === 'group') this.openQuickGroup(detail);
            else if (detail.createType === 'stockItem') this.openQuickStockItem(detail);
        },

        /* ---- inline quick-create ledger ---- */
        openQuickLedger(detail) {
            this.pendingLedgerCombo = detail.comboId;
            this.$wire.set('ql_name', detail.name || '', false);
            // In invoice mode, pre-select the natural group for the field being
            // created: the party under Sundry Debtors/Creditors, a ledger
            // allocation under Sales/Purchase Accounts. The user can still change it.
            const g = this.quickLedgerGroupFor(detail.comboId);
            this.$wire.set('ql_group_id', g.id, false);
            this.$wire.set('ql_group_label', g.label, false);
            this.$wire.set('ql_opening', '', false);
            // reset the F11-gated GST fields on the inline-create form
            this.$wire.set('ql_gst_rate', '', false);
            this.$wire.set('ql_state', '', false);
            this.$wire.set('ql_gstin', '', false);
            this.showQuickLedger = true;
            this.$store.zb.pushContext({
                name: 'quick-ledger',
                label: 'Create Ledger (inline)',
                focusEl: '#qled-name',
                actions: [],
                onPop: () => (this.showQuickLedger = false),
            });
        },
        saveQuickLedger() {
            const name = (this.$wire.get('ql_name') || '').trim();
            this.$store.zb.askAccept({
                title: 'Create Ledger (inline)',
                body: name ? 'Accept “' + name + '”?' : 'Accept?',
                onYes: () => this.commitQuickLedger(),
            });
        },
        async commitQuickLedger() {
            try {
                const comboId = this.pendingLedgerCombo;
                const rec = await this.$wire.saveQuickLedger();
                if (!rec) return;
                this.$store.masters.addLedger(rec);
                this.$store.zb.popToContext('quick-ledger');
                this.$store.zb.emit('zb:combo-fill', { comboId, item: rec });
                this.$store.zb.note('Created ledger inline: ' + rec.name, 'commit');
                this.pendingLedgerCombo = null;
            } catch (e) {
                /* inline errors */
            }
        },

        /* ---- inline quick-create stock item (Alt+C on the Stock Item picker) ---- */
        openQuickStockItem(detail) {
            this.pendingStockItemCombo = detail.comboId;
            this.$wire.set('qsi_name', detail.name || '', false);
            this.$wire.set('qsi_group_id', null, false);
            this.$wire.set('qsi_group_label', '', false);
            this.$wire.set('qsi_unit_id', null, false);
            this.$wire.set('qsi_unit_label', '', false);
            this.$wire.set('qsi_gst_rate', '', false);
            this.$wire.set('qsi_hsn', '', false);
            this.showQuickStockItem = true;
            this.$store.zb.pushContext({
                name: 'quick-stock-item',
                label: 'Create Stock Item (inline)',
                focusEl: '#qsi-name',
                actions: [],
                onPop: () => (this.showQuickStockItem = false),
            });
        },
        async saveQuickStockItem() {
            try {
                const comboId = this.pendingStockItemCombo;
                const rec = await this.$wire.saveQuickStockItem();
                if (!rec) return;
                this.$store.masters.addTo('stockItems', rec);
                this.$store.zb.popToContext('quick-stock-item');
                this.$store.zb.emit('zb:combo-fill', { comboId, item: rec });
                this.$store.zb.note('Created stock item inline: ' + rec.name, 'commit');
                this.pendingStockItemCombo = null;
            } catch (e) {
                /* inline errors */
            }
        },

        /* ---- inline quick-create group (from the quick-ledger Under picker) ---- */
        openQuickGroup(detail) {
            this.pendingGroupCombo = detail.comboId;
            this.$wire.set('qg_name', detail.name || '', false);
            this.$wire.set('qg_parent_id', null, false);
            this.$wire.set('qg_parent_label', '', false);
            this.$wire.set('qg_nature', 'Assets', false);
            this.showQuickGroup = true;
            this.$store.zb.pushContext({
                name: 'quick-group',
                label: 'Create Group (inline)',
                focusEl: '#qg-name',
                actions: [],
                onPop: () => (this.showQuickGroup = false),
            });
        },
        saveQuickGroup() {
            const name = (this.$wire.get('qg_name') || '').trim();
            this.$store.zb.askAccept({
                title: 'Create Group (inline)',
                body: name ? 'Accept “' + name + '”?' : 'Accept?',
                onYes: () => this.commitQuickGroup(),
            });
        },
        async commitQuickGroup() {
            try {
                const comboId = this.pendingGroupCombo;
                const rec = await this.$wire.saveQuickGroup();
                if (!rec) return;
                this.$store.masters.addGroup(rec);
                this.$store.zb.popToContext('quick-group');
                this.$store.zb.emit('zb:combo-fill', { comboId, item: rec });
                this.pendingGroupCombo = null;
            } catch (e) {
                /* inline errors */
            }
        },

        /* ---- date (F2) --------------------------------------------------
         * `date` stays the ISO yyyy-mm-dd the server wants; `dp` is what the
         * user types, one segment at a time, in Tally's DD-MM-YYYY order. The
         * two only meet through partsToIso(), so a half-typed segment can never
         * put a junk date on the voucher.
         */
        changeDate() {
            this.focusDatePart('dd');
        },
        syncDateParts() {
            const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(this.date || '');
            this.dp = m ? { dd: m[3], mm: m[2], yyyy: m[1] } : { dd: '', mm: '', yyyy: '' };
        },
        /** The typed segments as yyyy-mm-dd, or null while they aren't a real date. */
        partsToIso() {
            const d = parseInt(this.dp.dd, 10);
            const m = parseInt(this.dp.mm, 10);
            const y = parseInt(this.dp.yyyy, 10);
            if (!(d >= 1 && d <= 31) || !(m >= 1 && m <= 12) || !(y >= 1000 && y <= 9999)) return null;
            const iso =
                String(y).padStart(4, '0') + '-' + String(m).padStart(2, '0') + '-' + String(d).padStart(2, '0');
            // Reject a date the calendar doesn't have (31-02, 31-04, a non-leap 29-02):
            // Date rolls those forward silently, so compare the parts back.
            const dt = new Date(iso + 'T00:00:00');
            if (dt.getFullYear() !== y || dt.getMonth() + 1 !== m || dt.getDate() !== d) return null;
            return iso;
        },
        get dateOk() {
            return this.partsToIso() !== null;
        },
        pushDate() {
            const iso = this.partsToIso();
            if (iso && iso !== this.date) this.date = iso;
        },
        datePrev(p) {
            return p === 'yyyy' ? 'mm' : p === 'mm' ? 'dd' : null;
        },
        dateNextPart(p) {
            return p === 'dd' ? 'mm' : p === 'mm' ? 'yyyy' : null;
        },
        focusDatePart(part) {
            const el = document.querySelector('#v-date-' + part);
            if (!el) return;
            el.focus();
            try {
                el.select();
            } catch (_) {}
        },
        onDatePartInput(part, e) {
            const raw = (e.target.value || '').replace(/\D/g, '').slice(0, part === 'yyyy' ? 4 : 2);
            this.dp[part] = raw;
            e.target.value = raw; // keep the DOM in step when a non-digit was stripped
            this.pushDate();
            // Auto-advance the moment the segment can't take another digit — a day
            // over 3 or a month over 1 has nowhere left to go, as in Tally.
            if (part === 'dd' && (raw.length === 2 || parseInt(raw, 10) > 3)) this.focusDatePart('mm');
            else if (part === 'mm' && (raw.length === 2 || parseInt(raw, 10) > 1)) this.focusDatePart('yyyy');
        },
        onDateBackspace(part, e) {
            if (e.target.value !== '') return; // let the native delete happen
            const prev = this.datePrev(part);
            if (!prev) return;
            e.preventDefault();
            this.focusDatePart(prev);
        },
        onDateArrow(part, dir, e) {
            const el = e.target;
            const at = dir < 0 ? 0 : (el.value || '').length;
            if (el.selectionStart !== at || el.selectionEnd !== at) return; // still moving the caret
            const to = dir < 0 ? this.datePrev(part) : this.dateNextPart(part);
            if (!to) return;
            e.preventDefault();
            this.focusDatePart(to);
        },
        /** ↑/↓ bumps the focused segment (Tally, and the native date field, both do). */
        dateStep(part, delta) {
            if (!/^\d+$/.test(this.dp[part])) this.syncDateParts();
            const cur = parseInt(this.dp[part], 10);
            if (isNaN(cur)) return;
            const lim = { dd: [1, 31], mm: [1, 12], yyyy: [1000, 9999] }[part];
            let v = cur + delta;
            if (v < lim[0]) v = lim[1];
            if (v > lim[1]) v = lim[0];
            this.dp[part] = String(v).padStart(part === 'yyyy' ? 4 : 2, '0');
            this.pushDate();
            this.$nextTick(() => this.focusDatePart(part));
        },
        /** Leaving the control pads what's there, or snaps back to the last good date. */
        onDateBlur() {
            this.$nextTick(() => {
                const el = document.activeElement;
                if (el && el.closest && el.closest('[data-zb-datefield]')) return; // still inside
                const iso = this.partsToIso();
                if (iso) this.date = iso;
                this.syncDateParts();
            });
        },
        /** Enter inside the date: day → month → year → first field of the entry form. */
        onDateEnter(part) {
            const next = this.dateNextPart(part);
            if (next) {
                this.focusDatePart(next);
                return;
            }
            const iso = this.partsToIso();
            if (!iso) {
                this.flashMsg('Enter a valid date (DD-MM-YYYY)', 'warn');
                this.focusDatePart('dd');
                return;
            }
            this.date = iso;
            this.syncDateParts();
            this.focusFirstField();
        },

        /* ---- accept / post ---- */
        async accept() {
            if (this.accepting) return;
            if (!this.balanced) {
                let msg;
                if (this.isStockVoucher) {
                    if (!this.mv.item_id) msg = 'Choose the stock item';
                    else if (this.showStockJournal && this.sjIsTransfer && (!this.mv.from_godown_id || !this.mv.to_godown_id)) msg = 'Choose source and destination godowns';
                    else if (this.showStockJournal && this.sjIsTransfer && this.mv.from_godown_id === this.mv.to_godown_id) msg = 'Source and destination godowns must differ';
                    else if (this.showPhysicalStock) msg = 'Enter the counted quantity';
                    else msg = 'Enter a quantity greater than zero';
                    this.flashMsg(msg, 'warn');
                    return;
                }
                if (this.isWorkflowType) {
                    msg = !this.partyLedgerId
                        ? 'Choose the party A/c name'
                        : 'Enter at least one item with a quantity';
                    this.flashMsg(msg, 'warn');
                    return;
                }
                if (this.showInvoice) {
                    msg = !this.partyLedgerId
                        ? 'Choose the party A/c name'
                        : 'Enter at least one ' + this.invoiceLedgerLabel().toLowerCase() + ' with an amount';
                } else if (this.single) {
                    msg = !this.accountLedgerId
                        ? 'Choose the ' + this.accountLabel().toLowerCase()
                        : 'Enter at least one ledger with an amount';
                } else {
                    msg =
                        this.difference !== 0
                            ? 'Out of balance by ' + this.fmt(Math.abs(this.difference))
                            : 'Enter at least two lines with ledgers and amounts';
                }
                this.flashMsg(msg, 'warn');
                return;
            }
            // Bill-wise: before posting, ensure every bill-wise target is fully
            // allocated. Open the sub-screen for the first that isn't; on its accept
            // this method is re-entered to handle the next target, then posts.
            if (this.billEnabled) {
                const targets = this.billTargets();
                for (let i = 0; i < targets.length; i++) {
                    if (!this.isTargetAllocated(targets[i])) {
                        this.openBillAlloc(targets[i]);
                        return;
                    }
                }
            }
            // Cost centres: after any bill allocation, ensure each cost-applicable
            // line is fully allocated to cost centres before posting.
            if (this.costEnabled) {
                const ctargets = this.costTargets();
                for (let i = 0; i < ctargets.length; i++) {
                    if (!this.isCostTargetAllocated(ctargets[i])) {
                        this.openCostAlloc(ctargets[i]);
                        return;
                    }
                }
            }
            // TDS (Phase 10A): the deduction is taken out of the bank/cash leg, so there
            // must be exactly one credit line that is not a duty ledger. Two bank legs and
            // we cannot know which to short-pay; none and there is nothing to deduct from.
            if (this.tdsEngaged && this.tdsDeductedPaise > 0) {
                const legs = this.tdsBankLegs(this.single
                    ? [{ ledger_id: this.accountLedgerId, dr_cr: 'Cr' }]
                    : this.filledLines);
                if (legs.length !== 1) {
                    this.flashMsg('A TDS deduction needs exactly one bank/cash credit line to deduct from', 'warn');
                    return;
                }
            }
            this.accepting = true;
            const payload = {
                type: this.type,
                date: this.date,
                narration: this.narration || null,
                voucher_id: this.editId,
                // Phase 15C — provisional tag. Honoured server-side only when the scenarios
                // feature is on + the id is a scenario of this company; else the voucher stays real.
                scenario_id: this.scenariosEnabled && this.scenarioId ? this.scenarioId : null,
                // The client declares WHO, under WHICH section, and on WHAT base. It never
                // sends the deducted amount — TdsService derives that from the section rate
                // and the deductee's threshold state, and rejects the voucher if the TDS
                // Payable line it posted disagrees by a single paisa.
                tds_deduction: this.tdsEngaged
                    ? { deductee_ledger_id: this.tdsDeducteeId, tds_section_id: this.tdsSectionId, base_amount: this.tdsBasePaise / 100 }
                    : null,
                // Phase 10B — the bank challan on a remittance (Dr TDS Payable). The server
                // re-derives the deposited amount from the Dr line; only the identity rides here.
                tds_challan: this.challanPayload(),
                // Phase 11 — a forex rate override (a contract rate ≠ the recorded market
                // rate) needs an explicit confirmation + reason, stamped onto the narration.
                rate_override: this.hasForeignLine && this.forexRateOverride ? true : null,
                rate_override_reason: this.hasForeignLine && this.forexRateOverride ? (this.forexRateReason || null) : null,
                // Phase 12B — the DERIVED inter-company tag. Not user-editable: the
                // ledger's link decides it; the server re-derives and rejects mismatches.
                intercompany: (() => {
                    const c = this.interCompanyCounterparty();
                    return c ? { counterparty_company_id: c } : null;
                })(),
                party_ledger_id: this.showInvoice || this.isWorkflowType ? this.partyLedgerId : null,
                reference_no: this.showInvoice || this.isWorkflowType ? this.referenceNo || null : null,
                reference_date: this.showInvoice || this.isWorkflowType ? this.referenceDate || null : null,
                // reference_voucher_id chains the workflow: a Note → its invoice, a
                // Delivery/Receipt Note → its Order, and a Sales/Purchase invoice → the
                // Delivery/Receipt Note it bills against (the double-stock skip). The
                // server keeps it only for the types that carry it, so sending it
                // whenever set is safe.
                reference_voucher_id: this.referenceVoucherId || null,
                lines: this.isStockVoucher || this.isWorkflowType ? [] : this.buildPayloadLines(),
                items: this.showItemInvoice || this.isWorkflowType ? this.buildItemsPayload() : [],
                movement: this.isStockVoucher ? this.buildMovementPayload() : null,
            };
            try {
                // Phase 7C — ZeroBook Desktop, OFFLINE: the Livewire server is unreachable,
                // so queue the voucher to the local SQLite outbox instead. It posts through
                // the very same VoucherScreen::post() (chronologically) once connectivity
                // returns. Inert in the web edition (ZB_DESKTOP is undefined there), so the
                // online path below is completely unchanged. Stock vouchers are excluded —
                // their entry needs a live book-quantity read from the server.
                // Inside the try so the finally below always clears `accepting`: leaving it
                // latched would make every later Ctrl+A a silent no-op — a dead screen.
                if (window.ZB_DESKTOP && window.zbDesktop && window.zbDesktop.isOffline() && !this.isStockVoucher) {
                    await window.zbDesktop.postVoucher(payload);
                    this.flashMsg('✔ Queued offline · syncs when online (' + window.zbDesktop.pendingCount() + ' pending)', 'ok');
                    this.$store.zb.note('Queued offline', 'commit');
                    this.markVoucherPristine();
                    if (this.editId) { window.location.href = cfg.dayBookUrl; return; }
                    this.closePickers();
                    this.number = (parseInt(this.number, 10) || 0) + 1; // provisional local number
                    this.narration = '';
                    this.resetLines();
                    this.$nextTick(() => (this.isWorkflowType || this.showInvoice ? this.focusFirstField() : this.focusLine(0, 'drcr')));
                    return;
                }
                const res = await this.$wire.post(payload);
                // Livewire resolves (doesn't reject) on a server ValidationException,
                // so a missing return value means the server refused the voucher.
                if (!res || !res.voucher) {
                    this.flashMsg('Server rejected the voucher (out of balance or invalid)', 'warn');
                    return;
                }
                this.flashMsg('✔ ' + res.voucher.display_number + ' posted · Alt+P to print', 'ok');
                this.$store.zb.note('Posted ' + res.voucher.display_number, 'commit');
                // Saved — the screen no longer holds unsaved work, so continuous
                // entry does not prompt on the next Esc or type switch.
                this.markVoucherPristine();
                // remember it so Alt+P can print the just-accepted voucher
                this.lastPostedId = res.voucher.id;
                this.lastPostedNumber = res.voucher.display_number;
                // keep the Against-Ref picker fresh within a continuous-entry session
                this.updateOpenBillsFromPayload(payload.lines);
                // …and the TDS year-to-date cache, so the next payment to the same deductee
                // is measured against an aggregate that includes the one just posted.
                this.tdsAppendToLedger(res.voucher.id, payload.date, res.tds);
                if (this.editId) {
                    window.location.href = cfg.dayBookUrl;
                    return;
                }
                // continuous entry: fresh blank voucher of the same type
                this.closePickers();
                this.nextNumbers[this.type] = res.nextNumber;
                this.number = res.nextNumber;
                this.narration = '';
                this.resetLines();
                this.$nextTick(() => (this.isWorkflowType || this.showInvoice ? this.focusFirstField() : this.focusLine(0, 'drcr')));
            } catch (e) {
                this.flashMsg('Server rejected the voucher (out of balance or invalid)', 'warn');
            } finally {
                this.accepting = false;
            }
        },

        /* ---- cancel (alter mode, Alt+D) ---- */
        askCancel() {
            if (!this.editId) {
                this.flashMsg('Only saved vouchers can be cancelled', 'warn');
                return;
            }
            this.confirmingCancel = true;
            this.$store.zb.pushContext({
                name: 'v-cancel',
                label: 'Confirm cancel',
                actions: [{ key: 'enter', label: 'Yes, cancel', run: () => this.doCancel() }],
                onPop: () => (this.confirmingCancel = false),
            });
        },
        async doCancel() {
            if (this.$store.zb.activeName() === 'v-cancel') this.$store.zb.popContext();
            try {
                const res = await this.$wire.cancelVoucher();
                if (res && res.ok) {
                    window.location.href = cfg.dayBookUrl;
                } else {
                    this.flashMsg((res && res.message) || 'Could not cancel', 'warn');
                }
            } catch (e) {
                this.flashMsg('Could not cancel', 'warn');
            }
        },

        flashMsg(msg, kind) {
            this.flash = msg;
            this.flashKind = kind || 'ok';
            clearTimeout(this._flashT);
            this._flashT = setTimeout(() => (this.flash = ''), 2800);
        },
    };
}

/* ---- Day Book controller ------------------------------------------------ */
import { ensureElementVisible } from '../engine/scroll.js';

export function dayBook(cfg) {
    return {
        cfg,
        listActive: 0,
        confirming: null,
        flash: '',

        init() {
            this.$store.zb.pushContext({
                name: 'daybook',
                label: 'Day Book',
                focusEl: '#daybook-list',
                actions: [
                    { key: 'arrowdown', label: 'Next', hint: '↓', run: () => this.move(1) },
                    { key: 'arrowup', label: 'Previous', hint: '↑', run: () => this.move(-1) },
                    { key: 'enter', label: 'Open', allowInInput: true, run: () => this.onEnter() },
                    { key: 'f2', label: 'Period', allowInInput: true, run: () => this.focusPeriod() },
                    { key: 'alt+p', label: 'Print', allowInInput: true, run: () => this.printCurrent() },
                    { key: 'alt+d', label: 'Cancel', run: () => this.askCancel() },
                ],
                onEsc: () => {
                    if (this.confirming) return;
                    window.location.href = cfg.gatewayUrl;
                },
            });
            this.$nextTick(() => {
                const el = document.querySelector('#daybook-list');
                if (el) el.focus();
            });
        },

        rowsEls() {
            return Array.from(document.querySelectorAll('[data-voucher-row]'));
        },
        move(d) {
            const rows = this.rowsEls();
            if (!rows.length) return;
            this.listActive = Math.max(0, Math.min(rows.length - 1, this.listActive + d));
            const el = rows[this.listActive];
            if (!el) return;
            ensureElementVisible(el, { block: 'nearest', inline: 'nearest' });
            requestAnimationFrame(() => ensureElementVisible(el, { block: 'nearest', inline: 'nearest' }));
        },
        currentId() {
            const rows = this.rowsEls();
            const el = rows[this.listActive];
            return el ? parseInt(el.getAttribute('data-voucher-id'), 10) : null;
        },
        onEnter() {
            const el = document.activeElement;
            if (el && (el.id === 'daybook-from' || el.id === 'daybook-to')) {
                el.blur(); // commit wire:model.blur period, then return to the list
                this.$nextTick(() => {
                    const l = document.querySelector('#daybook-list');
                    if (l) l.focus();
                });
                return;
            }
            this.drill();
        },
        drill() {
            const id = this.currentId();
            if (id) window.location.href = cfg.alterUrl.replace('__id__', id);
        },
        printCurrent() {
            const id = this.currentId();
            if (!id) {
                this.flash = 'Select a voucher to print';
                clearTimeout(this._t);
                this._t = setTimeout(() => (this.flash = ''), 2200);
                return;
            }
            const url = (cfg.printUrl || '').replace('__id__', id);
            if (url) window.open(url, '_blank', 'noopener');
        },
        focusPeriod() {
            const el = document.querySelector('#daybook-from');
            if (el) el.focus();
        },
        askCancel() {
            const rows = this.rowsEls();
            const el = rows[this.listActive];
            if (!el) return;
            this.confirming = { id: parseInt(el.getAttribute('data-voucher-id'), 10), label: el.getAttribute('data-voucher-label') };
            this.$store.zb.pushContext({
                name: 'daybook-confirm',
                label: 'Confirm cancel',
                actions: [{ key: 'enter', label: 'Yes', run: () => this.doCancel() }],
                onPop: () => (this.confirming = null),
            });
        },
        async doCancel() {
            const item = this.confirming;
            if (this.$store.zb.activeName() === 'daybook-confirm') this.$store.zb.popContext();
            if (!item) return;
            try {
                const res = await this.$wire.cancel(item.id);
                this.flash = res && res.ok ? res.message : (res && res.message) || 'Could not cancel';
                this.$store.zb.note(this.flash, res && res.ok ? 'commit' : 'warn');
                if (this.listActive > 0) this.listActive--;
                clearTimeout(this._t);
                this._t = setTimeout(() => (this.flash = ''), 2600);
            } catch (e) {
                this.flash = 'Could not cancel';
            }
        },
    };
}

export function registerVouchers(Alpine) {
    Alpine.data('voucherScreen', voucherScreen);
    Alpine.data('dayBook', dayBook);
}
