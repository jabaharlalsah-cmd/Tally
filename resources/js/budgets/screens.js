/* =========================================================================
   ZeroBook — Phase 15A Budgets screen controllers (Alpine).
     budgetList     — list + mark-primary / revise / delete / new
     budgetVariance — flat variance report, drillable to ledger vouchers
     budgetEditor   — grid: add ledger/group lines via the master picker, save
     budgetSummary  — single-page roll-up, F2 as-of date
   All register on the Phase-1 keyboard engine ($store.zb) so the right button
   bar + bottom status bar reflect the active context automatically.
   ========================================================================= */

/* ---- Budget List -------------------------------------------------------- */
export function budgetList(cfg) {
    return {
        cfg,
        active: 0,

        init() {
            this.$store.zb.pushContext({
                name: 'budget-list',
                label: 'Budgets',
                focusEl: '#budget-list',
                actions: [
                    { key: 'arrowdown', label: 'Next', hint: '↓', run: () => this.move(1) },
                    { key: 'arrowup', label: 'Previous', hint: '↑', run: () => this.move(-1) },
                    { key: 'enter', label: 'Revise', run: () => this.open() },
                    { key: 'n', label: 'New budget', run: () => (window.location.href = cfg.newUrl) },
                    { key: 'p', label: 'Mark primary', run: () => this.primary() },
                    { key: 'delete', label: 'Delete', run: () => this.remove() },
                ],
                onEsc: () => (window.location.href = cfg.gatewayUrl),
            });
            this.$nextTick(() => { const el = document.querySelector('#budget-list'); if (el) el.focus(); });
        },
        rows() { return [...this.$root.querySelectorAll('[data-budget-row]')]; },
        curId() { const el = this.rows()[this.active]; return el ? el.getAttribute('data-id') : null; },
        move(d) {
            const n = this.rows().length;
            if (!n) return;
            this.active = Math.max(0, Math.min(n - 1, this.active + d));
            const el = this.rows()[this.active];
            if (el && el.scrollIntoView) el.scrollIntoView({ block: 'nearest' });
        },
        open() { const id = this.curId(); if (id) window.location.href = cfg.editUrl.replace('__id__', id); },
        primary() { const id = this.curId(); if (id) this.$wire.markPrimary(Number(id)); },
        remove() {
            const id = this.curId();
            if (!id) return;
            if (window.confirm('Delete this budget and all its targets? This cannot be undone.')) {
                this.$wire.remove(Number(id));
            }
        },
    };
}

/* ---- Budget vs Actual Variance (flat, drillable) ------------------------ */
export function budgetVariance(cfg) {
    return {
        cfg,
        rows: cfg.rows || [],
        activeKey: null,

        init() {
            const nav = this.navRows();
            this.activeKey = nav.length ? nav[0].key : null;
            this.$store.zb.pushContext({
                name: 'budget-variance',
                label: cfg.title || 'Budget vs Actual',
                focusEl: '#report-surface',
                actions: [
                    { key: 'arrowdown', label: 'Next', hint: '↓', run: () => this.move(1) },
                    { key: 'arrowup', label: 'Previous', hint: '↑', run: () => this.move(-1) },
                    { key: 'enter', label: 'Drill to vouchers', allowInInput: true, run: () => { if (!this.onPeriodEnter()) this.drill(); } },
                    { key: 'f2', label: 'Period', allowInInput: true, run: () => this.focusPeriod() },
                ],
                onEsc: () => (window.location.href = cfg.gatewayUrl),
            });
            this.$nextTick(() => { const el = document.querySelector('#report-surface'); if (el) el.focus(); });
        },
        navRows() { return this.rows; },
        row(key) { return this.rows.find((r) => r.key === key); },
        isActive(key) { return this.activeKey === key; },
        inPeriodInput() { const el = document.activeElement; return el && (el.id === 'report-from' || el.id === 'report-to'); },
        move(d) {
            if (this.inPeriodInput()) return;
            const nav = this.navRows();
            if (!nav.length) return;
            let i = nav.findIndex((r) => r.key === this.activeKey);
            if (i < 0) i = 0;
            i = Math.max(0, Math.min(nav.length - 1, i + d));
            this.activeKey = nav[i].key;
            this.$nextTick(() => { const el = document.querySelector('[data-key="' + this.activeKey + '"]'); if (el && el.scrollIntoView) el.scrollIntoView({ block: 'nearest' }); });
        },
        drill() {
            const r = this.row(this.activeKey);
            if (!r || !r.drill_ledger_id) return;
            let u = cfg.drillUrl.replace('__id__', r.drill_ledger_id);
            const q = [];
            if (cfg.from) q.push('from=' + encodeURIComponent(cfg.from));
            if (cfg.to) q.push('to=' + encodeURIComponent(cfg.to));
            window.location.href = u + (q.length ? '?' + q.join('&') : '');
        },
        focusPeriod() { const el = document.querySelector('#report-from'); if (el) el.focus(); },
        onPeriodEnter() {
            const el = document.activeElement;
            if (el && (el.id === 'report-from' || el.id === 'report-to')) {
                el.blur();
                this.$nextTick(() => { const s = document.querySelector('#report-surface'); if (s) s.focus(); });
                return true;
            }
            return false;
        },
    };
}

