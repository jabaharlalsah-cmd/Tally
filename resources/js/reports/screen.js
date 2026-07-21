/* =========================================================================
   ZeroBook — shared report controller (Alpine 'reportScreen') for Trial
   Balance / Balance Sheet / Profit & Loss, plus 'ledgerVouchers' for the drill
   list. Keyboard navigation, expand/collapse and highlight are 100% client-side
   (the full row set is rendered by the server once); only F2 (period) and
   drilling into a ledger/voucher hit the server as a normal navigation.
   ========================================================================= */

export function reportScreen(cfg) {
    return {
        cfg,
        rows: cfg.rows || [],
        rowsByKey: {},
        expanded: {},
        detailed: false,
        activeKey: null,

        init() {
            this.rows.forEach((r) => (this.rowsByKey[r.key] = r));
            const first = this.navRows()[0];
            this.activeKey = first ? first.key : null;

            // Coming back from a drill-down? Land on the line we left, expanded
            // as we left it — TallyPrime's behaviour. Consumed on read, so
            // entering the report fresh from the menu starts at the top.
            this.restoreView();

            this.$store.zb.pushContext({
                name: 'report',
                label: cfg.title || 'Report',
                focusEl: '#report-surface',
                actions: [
                    { key: 'arrowdown', label: 'Next', hint: '↓', run: () => this.move(1) },
                    { key: 'arrowup', label: 'Previous', hint: '↑', run: () => this.move(-1) },
                    { key: 'enter', label: 'Drill down', allowInInput: true, run: () => { if (!this.onPeriodEnter()) this.enter(); } },
                    { key: 'alt+f1', label: this.detailed ? 'Condensed' : 'Detailed', run: () => this.toggleDetailed() },
                    { key: 'f2', label: 'Period', allowInInput: true, run: () => this.focusPeriod() },
                    // Alt+F2 is Tally's period key. Registered here so it does NOT
                    // fall through to the global handler, which only relabels the
                    // top bar and leaves the report period untouched — a key that
                    // looks like it worked and did nothing.
                    { key: 'alt+f2', label: 'Period', allowInInput: true, run: () => this.focusPeriod() },
                ],
                onEsc: () => this.escape(),
            });
            this.$nextTick(() => {
                const el = document.querySelector('#report-surface');
                if (el) el.focus();
            });
        },

        row(key) {
            return this.rowsByKey[key];
        },
        // F12 report options
        cfgOpt(k) {
            return this.$store.config ? this.$store.config.get(k) : false;
        },
        pct(key) {
            const r = this.rowsByKey[key];
            const total = cfg.grandTotal || 0;
            if (!r || !total || r.closing_paise == null) return '';
            return (Math.abs(r.closing_paise) / total * 100).toFixed(1) + '%';
        },
        isVisible(key) {
            const r = this.rowsByKey[key];
            if (!r) return false;
            // Specials (net/total/stock/gross/subtotal) always show; group/ledger AND
            // Stock-Summary 'item' leaves honour the ancestor-expanded gate so a group
            // truly collapses its items (root-level rows have no ancestors → visible).
            if (r.kind !== 'group' && r.kind !== 'ledger' && r.kind !== 'item') return true;
            if (this.detailed) return true;
            return (r.ancestors || []).every((a) => this.expanded[a]);
        },
        isActive(key) {
            return this.activeKey === key;
        },
        navRows() {
            // 'item' = a Stock Summary leaf (Phase 6D); drills like a ledger row.
            return this.rows.filter((r) => (r.kind === 'group' || r.kind === 'ledger' || r.kind === 'item') && this.isVisible(r.key));
        },

        inPeriodInput() {
            const el = document.activeElement;
            return el && (el.id === 'report-from' || el.id === 'report-to');
        },
        move(d) {
            if (this.inPeriodInput()) return; // don't move rows while editing the period
            const nav = this.navRows();
            if (!nav.length) return;
            let i = nav.findIndex((r) => r.key === this.activeKey);
            if (i < 0) i = 0;
            i = Math.max(0, Math.min(nav.length - 1, i + d));
            this.activeKey = nav[i].key;
            this.$nextTick(() => {
                const el = document.querySelector('[data-key="' + this.activeKey + '"]');
                if (el && el.scrollIntoView) el.scrollIntoView({ block: 'nearest' });
            });
        },

        /** Keep activeKey on a still-visible, navigable row after expand/collapse/detail changes. */
        reconcileActive() {
            const nav = this.navRows();
            if (!nav.length) {
                this.activeKey = null;
                return;
            }
            if (nav.some((r) => r.key === this.activeKey)) return;
            const old = this.row(this.activeKey);
            let target = null;
            if (old && old.ancestors && old.ancestors.length) {
                for (let i = old.ancestors.length - 1; i >= 0; i--) {
                    if (nav.some((r) => r.key === old.ancestors[i])) {
                        target = old.ancestors[i];
                        break;
                    }
                }
            }
            this.activeKey = target || nav[0].key;
        },

        toggleExpand(key) {
            const e = Object.assign({}, this.expanded);
            if (e[key]) delete e[key];
            else e[key] = true;
            this.expanded = e;
            this.reconcileActive();
        },
        toggleDetailed() {
            this.detailed = !this.detailed;
            this.reconcileActive();
            const top = this.$store.zb.peek();
            if (top && top.name === 'report') {
                const a = top.map['alt+f1'];
                if (a) a.label = this.detailed ? 'Condensed' : 'Detailed';
                this.$store.zb.rev++;
            }
        },

        enter() {
            const r = this.row(this.activeKey);
            if (!r) return;
            if (r.kind === 'group') {
                if (r.collapsible) this.toggleExpand(r.key);
            } else if ((r.kind === 'ledger' || r.kind === 'item') && r.ledger_id) {
                // Remember where we were before leaving, so Esc from the drilled
                // report lands back on THIS row (see restoreView).
                const url = this.drillUrl(r.ledger_id);
                this.rememberView(url.split('?')[0]);
                window.location.href = url;
            }
        },

        /**
         * The report's view state, stashed across a drill-down.
         *
         * Drilling is a full page navigation, so the cursor, the expanded groups
         * and the Detailed toggle are all lost on the way back. In TallyPrime
         * coming back up a level returns you to exactly the line you left —
         * without that, every Esc on a long Trial Balance dumps the operator at
         * row one and they have to find their place again.
         *
         * sessionStorage, not localStorage: this is one journey's state, and it
         * should not survive the tab.
         */
        viewKey() {
            return 'zb.report:' + window.location.pathname;
        },

        rememberView(drillPath) {
            try {
                window.sessionStorage.setItem(
                    this.viewKey(),
                    JSON.stringify({
                        activeKey: this.activeKey,
                        detailed: this.detailed,
                        expanded: this.expanded,
                        // Where we drilled TO. The position is only restored when
                        // we come back FROM there, so re-entering the report from
                        // the menu starts fresh — Tally remembers your place
                        // within a drill, not across a whole session.
                        drillPath,
                    })
                );
            } catch (_) {
                /* private mode — the cursor simply won't be restored */
            }
        },

        /**
         * Restore and CONSUME the stashed state. Consuming matters: entering the
         * report fresh from the menu should start at the top, not resurrect a
         * cursor from an earlier visit.
         */
        restoreView() {
            let saved = null;
            try {
                const raw = window.sessionStorage.getItem(this.viewKey());
                if (raw) {
                    saved = JSON.parse(raw);
                    window.sessionStorage.removeItem(this.viewKey());
                }
            } catch (_) {
                return false;
            }
            if (!saved) return false;

            // Only restore when we have come back FROM the drill target. Arriving
            // fresh from the menu should start at the top; the stash is already
            // consumed above, so a stale one can never linger.
            const ref = document.referrer || '';
            if (saved.drillPath && ref.indexOf(saved.drillPath) === -1) return false;

            if (saved.expanded) this.expanded = saved.expanded;
            if (saved.detailed) this.detailed = true;
            // Only accept a row that still exists — the period may have changed,
            // or the ledger may have been retired, while we were away.
            if (saved.activeKey && this.rowsByKey[saved.activeKey]) {
                this.activeKey = saved.activeKey;
                this.$nextTick(() => {
                    const el = document.querySelector('[data-key="' + this.activeKey + '"]');
                    if (el && el.scrollIntoView) el.scrollIntoView({ block: 'nearest' });
                });
            }

            return true;
        },
        drillUrl(ledgerId) {
            let u = cfg.drillUrl.replace('__id__', ledgerId);
            const q = [];
            if (cfg.from) q.push('from=' + encodeURIComponent(cfg.from));
            if (cfg.to) q.push('to=' + encodeURIComponent(cfg.to));
            return u + (q.length ? '?' + q.join('&') : '');
        },

        escape() {
            // ascend one level: detailed -> off, then collapse the current group's
            // expanded parent, else exit to the Gateway.
            if (this.detailed) {
                this.detailed = false;
                return;
            }
            const r = this.row(this.activeKey);
            const parent = r && r.ancestors && r.ancestors.length ? r.ancestors[r.ancestors.length - 1] : null;
            if (parent && this.expanded[parent]) {
                this.toggleExpand(parent);
                this.activeKey = parent;
                return;
            }
            window.location.href = cfg.gatewayUrl;
        },

        focusPeriod() {
            const el = document.querySelector('#report-from');
            if (el) el.focus();
        },
        onPeriodEnter() {
            const el = document.activeElement;
            if (el && (el.id === 'report-from' || el.id === 'report-to')) {
                el.blur();
                this.$nextTick(() => {
                    const s = document.querySelector('#report-surface');
                    if (s) s.focus();
                });
                return true;
            }
            return false;
        },
    };
}

