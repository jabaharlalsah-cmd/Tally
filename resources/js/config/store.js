/* =========================================================================
   ZeroBook — F12 Configuration (screen-level) store + overlay.
   F12 options are UI preferences kept client-side (localStorage) so they
   persist across reloads. F11 features (company-level) live in the DB.
   The F12 overlay is context-aware: it shows the options for whichever screen
   context is active when opened.
   ========================================================================= */

export function configStore() {
    return {
        data: {
            singleEntry: false, // vouchers: single-entry mode
            // Phase 10A — engage the TDS panel automatically when a Payment names a
            // deductee that carries a default section. Default ON: forgetting to deduct
            // is a statutory default with interest, so the safe state is opt-out.
            tdsAuto: true,
            showOpening: false, // reports: show opening balance column
            showPercent: false, // reports: show percentage-of-total column
        },
        load() {
            try {
                const s = localStorage.getItem('zb-config');
                if (s) this.data = Object.assign(this.data, JSON.parse(s));
            } catch (_) {}
        },
        persist() {
            try {
                localStorage.setItem('zb-config', JSON.stringify(this.data));
            } catch (_) {}
        },
        get(k) {
            return !!this.data[k];
        },
        set(k, v) {
            this.data[k] = !!v;
            this.persist();
        },
        toggle(k) {
            this.set(k, !this.data[k]);
        },
    };
}

/* The F12 overlay component (context-aware). */
export function zbConfig() {
    return {
        open: false,
        active: 0,
        ctxLabel: '',
        options: [],

        init() {
            window.addEventListener('zb:open-config', () => this.openPanel());
        },

        optionsFor(name) {
            if (name === 'voucher') {
                const opts = [{ key: 'singleEntry', label: 'Use single-entry mode for Payment / Receipt / Contra' }];
                // Expose the As-Invoice/As-Voucher toggle for Sales/Purchase (also Ctrl+V).
                const v = window.ZB_VOUCHER;
                if (v && v.isInvoiceType) {
                    opts.push({ key: '__invoiceMode', label: 'Enter Sales / Purchase as Invoice (Ctrl+V)', action: 'invoiceToggle' });
                }
                // Phase 10A — only meaningful on a Payment screen with TDS switched on.
                if (v && v.showTdsPanel) {
                    opts.push({ key: 'tdsAuto', label: 'Deduct TDS automatically for a tagged deductee (Alt+T)' });
                }
                return { label: 'Voucher configuration', options: opts };
            }
            if (name === 'report') {
                return {
                    label: 'Report configuration',
                    options: [
                        { key: 'showOpening', label: 'Show Opening Balance' },
                        { key: 'showPercent', label: 'Show Percentages' },
                    ],
                };
            }
            return { label: name, options: [] };
        },

        openPanel() {
            // find the nearest meaningful screen context (skip picker/overlay contexts)
            const stack = this.$store.zb.stack;
            let picked = { label: 'this screen', options: [] };
            for (let i = stack.length - 1; i >= 0; i--) {
                const cand = this.optionsFor(stack[i].name);
                if (cand.options.length) {
                    picked = cand;
                    break;
                }
            }
            this.ctxLabel = picked.label;
            this.options = picked.options;
            this.active = 0;
            this.open = true;
            this.$store.zb.pushContext({
                name: 'config',
                label: 'F12: Configure',
                focusEl: '#config-surface',
                actions: [
                    { key: 'arrowdown', label: 'Next', hint: '↓', run: () => this.move(1) },
                    { key: 'arrowup', label: 'Previous', hint: '↑', run: () => this.move(-1) },
                    { key: 'enter', label: 'Toggle', run: () => this.toggle() },
                    { key: 'space', label: 'Toggle', hidden: true, run: () => this.toggle() },
                ],
                onPop: () => {
                    this.open = false;
                    this.$store.zb.rev++;
                },
            });
        },
        move(d) {
            if (!this.options.length) return;
            this.active = Math.max(0, Math.min(this.options.length - 1, this.active + d));
        },
        toggle() {
            if (!this.options.length) return;
            const opt = this.options[this.active];
            // The invoice/voucher entry-mode option is runtime state on the active
            // voucher screen (so the current entry is preserved on switch), not a
            // persisted preference — delegate to the voucher controller.
            if (opt.action === 'invoiceToggle') {
                if (window.ZB_VOUCHER && typeof window.ZB_VOUCHER.toggleInvoiceMode === 'function') {
                    window.ZB_VOUCHER.toggleInvoiceMode();
                }
                return;
            }
            this.$store.config.toggle(opt.key);
        },
        val(k) {
            if (k === '__invoiceMode') {
                return !!(window.ZB_VOUCHER && window.ZB_VOUCHER.showInvoice);
            }
            return this.$store.config.get(k);
        },
    };
}

export function registerConfig(Alpine) {
    const s = configStore();
    s.load();
    Alpine.store('config', s);
    Alpine.data('zbConfig', zbConfig);
}
