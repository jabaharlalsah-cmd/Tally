import { ensureElementVisible } from '../engine/scroll.js';

/* =========================================================================
   ZeroBook — Inventory masters controllers (Phase 6A)
   A generic 'inventoryWorkspace' (Units / Stock Groups / Godowns) plus a
   specialised 'stockItemWorkspace' that adds Alt+C inline-create of a Stock
   Group and a Unit, and a 0-network opening-value auto-compute. Mirrors the
   Phase 2 master workspace + the Phase 5D cost-centre workspace conventions:
   modes are pushed engine contexts, the client cache is the masters store, and
   the server is touched only on commit.
   ========================================================================= */

export function inventoryWorkspace(cfg) {
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
            { letter: 'c', label: 'Create', mode: 'create', desc: 'Add a single ' + cfg.kind.toLowerCase() },
            { letter: 'm', label: 'Create Multiple', mode: 'multi', desc: 'Fast grid entry' },
            { letter: 'd', label: 'Display', mode: 'display', desc: 'View (read-only)' },
            { letter: 'a', label: 'Alter', mode: 'alter', desc: 'Edit an existing ' + cfg.kind.toLowerCase() },
        ],

        init() {
            this.$store.masters.seedInventory(cfg.source, cfg.seed || []);
            this.pushMenuContext();
            const m = new URLSearchParams(window.location.search).get('mode');
            if (m && ['create', 'multi', 'display', 'alter'].includes(m)) {
                this.$nextTick(() => this.enterMode(m));
            }
        },

        pushMenuContext() {
            this.$store.zb.pushContext({
                name: 'inv.' + cfg.source,
                label: cfg.kind,
                focusEl: '#ws-menu',
                actions: this.menuActions(),
                onEsc: () => (window.location.href = cfg.hubUrl),
            });
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
            let l = this.$store.masters[cfg.source] || [];
            const q = this.listQuery.trim().toLowerCase();
            if (q) l = l.filter((x) => x.name.toLowerCase().includes(q) || (x.path && x.path.toLowerCase().includes(q)) || (x.group && x.group.toLowerCase().includes(q)));
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
                this.$store.zb.pushContext({ name: 'master.create', label: 'Create ' + cfg.kind, focusEl: cfg.createFirst, actions: [], onPop: () => (this.mode = 'menu') });
            } else if (m === 'multi') {
                this.$store.zb.pushContext({
                    name: 'master.multi', label: 'Multiple ' + cfg.kind, focusEl: cfg.multiFirst,
                    actions: [
                        { key: 'arrowdown', label: 'Row down', hint: '↓', run: () => this.moveGrid(1) },
                        { key: 'arrowup', label: 'Row up', hint: '↑', run: () => this.moveGrid(-1) },
                    ],
                    onPop: () => (this.mode = 'menu'),
                });
            } else if (m === 'display' || m === 'alter') {
                this.$store.zb.pushContext({
                    name: 'master.' + m, label: (m === 'display' ? 'Display ' : 'Alter ') + cfg.kind, focusEl: '#ws-list-search',
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
                this.$store.masters.addTo(cfg.source, rec);
                this.flashMsg('✔ ' + rec.name + ' created');
                this.$store.zb.note('Saved ' + cfg.kind + ': ' + rec.name, 'commit');
                this.afterCreate();
                this.$nextTick(() => { const el = document.querySelector(cfg.createFirst); if (el) el.focus(); });
            } catch (e) {
                /* inline errors */
            }
        },
        /** hook for a subclass (stock item resets its opening fields) */
        afterCreate() {},

        moveGrid(dir) {
            const cell = document.activeElement;
            if (!cell || !cell.dataset || cell.dataset.zbRow == null) return;
            const col = cell.dataset.zbCol || 'name';
            const row = parseInt(cell.dataset.zbRow, 10) + dir;
            const target = document.querySelector('[data-zb-row="' + row + '"][data-zb-col="' + col + '"]');
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
                    recs.forEach((r) => this.$store.masters.addTo(cfg.source, r));
                    this.$store.zb.note('Saved ' + recs.length + ' ' + cfg.kind + '(s)', 'commit');
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
            this.afterLoadAlter(item);
            this.$store.zb.pushContext({ name: 'master.alter-form', label: 'Alter: ' + item.name, focusEl: cfg.alterFirst, actions: [], onPop: () => (this.mode = 'alter') });
        },
        /** hook for a subclass (stock item pulls opening fields into local state) */
        afterLoadAlter(item) {},
        async saveAlter() {
            this.closePickers();
            try {
                const rec = await this.$wire.saveAlter();
                if (!rec) return;
                this.$store.masters.addTo(cfg.source, rec);
                this.$store.zb.note('Altered ' + cfg.kind + ': ' + rec.name, 'commit');
                this.$store.zb.popToContext('master.alter-form');
            } catch (e) {
                /* inline errors */
            }
        },

        askDelete(item) {
            if (!item) return;
            if (item.is_reserved) {
                this.flashMsg('“' + item.name + '” is a reserved master and cannot be deleted.', 'warn');
                return;
            }
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
                    this.$store.masters.removeFrom(cfg.source, item.id);
                    this.flashMsg('✔ Deleted ' + item.name);
                    this.$store.zb.note('Deleted ' + cfg.kind + ': ' + item.name, 'commit');
                    this.listActive = 0;
                } else {
                    this.flashMsg((res && res.message) || 'Could not delete.', 'warn');
                }
            } catch (e) {
                this.flashMsg('Could not delete.', 'warn');
            }
        },

        feature(k) {
            return !!(window.ZB_FEATURES && window.ZB_FEATURES[k]);
        },
        flashMsg(msg, kind) {
            this.flash = msg;
            this._flashKind = kind || 'ok';
            clearTimeout(this._flashT);
            this._flashT = setTimeout(() => (this.flash = ''), 2600);
        },
    };
}