/* ---- Ledger Vouchers drill list (reuses the Day Book row pattern) ------- */
export function ledgerVouchers(cfg) {
    return {
        cfg,
        listActive: 0,

        init() {
            this.$store.zb.pushContext({
                name: 'ledger-vouchers',
                label: cfg.title || 'Ledger Vouchers',
                focusEl: '#lv-list',
                actions: [
                    { key: 'arrowdown', label: 'Next', hint: '↓', run: () => this.move(1) },
                    { key: 'arrowup', label: 'Previous', hint: '↑', run: () => this.move(-1) },
                    { key: 'enter', label: 'Open voucher', run: () => this.drill() },
                ],
                onEsc: () => (window.location.href = cfg.backUrl),
            });
            this.$nextTick(() => {
                const el = document.querySelector('#lv-list');
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
            if (el && el.scrollIntoView) el.scrollIntoView({ block: 'nearest' });
        },
        drill() {
            const rows = this.rowsEls();
            const el = rows[this.listActive];
            if (el) window.location.href = cfg.alterUrl.replace('__id__', el.getAttribute('data-voucher-id'));
        },
    };
}

/* ---- GST Summary (period output/input/net tax, drill to a duty ledger) --- */
export function gstSummary(cfg) {
    return {
        cfg,
        activeIdx: 0,

        init() {
            this.activeIdx = this.firstDrillable();
            this.$store.zb.pushContext({
                name: 'gst-summary',
                label: cfg.title || 'GST Summary',
                focusEl: '#report-surface',
                actions: [
                    { key: 'arrowdown', label: 'Next', hint: '↓', run: () => this.move(1) },
                    { key: 'arrowup', label: 'Previous', hint: '↑', run: () => this.move(-1) },
                    { key: 'enter', label: 'Drill down', allowInInput: true, run: () => { if (!this.onPeriodEnter()) this.drill(); } },
                    { key: 'f2', label: 'Period', allowInInput: true, run: () => this.focusPeriod() },
                    // Alt+F2 is Tally's period key. Registered here so it does NOT
                    // fall through to the global handler, which only relabels the
                    // top bar and leaves the report period untouched — a key that
                    // looks like it worked and did nothing.
                    { key: 'alt+f2', label: 'Period', allowInInput: true, run: () => this.focusPeriod() },
                ],
                onEsc: () => (window.location.href = cfg.gatewayUrl),
            });
            this.$nextTick(() => {
                const el = document.querySelector('#report-surface');
                if (el) el.focus();
            });
        },
        rowEls() {
            return Array.from(document.querySelectorAll('[data-gst-row]'));
        },
        drillableAt(i) {
            const el = this.rowEls()[i];
            return el && el.getAttribute('data-ledger-id');
        },
        firstDrillable() {
            const els = this.rowEls();
            for (let i = 0; i < els.length; i++) if (els[i].getAttribute('data-ledger-id')) return i;
            return 0;
        },
        inPeriodInput() {
            const el = document.activeElement;
            return el && (el.id === 'report-from' || el.id === 'report-to');
        },
        move(d) {
            if (this.inPeriodInput()) return;
            const els = this.rowEls();
            if (!els.length) return;
            let i = this.activeIdx;
            // step to the next drillable row in the direction d
            for (let step = 0; step < els.length; step++) {
                i = Math.max(0, Math.min(els.length - 1, i + d));
                if (els[i].getAttribute('data-ledger-id')) break;
                if (i === 0 || i === els.length - 1) break;
            }
            this.activeIdx = i;
            const el = els[i];
            if (el && el.scrollIntoView) el.scrollIntoView({ block: 'nearest' });
        },
        drill() {
            const id = this.drillableAt(this.activeIdx);
            if (!id) return;
            let u = cfg.drillUrl.replace('__id__', id);
            const q = [];
            if (cfg.from) q.push('from=' + encodeURIComponent(cfg.from));
            if (cfg.to) q.push('to=' + encodeURIComponent(cfg.to));
            window.location.href = u + (q.length ? '?' + q.join('&') : '');
        },
        focusPeriod() {
            const el = document.querySelector('#report-from');
            if (el) el.focus();
        },
        onPeriodEnter() {
            const el = document.activeElement;
            if (el && (el.id === 'report-from' || el.id === 'report-to')) {
                el.blur();
                this.$nextTick(() => {
                    const s = document.querySelector('#report-surface');
                    if (s) s.focus();
                });
                return true;
            }
            return false;
        },
    };
}

/* ---- Outstandings (Receivables / Payables) — drill to the bill's vouchers -- */
export function outstandings(cfg) {
    return {
        cfg,
        activeIdx: 0,

        init() {
            this.activeIdx = this.firstDrillable();
            this.$store.zb.pushContext({
                name: 'outstandings',
                label: cfg.title || 'Outstandings',
                focusEl: '#report-surface',
                actions: [
                    { key: 'arrowdown', label: 'Next', hint: '↓', run: () => this.move(1) },
                    { key: 'arrowup', label: 'Previous', hint: '↑', run: () => this.move(-1) },
                    { key: 'enter', label: 'Drill to voucher(s)', allowInInput: true, run: () => { if (!this.onPeriodEnter()) this.drill(); } },
                    { key: 'f2', label: 'As-on date', allowInInput: true, run: () => this.focusPeriod() },
                ],
                onEsc: () => (window.location.href = cfg.gatewayUrl),
            });
            this.$nextTick(() => {
                const el = document.querySelector('#report-surface');
                if (el) el.focus();
            });
        },
        rowEls() {
            return Array.from(document.querySelectorAll('[data-out-row]'));
        },
        drillableAt(i) {
            const el = this.rowEls()[i];
            return el && el.getAttribute('data-ref') ? el : null;
        },
        firstDrillable() {
            const els = this.rowEls();
            for (let i = 0; i < els.length; i++) if (els[i].getAttribute('data-ref')) return i;
            return 0;
        },
        inPeriodInput() {
            const el = document.activeElement;
            return el && el.id === 'report-to';
        },
        move(d) {
            if (this.inPeriodInput()) return;
            const els = this.rowEls();
            if (!els.length) return;
            let i = this.activeIdx;
            for (let step = 0; step < els.length; step++) {
                i = Math.max(0, Math.min(els.length - 1, i + d));
                if (els[i].getAttribute('data-ref')) break;
                if (i === 0 || i === els.length - 1) break;
            }
            this.activeIdx = i;
            const el = els[i];
            if (el && el.scrollIntoView) el.scrollIntoView({ block: 'nearest' });
        },
        drill() {
            const el = this.drillableAt(this.activeIdx);
            if (!el) return;
            const ledger = el.getAttribute('data-ledger-id');
            const ref = el.getAttribute('data-ref');
            window.location.href = cfg.billUrl.replace('__id__', ledger) + '?ref=' + encodeURIComponent(ref);
        },
        focusPeriod() {
            const el = document.querySelector('#report-to');
            if (el) el.focus();
        },
        onPeriodEnter() {
            const el = document.activeElement;
            if (el && el.id === 'report-to') {
                el.blur();
                this.$nextTick(() => {
                    const s = document.querySelector('#report-surface');
                    if (s) s.focus();
                });
                return true;
            }
            return false;
        },
    };
}

/* ---- Cost Centre Breakup — drill a centre to its vouchers ---------------- */
export function costBreakup(cfg) {
    return {
        cfg,
        activeIdx: 0,

        init() {
            this.activeIdx = this.firstDrillable();
            this.$store.zb.pushContext({
                name: 'cost-breakup',
                label: cfg.title || 'Cost Centre Breakup',
                focusEl: '#report-surface',
                actions: [
                    { key: 'arrowdown', label: 'Next', hint: '↓', run: () => this.move(1) },
                    { key: 'arrowup', label: 'Previous', hint: '↑', run: () => this.move(-1) },
                    { key: 'enter', label: 'Drill to voucher(s)', allowInInput: true, run: () => { if (!this.onPeriodEnter()) this.drill(); } },
                    { key: 'f2', label: 'Period', allowInInput: true, run: () => this.focusPeriod() },
                    // Alt+F2 is Tally's period key. Registered here so it does NOT
                    // fall through to the global handler, which only relabels the
                    // top bar and leaves the report period untouched — a key that
                    // looks like it worked and did nothing.
                    { key: 'alt+f2', label: 'Period', allowInInput: true, run: () => this.focusPeriod() },
                ],
                onEsc: () => (window.location.href = cfg.gatewayUrl),
            });
            this.$nextTick(() => {
                const el = document.querySelector('#report-surface');
                if (el) el.focus();
            });
        },
        rowEls() {
            return Array.from(document.querySelectorAll('[data-cc-row]'));
        },
        firstDrillable() {
            const els = this.rowEls();
            for (let i = 0; i < els.length; i++) if (els[i].getAttribute('data-cc-id')) return i;
            return 0;
        },
        inPeriodInput() {
            const el = document.activeElement;
            return el && (el.id === 'report-from' || el.id === 'report-to');
        },
        move(d) {
            if (this.inPeriodInput()) return;
            const els = this.rowEls();
            if (!els.length) return;
            let i = this.activeIdx;
            for (let step = 0; step < els.length; step++) {
                i = Math.max(0, Math.min(els.length - 1, i + d));
                if (els[i].getAttribute('data-cc-id')) break;
                if (i === 0 || i === els.length - 1) break;
            }
            this.activeIdx = i;
            const el = els[i];
            if (el && el.scrollIntoView) el.scrollIntoView({ block: 'nearest' });
        },
        drill() {
            const el = this.rowEls()[this.activeIdx];
            const id = el && el.getAttribute('data-cc-id');
            if (!id) return;
            let u = cfg.drillUrl.replace('__id__', id);
            const q = [];
            if (cfg.from) q.push('from=' + encodeURIComponent(cfg.from));
            if (cfg.to) q.push('to=' + encodeURIComponent(cfg.to));
            window.location.href = u + (q.length ? '?' + q.join('&') : '');
        },
        focusPeriod() {
            const el = document.querySelector('#report-from');
            if (el) el.focus();
        },
        onPeriodEnter() {
            const el = document.activeElement;
            if (el && (el.id === 'report-from' || el.id === 'report-to')) {
                el.blur();
                this.$nextTick(() => {
                    const s = document.querySelector('#report-surface');
                    if (s) s.focus();
                });
                return true;
            }
            return false;
        },
    };
}

/* ---------------------------------------------------------------------------
   TDS Deduction Summary (Phase 10A). A flat two-level list — section, then the
   deductees under it — where only the deductee rows drill. The drill needs BOTH
   ids (a deductee may be paid under several sections, and a section pays many
   deductees), so the URL carries two placeholders rather than one.
   --------------------------------------------------------------------------- */
export function tdsSummary(cfg) {
    return {
        cfg,
        activeIdx: 0,

        init() {
            this.activeIdx = this.firstDrillable();
            this.$store.zb.pushContext({
                name: 'tds-summary',
                label: cfg.title || 'TDS Deduction Summary',
                focusEl: '#report-surface',
                actions: [
                    { key: 'arrowdown', label: 'Next', hint: '↓', run: () => this.move(1) },
                    { key: 'arrowup', label: 'Previous', hint: '↑', run: () => this.move(-1) },
                    { key: 'enter', label: 'Drill to voucher(s)', allowInInput: true, run: () => { if (!this.onPeriodEnter()) this.drill(); } },
                    { key: 'f2', label: 'Period', allowInInput: true, run: () => this.focusPeriod() },
                    // Alt+F2 is Tally's period key. Registered here so it does NOT
                    // fall through to the global handler, which only relabels the
                    // top bar and leaves the report period untouched — a key that
                    // looks like it worked and did nothing.
                    { key: 'alt+f2', label: 'Period', allowInInput: true, run: () => this.focusPeriod() },
                ],
                onEsc: () => (window.location.href = cfg.gatewayUrl),
            });
            this.$nextTick(() => {
                const el = document.querySelector('#report-surface');
                if (el) el.focus();
            });
        },
        rowEls() {
            return Array.from(document.querySelectorAll('[data-tds-row]'));
        },
        isDrillable(el) {
            return !!(el && el.getAttribute('data-tds-deductee'));
        },
        firstDrillable() {
            const els = this.rowEls();
            for (let i = 0; i < els.length; i++) if (this.isDrillable(els[i])) return i;
            return 0;
        },
        inPeriodInput() {
            const el = document.activeElement;
            return el && (el.id === 'report-from' || el.id === 'report-to');
        },
        move(d) {
            if (this.inPeriodInput()) return;
            const els = this.rowEls();
            if (!els.length) return;
            let i = this.activeIdx;
            for (let step = 0; step < els.length; step++) {
                i = Math.max(0, Math.min(els.length - 1, i + d));
                if (this.isDrillable(els[i])) break;
                if (i === 0 || i === els.length - 1) break;
            }
            this.activeIdx = i;
            const el = els[i];
            if (el && el.scrollIntoView) el.scrollIntoView({ block: 'nearest' });
        },
        drill() {
            const el = this.rowEls()[this.activeIdx];
            if (!this.isDrillable(el)) return;
            const u = cfg.drillUrl
                .replace('__sid__', el.getAttribute('data-tds-section'))
                .replace('__did__', el.getAttribute('data-tds-deductee'));
            const q = [];
            if (cfg.from) q.push('from=' + encodeURIComponent(cfg.from));
            if (cfg.to) q.push('to=' + encodeURIComponent(cfg.to));
            window.location.href = u + (q.length ? '?' + q.join('&') : '');
        },
        focusPeriod() {
            const el = document.querySelector('#report-from');
            if (el) el.focus();
        },
        onPeriodEnter() {
            const el = document.activeElement;
            if (el && (el.id === 'report-from' || el.id === 'report-to')) {
                el.blur();
                this.$nextTick(() => {
                    const s = document.querySelector('#report-surface');
                    if (s) s.focus();
                });
                return true;
            }
            return false;
        },
    };
}

/* ---- Phase 12C-2 — group consolidation reports ---------------------------
   Server-rendered rows; arrows move the active section, Enter toggles its
   per-company detail rows, F2 focuses the period. One controller, three
   screens (Group TB / BS / P&L). */
export function groupReport() {
    return {
        active: 0,
        init() {
            this.$store.zb.pushContext({
                name: 'group-report',
                label: 'Group Report',
                focusEl: null,
                actions: [
                    { key: 'arrowdown', label: 'Next', hint: '\u2193', run: () => this.move(1) },
                    { key: 'arrowup', label: 'Previous', hint: '\u2191', run: () => this.move(-1) },
                    // allowInInput so Enter/F2 still reach us in the group select and
                    // date fields; inField() then hands the key back to the field.
                    { key: 'enter', label: 'Expand', allowInInput: true, run: () => { if (!this.inField()) this.toggle(); } },
                    { key: 'f2', label: 'Period', allowInInput: true, run: () => { const el = document.querySelector('#gr-from'); if (el) el.focus(); } },
                    // Alt+F2 is Tally's period key. Registered here so it does NOT
                    // fall through to the global handler, which only relabels the
                    // top bar and leaves the report period untouched — a key that
                    // looks like it worked and did nothing.
                    { key: 'alt+f2', label: 'Period', allowInInput: true, run: () => this.focusPeriod() },
                ],
                onEsc: () => (window.location.href = (window.ZB_CONFIG || {}).urls?.gateway || '/app'),
            });
            this.paint();
        },
        rows() {
            return [...this.$root.querySelectorAll('[data-gr-row]')];
        },
        // The group select and the two date inputs must keep their own arrow/Enter
        // keys (the sibling reports' inPeriodInput discipline).
        inField() {
            const el = document.activeElement;
            return !!el && (el.id === 'gr-group' || el.id === 'gr-from' || el.id === 'gr-to');
        },
        move(d) {
            if (this.inField()) return;
            const n = this.rows().length;
            if (!n) return;
            this.active = (this.active + d + n) % n;
            this.paint();
        },
        paint() {
            this.rows().forEach((r, i) => r.classList.toggle('is-active', i === this.active));
            const el = this.rows()[this.active];
            if (el && el.scrollIntoView) el.scrollIntoView({ block: 'nearest' });
        },
        toggle() {
            const row = this.rows()[this.active];
            if (!row) return;
            const key = row.dataset.grRow;
            this.$root.querySelectorAll('[data-gr-child-of="' + key + '"]').forEach((c) => {
                c.style.display = c.style.display === 'none' ? '' : 'none';
            });
        },
    };
}

export function registerReports(Alpine) {
    Alpine.data('groupReport', groupReport);
    Alpine.data('reportScreen', reportScreen);
    Alpine.data('ledgerVouchers', ledgerVouchers);
    Alpine.data('gstSummary', gstSummary);
    Alpine.data('outstandings', outstandings);
    Alpine.data('costBreakup', costBreakup);
    Alpine.data('tdsSummary', tdsSummary);
}
