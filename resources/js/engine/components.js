/* =========================================================================
   ZeroBook keyboard engine — surface components (Alpine.data)
   Each component owns its screen and pushes/pops its own context on the
   shared stack, so the single dispatcher routes keys to the right handlers.
   ========================================================================= */

import { calculate } from './calculator.js';
import { ensureElementVisible } from './scroll.js';

/* ---- Gateway hub: highlighted-letter menu navigation -------------------- */
function zbGateway(config) {
    return {
        items: (config && config.items) || [],
        active: 0,

        init() {
            const acts = [
                { key: 'arrowdown', label: 'Next', hint: '↓', run: () => this.move(1) },
                { key: 'arrowup', label: 'Previous', hint: '↑', run: () => this.move(-1) },
                { key: 'enter', label: 'Select', run: () => this.choose(this.active) },
            ];
            // one hot-letter binding per menu item
            this.items.forEach((it, i) => {
                acts.push({
                    key: it.letter.toLowerCase(),
                    label: it.label,
                    hint: it.letter.toUpperCase(),
                    hidden: true,
                    run: () => {
                        this.active = i;
                        this.choose(i);
                    },
                });
            });
            this.$store.zb.pushContext({
                name: (config && config.name) || 'gateway',
                label: (config && config.label) || 'Gateway of ZeroBook',
                actions: acts,
                focusEl: (config && config.focusEl) || '#zb-gateway',
                onEsc: config && config.hubUrl ? () => (window.location.href = config.hubUrl) : null,
            });
        },
        move(delta) {
            const n = this.items.length;
            this.active = (this.active + delta + n) % n;
            this.$nextTick(() => this.scrollActive());
        },
        scrollActive() {
            const root = this.$refs && this.$refs.list ? this.$refs.list : (this.$el || null);
            const el = root && root.querySelector ? root.querySelector('.is-active') : null;
            if (!el) return;
            ensureElementVisible(el, { block: 'nearest', inline: 'nearest' });
            requestAnimationFrame(() => ensureElementVisible(el, { block: 'nearest', inline: 'nearest' }));
        },
        choose(i) {
            const it = this.items[i];
            if (!it) return;
            this.$store.zb.note('Gateway ▸ ' + it.label, 'flow');
            if (it.kind === 'nav' && it.href) {
                window.location.href = it.href;
            } else if (it.act === 'goto') {
                this.$store.zb.emit('zb:open-goto');
            } else if (it.act === 'calc') {
                this.$store.zb.emit('zb:toggle-calc');
            } else if (it.act === 'period') {
                this.$store.zb.emit('zb:open-period');
            }
        },
        hotLabel(it) {
            // returns { pre, hot, post } splitting label around its hot letter
            const idx = it.label.toLowerCase().indexOf(it.letter.toLowerCase());
            if (idx < 0) return { pre: it.label, hot: '', post: '' };
            return {
                pre: it.label.slice(0, idx),
                hot: it.label.slice(idx, idx + 1),
                post: it.label.slice(idx + 1),
            };
        },
    };
}

/* ---- Calculator pane (Ctrl+N) ------------------------------------------ */
function zbCalc() {
    return {
        init() {
            this._onToggle = () => this.toggle();
            window.addEventListener('zb:toggle-calc', this._onToggle);
        },
        destroy() {
            window.removeEventListener('zb:toggle-calc', this._onToggle);
        },
        get calc() {
            return this.$store.zb.calc;
        },
        toggle() {
            if (this.calc.open) this.close();
            else this.open();
        },
        open() {
            this.calc.open = true;
            this.calc.error = '';
            this.$store.zb.rev++;
            this.$store.zb.pushContext({
                name: 'calc',
                label: 'Calculator',
                focusEl: '#zb-calc-input',
                actions: [
                    { key: 'enter', label: 'Add to tape', run: () => this.commit() },
                    { key: 'ctrl+n', label: 'Close', run: () => this.close() },
                    { key: 'alt+n', label: 'Close', hidden: true, run: () => this.close() },
                ],
                onPop: () => {
                    this.calc.open = false;
                    this.$store.zb.rev++;
                },
            });
        },
        close() {
            if (this.$store.zb.activeName() === 'calc') this.$store.zb.popContext();
            else {
                this.calc.open = false;
                this.$store.zb.rev++;
            }
        },
        onInput() {
            const r = calculate(this.calc.expr);
            if (r.ok) {
                this.calc.result = this.format(r.value);
                this.calc.error = '';
            } else {
                this.calc.result = '';
                this.calc.error = r.error;
            }
        },
        commit() {
            const r = calculate(this.calc.expr);
            if (r.ok) {
                this.calc.tape.unshift({ expr: this.calc.expr, value: this.format(r.value) });
                this.calc.tape = this.calc.tape.slice(0, 20);
                this.calc.result = this.format(r.value);
                this.calc.error = '';
                this.$store.zb.note('Calc = ' + this.calc.result, 'calc');
            } else {
                this.calc.error = r.error || 'Invalid expression';
            }
        },
        format(v) {
            return String(v);
        },
    };
}