/* ---- Stock Item: generic workspace + inline group/unit create + opening auto-value ---- */
export function stockItemWorkspace(cfg) {
    const base = inventoryWorkspace(cfg);
    return Object.assign(base, {
        showQuickStockGroup: false,
        showQuickUnit: false,
        pendingComboId: null,
        valueTouched: false, // create form: has the user overridden the opening value?
        alterValueTouched: false,
        _writingValue: false, // true while WE write the value (so the auto-write isn't mistaken for a user edit)

        init() {
            // Seed the caches the item form picks from (groups + units + godowns).
            this.$store.masters.seedInventory('stockGroups', cfg.stockGroups || []);
            this.$store.masters.seedInventory('units', cfg.units || []);
            this.$store.masters.seedInventory('godowns', cfg.godowns || []);
            this.$store.masters.seedInventory(cfg.source, cfg.seed || []);
            this.pushMenuContext();
            const m = new URLSearchParams(window.location.search).get('mode');
            if (m && ['create', 'multi', 'display', 'alter'].includes(m)) this.$nextTick(() => this.enterMode(m));

            this._onCreate = (e) => this.onComboCreate(e.detail);
            window.addEventListener('zb:combo-create', this._onCreate);
        },
        destroy() {
            window.removeEventListener('zb:combo-create', this._onCreate);
        },

        /* opening qty × rate = value (0-network; stops once the user edits value) */
        recomputeOpeningValue(qtySel, rateSel, valSel) {
            if (this.valueTouched) return;
            this.writeComputedValue(qtySel, rateSel, valSel);
        },
        recomputeAlterOpeningValue(qtySel, rateSel, valSel) {
            if (this.alterValueTouched) return;
            this.writeComputedValue(qtySel, rateSel, valSel);
        },
        writeComputedValue(qtySel, rateSel, valSel) {
            const q = parseFloat((document.querySelector(qtySel) || {}).value) || 0;
            const r = parseFloat((document.querySelector(rateSel) || {}).value) || 0;
            const el = document.querySelector(valSel);
            if (el) {
                // Flag the write so the value field's @input doesn't read it as a
                // manual override (which would freeze the auto-compute). dispatch is
                // synchronous, so clearing the flag right after is safe.
                this._writingValue = true;
                el.value = Math.round(q * r * 100) / 100;
                el.dispatchEvent(new Event('input')); // let wire:model capture it
                this._writingValue = false;
            }
        },
        /** Value field @input: a real user edit freezes the auto-compute. */
        markValueTouched() {
            if (!this._writingValue) this.valueTouched = true;
        },
        markAlterValueTouched() {
            if (!this._writingValue) this.alterValueTouched = true;
        },
        afterCreate() {
            this.valueTouched = false;
        },
        afterLoadAlter() {
            // A loaded item has an explicit value already; treat it as user-set so
            // editing qty/rate on alter doesn't silently overwrite it.
            this.alterValueTouched = true;
        },

        /* ---- Alt+C inline create (Stock Group / Unit) ---- */
        onComboCreate(detail) {
            if (detail.createType === 'stockgroup') this.openQuickStockGroup(detail);
            else if (detail.createType === 'unit') this.openQuickUnit(detail);
        },
        openQuickStockGroup(detail) {
            this.pendingComboId = detail.comboId;
            this.$wire.set('qsg_name', detail.name || '', false);
            this.$wire.set('qsg_parent_id', null, false);
            this.$wire.set('qsg_parent_label', '', false);
            this.showQuickStockGroup = true;
            this.$store.zb.pushContext({
                name: 'quick-stockgroup', label: 'Create Stock Group (inline)', focusEl: '#qsg-name', actions: [],
                onPop: () => (this.showQuickStockGroup = false),
            });
        },
        async saveQuickStockGroup() {
            try {
                const rec = await this.$wire.saveQuickStockGroup();
                if (!rec) return;
                this.$store.masters.addTo('stockGroups', rec);
                this.$store.zb.popToContext('quick-stockgroup');
                this.$store.zb.emit('zb:combo-fill', { comboId: this.pendingComboId, item: rec });
                this.$store.zb.note('Created stock group inline: ' + rec.name, 'commit');
                this.pendingComboId = null;
            } catch (e) {
                /* inline errors */
            }
        },
        openQuickUnit(detail) {
            this.pendingComboId = detail.comboId;
            this.$wire.set('qu_name', detail.name || '', false);
            this.$wire.set('qu_symbol', '', false);
            this.$wire.set('qu_decimal_places', '0', false);
            this.showQuickUnit = true;
            this.$store.zb.pushContext({
                name: 'quick-unit', label: 'Create Unit (inline)', focusEl: '#qu-name', actions: [],
                onPop: () => (this.showQuickUnit = false),
            });
        },
        async saveQuickUnit() {
            try {
                const rec = await this.$wire.saveQuickUnit();
                if (!rec) return;
                this.$store.masters.addTo('units', rec);
                this.$store.zb.popToContext('quick-unit');
                this.$store.zb.emit('zb:combo-fill', { comboId: this.pendingComboId, item: rec });
                this.$store.zb.note('Created unit inline: ' + rec.name, 'commit');
                this.pendingComboId = null;
            } catch (e) {
                /* inline errors */
            }
        },
    });
}

