/* =========================================================================
   ZeroBook — Phase 15B Ratio Analysis screen controllers (Alpine).
     ratioDashboard  — the 4-section grid; ↑↓ move, Enter drill, F2 as-of, F12 thresholds
     ratioDrilldown  — a ratio's inputs + contributing ledgers, drill to vouchers
     ratioThresholds — edit the colour bands, F9 save
   Registered on the Phase-1 keyboard engine so the right button bar + status bar
   reflect the active context automatically.
   ========================================================================= */

/* ---- Ratio Dashboard ---------------------------------------------------- */
export function ratioDashboard(cfg) {
    return {
        cfg,
        active: 0,

        init() {
            this.$store.zb.pushContext({
                name: 'ratio-dashboard',
                label: 'Ratio Analysis',
                focusEl: '#ratio-surface',
                actions: [
                    { key: 'arrowdown', label: 'Next', hint: '↓', run: () => this.move(1) },
                    { key: 'arrowup', label: 'Previous', hint: '↑', run: () => this.move(-1) },
                    { key: 'enter', label: 'Drill to inputs', allowInInput: true, run: () => { if (!this.onPeriodEnter()) this.drill(); } },
                    { key: 'f2', label: 'As-of date', allowInInput: true, run: () => this.focusPeriod() },
                    { key: 'f12', label: 'Thresholds', run: () => (window.location.href = cfg.thresholdsUrl) },
                ],
                onEsc: () => (window.location.href = cfg.gatewayUrl),
            });
            this.$nextTick(() => { const el = document.querySelector('#ratio-surface'); if (el) el.focus(); });
        },
        rows() { return [...this.$root.querySelectorAll('[data-ratio-row]')]; },
        paint() {
            this.rows().forEach((r, i) => r.classList.toggle('is-active', i === this.active));
            const el = this.rows()[this.active];
            if (el && el.scrollIntoView) el.scrollIntoView({ block: 'nearest' });
        },
        inPeriodInput() { const el = document.activeElement; return el && el.id === 'ratio-asof'; },
        move(d) {
            if (this.inPeriodInput()) return;
            const n = this.rows().length;
            if (!n) return;
            this.active = Math.max(0, Math.min(n - 1, this.active + d));
            this.paint();
        },
        drill() {
            const el = this.rows()[this.active];
            this.drillTo(el && el.getAttribute('data-key'));
        },
        /** Navigate to a ratio's inputs, carrying the LIVE F2 as-of (the Livewire update POST has no
            asOf query param, so request('asOf') in the blade is unreliable — read the input directly). */
        drillTo(key) {
            if (!key) return;
            const asof = (document.querySelector('#ratio-asof') || {}).value || '';
            let u = cfg.drilldownUrl.replace('__key__', key);
            if (asof) u += '?asOf=' + encodeURIComponent(asof);
            window.location.href = u;
        },
        focusPeriod() { const el = document.querySelector('#ratio-asof'); if (el) el.focus(); },
        onPeriodEnter() {
            const el = document.activeElement;
            if (el && el.id === 'ratio-asof') {
                el.blur();
                this.$nextTick(() => { const s = document.querySelector('#ratio-surface'); if (s) s.focus(); });
                return true;
            }
            return false;
        },
    };
}

/* ---- Ratio Drill-down --------------------------------------------------- */
export function ratioDrilldown(cfg) {
    return {
        cfg,
        active: 0,

        init() {
            this.$store.zb.pushContext({
                name: 'ratio-drilldown',
                label: cfg.title || 'Ratio Inputs',
                focusEl: '#ratio-drill',
                actions: [
                    { key: 'arrowdown', label: 'Next', hint: '↓', run: () => this.move(1) },
                    { key: 'arrowup', label: 'Previous', hint: '↑', run: () => this.move(-1) },
                    { key: 'enter', label: 'Drill to vouchers', run: () => this.drill() },
                ],
                onEsc: () => (window.location.href = cfg.backUrl),
            });
            this.$nextTick(() => { const el = document.querySelector('#ratio-drill'); if (el) el.focus(); });
        },
        rows() { return [...this.$root.querySelectorAll('[data-ledger-row]')]; },
        paint() {
            this.rows().forEach((r, i) => r.classList.toggle('is-active', i === this.active));
            const el = this.rows()[this.active];
            if (el && el.scrollIntoView) el.scrollIntoView({ block: 'nearest' });
        },
        move(d) {
            const n = this.rows().length;
            if (!n) return;
            this.active = Math.max(0, Math.min(n - 1, this.active + d));
            this.paint();
        },
        drill() {
            const el = this.rows()[this.active];
            const id = el && el.getAttribute('data-ledger-id');
            if (!id) return;
            let u = cfg.ledgerUrl.replace('__id__', id);
            const q = [];
            if (cfg.from) q.push('from=' + encodeURIComponent(cfg.from));
            if (cfg.to) q.push('to=' + encodeURIComponent(cfg.to));
            window.location.href = u + (q.length ? '?' + q.join('&') : '');
        },
    };
}

/* ---- Ratio Thresholds settings ------------------------------------------ */
export function ratioThresholds(cfg) {
    return {
        cfg,
        init() {
            this.$store.zb.pushContext({
                name: 'ratio-thresholds',
                label: 'Ratio Thresholds',
                focusEl: '#ratio-thresholds',
                actions: [
                    { key: 'f9', label: 'Save', allowInInput: true, run: () => this.$wire.save() },
                    { key: 'ctrl+a', label: 'Save', allowInInput: true, hidden: true, run: () => this.$wire.save() },
                ],
                onEsc: () => (window.location.href = cfg.backUrl),
            });
            this.$nextTick(() => { const el = document.querySelector('#ratio-thresholds'); if (el) el.focus(); });
        },
    };
}

export function registerRatios(Alpine) {
    Alpine.data('ratioDashboard', ratioDashboard);
    Alpine.data('ratioDrilldown', ratioDrilldown);
    Alpine.data('ratioThresholds', ratioThresholds);
}
