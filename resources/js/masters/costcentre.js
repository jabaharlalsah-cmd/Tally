/* =========================================================================
   ZeroBook — Cost Centre master controller (Alpine 'costCentreWorkspace')
   Phase 5D. A standalone twin of masterWorkspace (groups/ledgers), so the shared
   master controller is untouched. Modes (menu → create/multiple/display/alter)
   are pushed engine contexts; the cost-centre cache lives in the masters store;
   the server is touched only on commit.
   ========================================================================= */

import { ensureElementVisible } from '../engine/scroll.js';

export function costCentreWorkspace(cfg) {
    return {
        cfg,
        mode: 'menu',
        menuActive: 0,
        listActive: 0,
        listQuery: '',
        confirming: null,
        flash: '',
        _flashKind: 'ok',

        menuItems: [
            { letter: 'c', label: 'Create', mode: 'create', desc: 'Add a single cost centre' },
            { letter: 'm', label: 'Create Multiple', mode: 'multi', desc: 'Fast grid entry' },
            { letter: 'd', label: 'Display', mode: 'display', desc: 'View (read-only)' },
            { letter: 'a', label: 'Alter', mode: 'alter', desc: 'Edit an existing cost centre' },
        ],

        init() {
            this.$store.masters.seedCostCentres(cfg.costCentres || []);
            this.$store.zb.pushContext({
                name: 'masters.costcentre',
                label: 'Cost Centres',
                focusEl: '#ws-menu',
                actions: this.menuActions(),
                onEsc: () => (window.location.href = cfg.hubUrl),
            });
            const m = new URLSearchParams(window.location.search).get('mode');
            if (m && ['create', 'multi', 'display', 'alter'].includes(m)) {
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

        get list() {
            let l = this.$store.masters.costCentres || [];
            const q = this.listQuery.trim().toLowerCase();
            if (q) l = l.filter((x) => x.name.toLowerCase().includes(q) || (x.path && x.path.toLowerCase().includes(q)));
            return l;
        },
        get current() {
            return this.list[this.listActive] || null;
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

        enterMode(m) {
            this.mode = m;
            this.listActive = 0;
            this.listQuery = '';
            if (m === 'create') {
                this.$store.zb.pushContext({ name: 'master.create', label: 'Create Cost Centre', focusEl: '#cc-name', actions: [], onPop: () => (this.mode = 'menu') });
            } else if (m === 'multi') {
                this.$store.zb.pushContext({
                    name: 'master.multi', label: 'Multiple Cost Centres', focusEl: '#ccm-r0-name',
                    actions: [
                        { key: 'arrowdown', label: 'Row down', hint: '↓', run: () => this.moveGrid(1) },
                        { key: 'arrowup', label: 'Row up', hint: '↑', run: () => this.moveGrid(-1) },
                    ],
                    onPop: () => (this.mode = 'menu'),
                });
            } else if (m === 'display' || m === 'alter') {
                this.$store.zb.pushContext({
                    name: 'master.' + m, label: (m === 'display' ? 'Display ' : 'Alter ') + 'Cost Centre', focusEl: '#ws-list-search',
                    actions: [
                        { key: 'arrowdown', label: 'Next', hint: '↓', run: () => this.listMove(1) },
                        { key: 'arrowup', label: 'Previous', hint: '↑', run: () => this.listMove(-1) },
                        { key: 'enter', label: m === 'alter' ? 'Alter' : 'Details', run: () => (m === 'alter' ? this.openAlter(this.current) : null) },
                        { key: 'alt+d', label: 'Delete', run: () => this.askDelete(this.current) },
                    ],
                    onPop: () => (this.mode = 'menu'),
                });
            }
        },
        backToMenu() {
            if (this.$store.zb.activeName().startsWith('master.')) this.$store.zb.popContext();
        },
        closePickers() {
            let guard = 0;
            while (this.$store.zb.activeName().startsWith('combo:') && guard++ < 6) this.$store.zb.popContext();
        },

        async saveCreate() {
            this.closePickers();
            try {
                const rec = await this.$wire.saveSingle();
                if (!rec) return;
                this.$store.masters.addCostCentre(rec);
                this.flashMsg('✔ ' + rec.name + ' created');
                this.$store.zb.note('Saved cost centre: ' + rec.name, 'commit');
                this.$nextTick(() => { const el = document.querySelector('#cc-name'); if (el) el.focus(); });
            } catch (e) {
                /* inline errors */
            }
        },

        moveGrid(dir) {
            const cell = document.activeElement;
            if (!cell || !cell.dataset || cell.dataset.zbRow == null) return;
            const row = parseInt(cell.dataset.zbRow, 10) + dir;
            const target = document.querySelector('[data-zb-row="' + row + '"][data-zb-col="name"]');
            if (target) {
                target.focus();
                if (target.select) { try { target.select(); } catch (_) {} }
            }
        },
        async saveMulti() {
            this.closePickers();
            try {
                const recs = await this.$wire.saveMulti();
                if (Array.isArray(recs)) {
                    recs.forEach((r) => this.$store.masters.addCostCentre(r));
                    this.$store.zb.note('Saved ' + recs.length + ' cost centre(s)', 'commit');
                }
                this.backToMenu();
            } catch (e) {
                /* inline errors */
            }
        },

        async openAlter(item) {
            if (!item) return;
            await this.$wire.loadForAlter(item.id);
            this.mode = 'alter-form';
            this.$store.zb.pushContext({ name: 'master.alter-form', label: 'Alter: ' + item.name, focusEl: '#cca-name', actions: [], onPop: () => (this.mode = 'alter') });
        },
        async saveAlter() {
            try {
                const rec = await this.$wire.saveAlter();
                if (!rec) return;
                this.$store.masters.addCostCentre(rec);
                this.$store.zb.note('Altered cost centre: ' + rec.name, 'commit');
                this.$store.zb.popToContext('master.alter-form');
            } catch (e) {
                /* inline errors */
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
                    this.$store.masters.removeCostCentre(item.id);
                    this.flashMsg('✔ Deleted ' + item.name);
                    this.$store.zb.note('Deleted cost centre: ' + item.name, 'commit');
                    this.listActive = 0;
                } else {
                    this.flashMsg((res && res.message) || 'Could not delete.', 'warn');
                }
            } catch (e) {
                this.flashMsg('Could not delete.', 'warn');
            }
        },

        flashMsg(msg, kind) {
            this.flash = msg;
            this._flashKind = kind || 'ok';
            clearTimeout(this._flashT);
            this._flashT = setTimeout(() => (this.flash = ''), 2600);
        },
    };
}

export function registerCostCentre(Alpine) {
    Alpine.data('costCentreWorkspace', costCentreWorkspace);
}