/* ---- Go To universal navigator (Alt+G) --------------------------------- */
function zbGoto() {
    return {
        query: '',
        active: 0,
        dest: [],
        init() {
            this.dest = Array.isArray(window.ZB_NAV) ? window.ZB_NAV : [];
            this._onOpen = () => this.open();
            window.addEventListener('zb:open-goto', this._onOpen);
        },
        destroy() {
            window.removeEventListener('zb:open-goto', this._onOpen);
        },
        get open_() {
            return this.$store.zb.goto.open;
        },
        get filtered() {
            const q = this.query.trim().toLowerCase();
            if (!q) return this.dest;
            return this.dest.filter(
                (d) =>
                    d.label.toLowerCase().includes(q) ||
                    (d.sub && d.sub.toLowerCase().includes(q)) ||
                    (d.keywords && d.keywords.toLowerCase().includes(q))
            );
        },
        open() {
            if (this.$store.zb.goto.open) return;
            this.query = '';
            this.active = 0;
            this.$store.zb.goto.open = true;
            this.$store.zb.rev++;
            this.$store.zb.pushContext({
                name: 'goto',
                label: 'Go To',
                focusEl: '#zb-goto-input',
                actions: [
                    { key: 'arrowdown', label: 'Next', hint: '↓', run: () => this.move(1) },
                    { key: 'arrowup', label: 'Previous', hint: '↑', run: () => this.move(-1) },
                    { key: 'enter', label: 'Open', run: () => this.select() },
                ],
                onPop: () => {
                    this.$store.zb.goto.open = false;
                    this.$store.zb.rev++;
                },
            });
        },
        close() {
            if (this.$store.zb.activeName() === 'goto') this.$store.zb.popContext();
        },
        move(delta) {
            const n = this.filtered.length;
            if (!n) return;
            this.active = (this.active + delta + n) % n;
            this.$nextTick(() => this.scrollActive());
        },
        onQuery() {
            this.active = 0;
        },
        scrollActive() {
            const el = this.$refs.list && this.$refs.list.querySelector('.is-active');
            if (!el) return;
            ensureElementVisible(el, { block: 'nearest', inline: 'nearest' });
            requestAnimationFrame(() => ensureElementVisible(el, { block: 'nearest', inline: 'nearest' }));
        },
        select() {
            const d = this.filtered[this.active];
            if (!d) return;
            this.$store.zb.note('Go To ▸ ' + d.label, 'flow');
            this.close();
            if (d.kind === 'nav' && d.href) {
                window.location.href = d.href;
            } else if (d.act === 'calc') {
                this.$store.zb.emit('zb:toggle-calc');
            } else if (d.act === 'period') {
                this.$store.zb.emit('zb:open-period');
            } else if (d.act === 'company') {
                this.$store.zb.emit('zb:open-company'); // Phase 12A
            }
        },
    };
}

/* ---- Company picker (F3) — Phase 12A ------------------------------------
 * The Tally "Select Company" list: every active company in the tenant,
 * keyboard-navigable with instant filter-by-typing (a zbGoto clone). Enter
 * POSTs the switch, then does a FULL page load to the Gateway — every client
 * cache (ZB_CONFIG, masters store, Livewire snapshots) is a page-load snapshot
 * of the old company, so a fresh page under the new one is the only correct
 * invalidation. Open screens are discarded, exactly as Tally does it.       */