/* ---- Budget Editor grid ------------------------------------------------- */
export function budgetEditor(cfg) {
    return {
        cfg,

        init() {
            // Seed the master cache so the ledger/group pickers have data.
            if (cfg.masters) {
                this.$store.masters.seed(cfg.masters.groups || [], cfg.masters.ledgers || []);
            }
            // A picked ledger/group is added straight to the grid via a Livewire call.
            this._onPick = (e) => {
                const d = e.detail || {};
                if (d.comboId === 'budget-add-ledger') { this.$wire.addLedger(Number(d.id)); this.clearPicker('budget-add-ledger'); }
                else if (d.comboId === 'budget-add-group') { this.$wire.addGroup(Number(d.id)); this.clearPicker('budget-add-group'); }
            };
            window.addEventListener('zb:combo-pick', this._onPick);

            this.$store.zb.pushContext({
                name: 'budget-editor',
                label: cfg.reviseMode ? 'Revise Budget' : 'Budget Editor',
                focusEl: '#budget-editor',
                actions: [
                    { key: 'f9', label: 'Save', allowInInput: true, run: () => this.$wire.save() },
                    { key: 'ctrl+a', label: 'Save', allowInInput: true, hidden: true, run: () => this.$wire.save() },
                ],
                onEsc: () => (window.location.href = cfg.listUrl),
            });
            this.$nextTick(() => { const el = document.querySelector('#budget-editor'); if (el) el.focus(); });
        },
        destroy() { if (this._onPick) window.removeEventListener('zb:combo-pick', this._onPick); },

        /** Reset a picker input after it added a line, so the next pick is fresh. */
        clearPicker(id) {
            this.$nextTick(() => {
                const root = document.querySelector('[data-combo-id="' + id + '"] input');
                if (root) { root.value = ''; root.blur(); }
            });
        },
    };
}

/* ---- Budget Summary ----------------------------------------------------- */
export function budgetSummary(cfg) {
    return {
        cfg,
        init() {
            this.$store.zb.pushContext({
                name: 'budget-summary',
                label: 'Budget Summary',
                focusEl: '#budget-summary',
                actions: [
                    { key: 'f2', label: 'As-of date', allowInInput: true, run: () => { const el = document.querySelector('#summary-asof'); if (el) el.focus(); } },
                ],
                onEsc: () => (window.location.href = cfg.gatewayUrl),
            });
            this.$nextTick(() => { const el = document.querySelector('#budget-summary'); if (el) el.focus(); });
        },
    };
}

export function registerBudgets(Alpine) {
    Alpine.data('budgetList', budgetList);
    Alpine.data('budgetVariance', budgetVariance);
    Alpine.data('budgetEditor', budgetEditor);
    Alpine.data('budgetSummary', budgetSummary);
}
