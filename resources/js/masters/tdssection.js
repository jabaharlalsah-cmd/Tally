/* =========================================================================
   ZeroBook — TDS Sections master controller (Alpine 'tdsSectionWorkspace')
   Phase 10A. A standalone twin of costCentreWorkspace, so the shared master
   controller is untouched. Modes (menu → create/display/alter) are pushed
   engine contexts; the section cache lives in the masters store; the server
   is touched only on commit.

   This screen is what keeps the TDS engine future-proof: when the Finance Act
   changes a rate, a threshold, or a section code, the user edits or expires a
   row here. Nothing in the engine hardcodes either.
   ========================================================================= */

import { ensureElementVisible } from '../engine/scroll.js';

export function tdsSectionWorkspace(cfg) {
    return {
        cfg,
        mode: 'menu',
        menuActive: 0,
        listActive: 0,
        listQuery: '',
        showExpired: false,
        confirming: null,
        expiring: null,
        flash: '',
        _flashKind: 'ok',

        menuItems: [
            { letter: 'c', label: 'Create', mode: 'create', desc: 'Add a TDS section' },
            { letter: 'd', label: 'Display', mode: 'display', desc: 'View the rate table (read-only)' },
            { letter: 'a', label: 'Alter', mode: 'alter', desc: 'Edit a rate, a threshold, or expire a section' },
        ],

        init() {
            this.$store.masters.seedInventory('tdsSections', cfg.sections || []);
            this.$store.zb.pushContext({
                name: 'masters.tdssection',
                label: 'TDS Sections',
                focusEl: '#ws-menu',
                actions: this.menuActions(),
                onEsc: () => (window.location.href = cfg.hubUrl),
            });
            const m = new URLSearchParams(window.location.search).get('mode');
            if (m && ['create', 'display', 'alter'].includes(m)) {
                this.$nextTick(() => this.enterMode(m));
            }
        },

        menuActions() {
            const acts = [
                { key: 'arrowdown', label: 'Next', hint: '↓', run: () => this.menuMove(1) },
                { key: 'arrowup', label: 'Previous', hint: '↑', run: () => this.menuMove(-1) },
                { key: 'enter', label: 'Open', run: () => this.enterMode(this.menuItems[this.menuActive].mode) },
            ];
            this.menuItems.forEach((it, i) => {
                acts.push({ key: it.letter, label: it.label, hint: it.letter.toUpperCase(), hidden: true, run: () => { this.menuActive = i; this.enterMode(it.mode); } });
            });
            return acts;
        },
        menuMove(d) {
            const n = this.menuItems.length;
            this.menuActive = (this.menuActive + d + n) % n;
        },
        hotSplit(it) {
            const i = it.label.toLowerCase().indexOf(it.letter.toLowerCase());
            if (i < 0) return { pre: it.label, hot: '', post: '' };
            return { pre: it.label.slice(0, i), hot: it.label.slice(i, i + 1), post: it.label.slice(i + 1) };
        },

        // The catalog carries both eras — the repealed 194-series and the Section 393
        // sub-provisions that replaced them. Out-of-force rows are hidden by default so
        // the list reads as "what applies now", and revealed on demand for the history.
        get list() {
            let l = this.$store.masters.tdsSections || [];
            if (!this.showExpired) l = l.filter((x) => x.in_force);
            const q = this.listQuery.trim().toLowerCase();
            if (q) l = l.filter((x) => x.name.toLowerCase().includes(q) || (x.path && x.path.toLowerCase().includes(q)));
            return l;
        },
        get current() {
            return this.list[this.listActive] || null;
        },
        get inForceCount() {
            return (this.$store.masters.tdsSections || []).filter((x) => x.in_force).length;
        },
        get expiredCount() {
            return (this.$store.masters.tdsSections || []).filter((x) => !x.in_force).length;
        },
        listMove(d) {
            const n = this.list.length;
            if (!n) return;
            this.listActive = Math.max(0, Math.min(n - 1, this.listActive + d));
            this.$nextTick(() => {
                const el = document.querySelector('#ws-list .is-active');
                if (!el) return;
                ensureElementVisible(el, { block: 'nearest', inline: 'nearest' });
                requestAnimationFrame(() => ensureElementVisible(el, { block: 'nearest', inline: 'nearest' }));
            });
        },
        onListQuery() {
            this.listActive = 0;
        },
        toggleExpired() {
            this.showExpired = !this.showExpired;
            this.listActive = 0;
        },

        enterMode(m) {
            this.mode = m;
            this.listActive = 0;
            this.listQuery = '';
            if (m === 'create') {
                this.$store.zb.pushContext({ name: 'master.create', label: 'Create TDS Section', focusEl: '#ts-code', actions: [], onPop: () => (this.mode = 'menu') });
            } else if (m === 'display' || m === 'alter') {
                this.$store.zb.pushContext({
                    name: 'master.' + m,
                    label: (m === 'display' ? 'Display ' : 'Alter ') + 'TDS Section',
                    focusEl: '#ws-list-search',
                    actions: [
                        { key: 'arrowdown', label: 'Next', hint: '↓', run: () => this.listMove(1) },
                        { key: 'arrowup', label: 'Previous', hint: '↑', run: () => this.listMove(-1) },
                        { key: 'enter', label: m === 'alter' ? 'Alter' : 'Details', run: () => (m === 'alter' ? this.openAlter(this.current) : null) },
                        { key: 'alt+e', label: 'Expire section', run: () => this.askExpire(this.current) },
                        { key: 'alt+h', label: this.showExpired ? 'Hide repealed' : 'Show repealed', run: () => this.toggleExpired() },
                        { key: 'alt+d', label: 'Delete', run: () => this.askDelete(this.current) },
                    ],
                    onPop: () => (this.mode = 'menu'),
                });
            }
        },
        backToMenu() {
            if (this.$store.zb.activeName().startsWith('master.')) this.$store.zb.popContext();
        },

        async saveCreate() {
            try {
                const rec = await this.$wire.saveSingle();
                if (!rec) return;
                await this.refresh();
                this.flashMsg('✔ ' + rec.code + ' created');
                this.$store.zb.note('Saved TDS section: ' + rec.code, 'commit');
                this.$nextTick(() => { const el = document.querySelector('#ts-code'); if (el) el.focus(); });
            } catch (e) {
                /* inline errors */
            }
        },

        async openAlter(item) {
            if (!item) return;
            await this.$wire.loadForAlter(item.id);
            this.mode = 'alter-form';
            this.$store.zb.pushContext({ name: 'master.alter-form', label: 'Alter: ' + item.code, focusEl: '#tsa-code', actions: [], onPop: () => (this.mode = 'alter') });
        },
        async saveAlter() {
            try {
                const rec = await this.$wire.saveAlter();
                if (!rec) return;
                await this.refresh();
                this.$store.zb.note('Altered TDS section: ' + rec.code, 'commit');
                this.$store.zb.popToContext('master.alter-form');
            } catch (e) {
                /* inline errors */
            }
        },

        // Expiring is the RIGHT way to retire a repealed section: the vouchers that
        // already deducted under it keep pointing at it, and no future one can.
        askExpire(item) {
            if (!item) return;
            this.expiring = item;
            this.$store.zb.pushContext({
                name: 'master.confirm', label: 'Expire section', focusEl: null,
                actions: [
                    { key: 'enter', label: 'Yes, expire', run: () => this.doExpire() },
                    { key: 'y', label: 'Yes', hidden: true, run: () => this.doExpire() },
                ],
                onPop: () => (this.expiring = null),
            });
        },
        async doExpire() {
            const item = this.expiring;
            if (this.$store.zb.activeName() === 'master.confirm') this.$store.zb.popContext();
            if (!item) return;
            const res = await this.$wire.expireSection(item.id);
            if (res && res.ok) {
                await this.refresh();
                this.flashMsg('✔ ' + res.message);
                this.$store.zb.note(res.message, 'commit');
            } else {
                this.flashMsg((res && res.message) || 'Could not expire.', 'warn');
            }
        },

        askDelete(item) {
            if (!item) return;
            this.confirming = item;
            this.$store.zb.pushContext({
                name: 'master.confirm', label: 'Confirm delete', focusEl: null,
                actions: [
                    { key: 'enter', label: 'Yes, delete', run: () => this.doDelete() },
                    { key: 'y', label: 'Yes', hidden: true, run: () => this.doDelete() },
                ],
                onPop: () => (this.confirming = null),
            });
        },
        async doDelete() {
            const item = this.confirming;
            if (this.$store.zb.activeName() === 'master.confirm') this.$store.zb.popContext();
            if (!item) return;
            try {
                const res = await this.$wire.deleteMaster(item.id);
                if (res && res.ok) {
                    this.$store.masters.removeFrom('tdsSections', item.id);
                    this.flashMsg('✔ Deleted ' + item.code);
                    this.$store.zb.note('Deleted TDS section: ' + item.code, 'commit');
                    this.listActive = 0;
                } else {
                    this.flashMsg((res && res.message) || 'Could not delete.', 'warn');
                }
            } catch (e) {
                this.flashMsg('Could not delete.', 'warn');
            }
        },

        // A section's derived fields (in_force, usage, labels) come from the server, so
        // a create/alter/expire re-reads the catalog rather than guessing them client-side.
        async refresh() {
            const rows = await this.$wire.sectionCache();
            if (Array.isArray(rows)) this.$store.masters.seedInventory('tdsSections', rows);
        },

        flashMsg(msg, kind) {
            this.flash = msg;
            this._flashKind = kind || 'ok';
            clearTimeout(this._flashT);
            this._flashT = setTimeout(() => (this.flash = ''), 3200);
        },
    };
}

export function registerTdsSections(Alpine) {
    Alpine.data('tdsSectionWorkspace', tdsSectionWorkspace);
}