function zbCompanyPicker() {
    return {
        query: '',
        active: 0,
        companies: [],
        switching: false,
        init() {
            const cfg = window.ZB_CONFIG || {};
            this.companies = Array.isArray(cfg.companies) ? cfg.companies : [];
            this._onOpen = () => this.open();
            window.addEventListener('zb:open-company', this._onOpen);
        },
        destroy() {
            window.removeEventListener('zb:open-company', this._onOpen);
        },
        get currentId() {
            return (window.ZB_CONFIG || {}).activeCompanyId ?? null;
        },
        get filtered() {
            const q = this.query.trim().toLowerCase();
            if (!q) return this.companies;
            return this.companies.filter(
                (c) => c.name.toLowerCase().includes(q) || (c.slug && c.slug.toLowerCase().includes(q))
            );
        },
        open() {
            if (this.$store.zb.companyPicker.open || this.companies.length === 0) return;
            this.query = '';
            this.switching = false;
            // start on the ACTIVE company so Enter with no movement is a no-op switch
            this.active = Math.max(0, this.companies.findIndex((c) => c.id === this.currentId));
            this.$store.zb.companyPicker.open = true;
            this.$store.zb.rev++;
            this.$store.zb.pushContext({
                name: 'company-picker',
                label: 'Select Company',
                focusEl: '#zb-company-input',
                actions: [
                    { key: 'arrowdown', label: 'Next', hint: '↓', run: () => this.move(1) },
                    { key: 'arrowup', label: 'Previous', hint: '↑', run: () => this.move(-1) },
                    { key: 'enter', label: 'Select', run: () => this.select() },
                ],
                onPop: () => {
                    this.$store.zb.companyPicker.open = false;
                    this.$store.zb.rev++;
                },
            });
        },
        close() {
            if (this.$store.zb.activeName() === 'company-picker') this.$store.zb.popContext();
        },
        move(delta) {
            const n = this.filtered.length;
            if (!n) return;
            this.active = (this.active + delta + n) % n;
            this.$nextTick(() => this.scrollActive());
        },
        onQuery() {
            this.active = 0;
        },
        scrollActive() {
            const el = this.$refs.list && this.$refs.list.querySelector('.is-active');
            if (!el) return;
            ensureElementVisible(el, { block: 'nearest', inline: 'nearest' });
            requestAnimationFrame(() => ensureElementVisible(el, { block: 'nearest', inline: 'nearest' }));
        },
        async select() {
            const c = this.filtered[this.active];
            if (!c || this.switching) return;
            if (c.id === this.currentId) {
                this.close(); // already the active company — nothing to switch
                return;
            }
            this.switching = true;
            try {
                const cfg = window.ZB_CONFIG || {};
                const res = await fetch(cfg.urls.companySwitch, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    },
                    body: JSON.stringify({ company_id: c.id }),
                });
                const data = await res.json();
                if (data.ok && data.url) {
                    this.$store.zb.note('Company ▸ ' + c.name, 'flow');
                    window.location.href = data.url; // full reload — discards every stale cache
                } else {
                    // e.g. the company was deactivated after this page loaded — say so.
                    this.switching = false;
                    this.$store.zb.note((data && data.message) || 'Could not switch company — reload and try again.', 'warn');
                }
            } catch (e) {
                this.switching = false;
                this.$store.zb.note('Could not switch company — reload and try again.', 'warn');
            }
        },
    };
}

/* ---- Period / date picker (F2) — stub, full logic in Phase 4 ----------- */
function zbPeriod() {
    return {
        from: '',
        to: '',
        init() {
            this._onOpen = () => this.open();
            window.addEventListener('zb:open-period', this._onOpen);
        },
        destroy() {
            window.removeEventListener('zb:open-period', this._onOpen);
        },
        get open_() {
            return this.$store.zb.period.open;
        },
        open() {
            if (this.$store.zb.period.open) return;
            this.$store.zb.period.open = true;
            this.$store.zb.rev++;
            this.$store.zb.pushContext({
                name: 'period',
                label: 'Change Period',
                focusEl: '#zb-period-from',
                actions: [
                    { key: 'enter', label: 'Next / Apply', run: (e) => this.$store.zb.fieldAdvance(e) },
                    { key: 'ctrl+a', label: 'Accept', hidden: true, run: () => this.apply() },
                ],
                onPop: () => {
                    this.$store.zb.period.open = false;
                    this.$store.zb.rev++;
                },
            });
        },
        close() {
            if (this.$store.zb.activeName() === 'period') this.$store.zb.popContext();
        },
        apply() {
            if (this.from && this.to) {
                const f = this.fmt(this.from);
                const t = this.fmt(this.to);
                this.$store.zb.periodLabel = f + ' to ' + t;
                this.$store.zb.dateLabel = t;
                this.$store.zb.note('Period set: ' + this.$store.zb.periodLabel, 'flow');
            }
            this.close();
        },
        fmt(v) {
            // yyyy-mm-dd -> dd-Mon-yyyy (display only)
            const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(v);
            if (!m) return v;
            const mon = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            return m[3] + '-' + mon[parseInt(m[2], 10) - 1] + '-' + m[1];
        },
    };
}

export function registerComponents(Alpine) {
    Alpine.data('zbGateway', zbGateway);
    Alpine.data('zbCalc', zbCalc);
    Alpine.data('zbGoto', zbGoto);
    Alpine.data('zbCompanyPicker', zbCompanyPicker);
    Alpine.data('zbPeriod', zbPeriod);
}