/* ---- Companies (Phase 12A): generic workspace + DEACTIVATE semantics --------
 * Alt+D deactivates (never deletes — accounting data is not deletable from a
 * list screen). The row stays in the cache with is_active=false so it can be
 * reactivated from the Alter form; the server refuses to deactivate the ACTIVE
 * company or the last active one. */
export function companyWorkspace(cfg) {
    const base = inventoryWorkspace(cfg);
    return Object.assign(base, {
        askDelete(item) {
            if (!item) return;
            this.confirming = item;
            this.$store.zb.pushContext({
                name: 'master.confirm', label: 'Confirm deactivate', focusEl: null,
                actions: [
                    { key: 'enter', label: 'Yes, deactivate', run: () => this.doDelete() },
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
                    if (res.record) this.$store.masters.addTo(cfg.source, res.record); // stays listed, inactive
                    this.flashMsg('✔ Deactivated ' + item.name);
                    this.$store.zb.note('Deactivated company: ' + item.name, 'commit');
                } else {
                    this.flashMsg((res && res.message) || 'Could not deactivate.', 'warn');
                }
            } catch (e) {
                this.flashMsg('Could not deactivate.', 'warn');
            }
        },
    });
}

export function registerInventory(Alpine) {
    Alpine.data('inventoryWorkspace', inventoryWorkspace);
    Alpine.data('stockItemWorkspace', stockItemWorkspace);
    Alpine.data('companyWorkspace', companyWorkspace); // Phase 12A
}
