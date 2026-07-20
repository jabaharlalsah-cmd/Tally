/* =========================================================================
   ZeroBook — Phase 15C Scenarios screen controllers (Alpine).
     scenarioMaster  — create / rename / activate / typed-delete scenarios
     scenarioManager — review a scenario's provisional vouchers, then promote
     scenarioImpact  — real vs real+scenario deltas; F2 as-of, Enter drills
   Registered on the Phase-1 keyboard engine so the right button bar + status
   bar reflect the active context automatically.
   ========================================================================= */

/* ---- Scenario Master ---------------------------------------------------- */
export function scenarioMaster(cfg) {
    return {
        cfg,
        init() {
            this.$store.zb.pushContext({
                name: 'scenario-master',
                label: 'Scenarios',
                focusEl: '#scenario-master',
                actions: [
                    { key: 'f9', label: 'New scenario', allowInInput: true, run: () => this.focusNew() },
                    { key: 'ctrl+m', label: 'Manager', hidden: true, run: () => (window.location.href = cfg.managerUrl) },
                ],
                onEsc: () => (window.location.href = cfg.gatewayUrl),
            });
            this.$nextTick(() => { const el = document.querySelector('#scenario-master'); if (el) el.focus(); });
        },
        focusNew() { const el = document.querySelector('#scn-new-name'); if (el) { el.focus(); el.select(); } },
    };
}

/* ---- Scenario Manager (promote) ----------------------------------------- */
export function scenarioManager(cfg) {
    return {
        cfg,
        init() {
            this.$store.zb.pushContext({
                name: 'scenario-manager',
                label: 'Scenario Manager',
                focusEl: '#scenario-manager',
                actions: [
                    { key: 'f5', label: 'Impact report', run: () => { if (cfg.impactUrl) window.location.href = cfg.impactUrl; } },
                ],
                onEsc: () => (window.location.href = cfg.masterUrl),
            });
            this.$nextTick(() => { const el = document.querySelector('#scenario-manager'); if (el) el.focus(); });
        },
    };
}

/* ---- Scenario Impact ---------------------------------------------------- */
export function scenarioImpact(cfg) {
    return {
        cfg,
        active: 0,
        init() {
            this.$store.zb.pushContext({
                name: 'scenario-impact',
                label: 'Scenario Impact',
                focusEl: '#scenario-impact',
                actions: [
                    { key: 'arrowdown', label: 'Next', hint: '↓', run: () => this.move(1) },
                    { key: 'arrowup', label: 'Previous', hint: '↑', run: () => this.move(-1) },
                    { key: 'enter', label: 'Drill to vouchers', allowInInput: true, run: () => { if (!this.onAsOfEnter()) this.drill(); } },
                    { key: 'f2', label: 'As-of date', allowInInput: true, run: () => this.focusAsOf() },
                ],
                onEsc: () => (window.location.href = cfg.masterUrl),
            });
            this.$nextTick(() => { const el = document.querySelector('#scenario-impact'); if (el) el.focus(); });
        },
        rows() { return [...this.$root.querySelectorAll('[data-ledger-row]')]; },
        paint() {
            this.rows().forEach((r, i) => r.classList.toggle('is-active', i === this.active));
            const el = this.rows()[this.active];
            if (el && el.scrollIntoView) el.scrollIntoView({ block: 'nearest' });
        },
        inAsOf() { const el = document.activeElement; return el && el.id === 'scn-asof'; },
        move(d) {
            if (this.inAsOf()) return;
            const n = this.rows().length;
            if (!n) return;
            this.active = Math.max(0, Math.min(n - 1, this.active + d));
            this.paint();
        },
        drill() {
            const el = this.rows()[this.active];
            const id = el && el.getAttribute('data-ledger-id');
            if (!id || !cfg.ledgerUrl) return;
            let u = cfg.ledgerUrl.replace('__id__', id);
            const q = [];
            if (cfg.from) q.push('from=' + encodeURIComponent(cfg.from));
            if (cfg.to) q.push('to=' + encodeURIComponent(cfg.to));
            window.location.href = u + (q.length ? '?' + q.join('&') : '');
        },
        focusAsOf() { const el = document.querySelector('#scn-asof'); if (el) el.focus(); },
        onAsOfEnter() {
            const el = document.activeElement;
            if (el && el.id === 'scn-asof') {
                el.blur();
                this.$nextTick(() => { const s = document.querySelector('#scenario-impact'); if (s) s.focus(); });
                return true;
            }
            return false;
        },
    };
}

export function registerScenarios(Alpine) {
    Alpine.data('scenarioMaster', scenarioMaster);
    Alpine.data('scenarioManager', scenarioManager);
    Alpine.data('scenarioImpact', scenarioImpact);
}
