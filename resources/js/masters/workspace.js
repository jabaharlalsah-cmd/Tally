import { registerDirty } from '../engine/keys.js';
import { ensureElementVisible } from '../engine/scroll.js';

/* =========================================================================
   ZeroBook — master workspace controller (Alpine 'masterWorkspace')
   Shared by the Groups and Ledgers screens. Orchestrates modes
   (menu → create / multiple / display / alter) as pushed engine contexts,
   list & grid keyboard navigation, delete-confirm, and the inline quick-create
   handshake with zbSelect. Field values themselves live in Livewire (wire:model,
   deferred) — the server is touched only on commit.

   cfg: { kind:'group'|'ledger', title, hubUrl,
          createFirst, multiFirst, alterFirst }
   ========================================================================= */

export function makeWorkspace(cfg) {
    const source = cfg.kind === 'group' ? 'groups' : 'ledgers';
    const Kind = cfg.kind === 'group' ? 'Group' : 'Ledger';
    /** Quote a name for the accept prompt, or fall back to a bare question. */
    const accepting = (name) => (name ? 'Accept “' + name + '”?' : 'Accept?');
    return {
        cfg,
        mode: 'menu',
        listActive: 0,
        listQuery: '',
        pendingComboId: null,
        showQuickGroup: false,
        confirming: null, // item pending delete confirmation
        flash: '',
        // Declared, not just assigned in flashMsg(): the flash's :class binding reads
        // it on every render — including the first, before any flash has fired — and
        // Alpine only resolves names present on the initial data object.
        _flashKind: 'ok',

        menuItems: [
            { letter: 'c', label: 'Create', mode: 'create', desc: 'Add a single ' + cfg.kind },
            { letter: 'm', label: 'Create Multiple', mode: 'multi', desc: 'Fast grid entry' },
            { letter: 'd', label: 'Display', mode: 'display', desc: 'View (read-only)' },
            { letter: 'a', label: 'Alter', mode: 'alter', desc: 'Edit an existing ' + cfg.kind },
        ],
        menuActive: 0,

        init() {
            this._onComboCreate = (e) => {
                if (!e.detail || e.detail.createType !== 'group') return;
                this.openQuickGroup(e.detail);
            };
            window.addEventListener('zb:combo-create', this._onComboCreate);

            // base menu context for this master screen
            this.$store.zb.pushContext({
                name: 'masters.' + cfg.kind,
                label: cfg.title,
                focusEl: '#ws-menu',
                actions: this.menuActions(),
                onEsc: () => (window.location.href = cfg.hubUrl),
            });

            // Unsaved-work guard (engine/keys.js). Registered here rather than
            // globally so only screens that can actually hold unsaved input arm
            // it — an inert probe would disable bfcache for the whole app.
            //
            // It is deliberately wired to NAVIGATION only, never to Esc: ~23
            // screens have an onEsc that assumes stepping back is unconditional,
            // and prompting there would change behaviour everywhere. Closing the
            // tab, reloading, or following the gear are the paths that silently
            // destroy work, and those are what this covers.
            this._offDirty = registerDirty('master.' + cfg.kind, () => this.hasUnsavedWork());

            // deep-link ?mode=create|multi|display|alter
            const m = new URLSearchParams(window.location.search).get('mode');
            if (m && ['create', 'multi', 'display', 'alter'].includes(m)) {
                this.$nextTick(() => this.enterMode(m));
            }
        },

        /** The visible form for the mode currently on screen. */
        activeForm() {
            if (!['create', 'multi', 'alter-form'].includes(this.mode)) return null;
            const forms = this.$el ? this.$el.querySelectorAll('[data-zb-form]') : [];

            return Array.from(forms).find((f) => f.offsetParent !== null) || null;
        },

        /**
         * Clear the dirty flag. Called on entering a form mode and after each
         * successful save.
         */
        markFormPristine() {
            this._touched = false;
        },

        /**
         * Is there input that would be lost by leaving the page?
         *
         * Keyed on whether the USER typed, not on what the DOM contains. Two
         * earlier attempts were wrong and both failed the same way — warning on
         * a form nobody had touched:
         *   - "any field is non-empty": Country ships pre-filled "India".
         *   - "values differ from a snapshot": Livewire hydrates the picker
         *     inputs after the snapshot is taken, so they always differed.
         *
         * event.isTrusted is the honest signal — true only for events the
         * browser generated from a real key press or click, false for anything
         * a framework dispatches. A warning that cries wolf on an untouched
         * screen is worse than none: it teaches people to click through the one
         * that matters.
         */
        hasUnsavedWork() {
            return !!this._touched && !!this.activeForm();
        },

        /** Bound to input/change on the workspace root (see the blade). */
        noteUserInput(e) {
            if (e && e.isTrusted) this._touched = true;
        },
        destroy() {
            window.removeEventListener('zb:combo-create', this._onComboCreate);
            // Drop the probe, or a torn-down screen keeps arming the unload
            // prompt for the rest of the session.
            if (this._offDirty) this._offDirty();
        },


        menuActions() {
            const acts = [
                { key: 'arrowdown', label: 'Next', hint: '↓', run: () => this.menuMove(1) },
                { key: 'arrowup', label: 'Previous', hint: '↑', run: () => this.menuMove(-1) },
                { key: 'enter', label: 'Open', run: () => this.enterMode(this.menuItems[this.menuActive].mode) },
            ];
            this.menuItems.forEach((it, i) => {
                acts.push({
                    key: it.letter,
                    label: it.label,
                    hint: it.letter.toUpperCase(),
                    hidden: true,
                    run: () => {
                        this.menuActive = i;
                        this.enterMode(it.mode);
                    },
                });
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

        /* ---- list (display/alter) ---- */
        get list() {
            let l = this.$store.masters[source] || [];
            const q = this.listQuery.trim().toLowerCase();
            if (q) {
                l = l.filter(
                    (x) =>
                        x.name.toLowerCase().includes(q) ||
                        (x.alias && x.alias.toLowerCase().includes(q)) ||
                        (x.path && x.path.toLowerCase().includes(q)) ||
                        (x.group && x.group.toLowerCase().includes(q))
                );
            }
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

        /* ---- mode switching ---- */
        enterMode(m) {
            this.mode = m;
            this.listActive = 0;
            this.listQuery = '';
            // Snapshot the form as first shown, so pre-filled defaults
            // (Country "India", Dr/Cr) do not read as unsaved work.
            this.markFormPristine();
            if (m === 'create') {
                this.$store.zb.pushContext({
                    name: 'master.create',
                    label: 'Create ' + cfg.title,
                    focusEl: cfg.createFirst,
                    actions: [],
                    onPop: () => (this.mode = 'menu'),
                });
            } else if (m === 'multi') {
                this.$store.zb.pushContext({
                    name: 'master.multi',
                    label: 'Multiple ' + cfg.title,
                    focusEl: cfg.multiFirst,
                    actions: [
                        { key: 'arrowdown', label: 'Row down', hint: '↓', run: () => this.moveGrid(1) },
                        { key: 'arrowup', label: 'Row up', hint: '↑', run: () => this.moveGrid(-1) },
                    ],
                    onPop: () => (this.mode = 'menu'),
                });
            } else if (m === 'display' || m === 'alter') {
                this.$store.zb.pushContext({
                    name: 'master.' + m,
                    label: (m === 'display' ? 'Display ' : 'Alter ') + cfg.title,
                    focusEl: '#ws-list-search',
                    actions: [
                        { key: 'arrowdown', label: 'Next', hint: '↓', run: () => this.listMove(1) },
                        { key: 'arrowup', label: 'Previous', hint: '↑', run: () => this.listMove(-1) },
                        {
                            key: 'enter',
                            label: m === 'alter' ? 'Alter' : 'Details',
                            run: () => (m === 'alter' ? this.openAlter(this.current) : null),
                        },
                        { key: 'alt+d', label: 'Delete', run: () => this.askDelete(this.current) },
                        {
                            key: 'alt+a',
                            label: 'Retire / Restore',
                            run: () => this.toggleActive(this.current),
                        },
                    ],
                    onPop: () => (this.mode = 'menu'),
                });
            }
        },
        backToMenu() {
            if (this.$store.zb.activeName().startsWith('master.')) this.$store.zb.popContext();
        },
        /** Pop any open combobox picker contexts sitting on top of the stack. */
        closePickers() {
            let guard = 0;
            while (this.$store.zb.activeName().startsWith('combo:') && guard++ < 6) {
                this.$store.zb.popContext();
            }
        },

        /* ---- create (single) ---- */
        saveCreate() {
            // Pickers first: the accept context has to be the top of the stack,
            // and it captures focus for restore on "No" — a combo still open
            // would be both stranded above it and the field we return to.
            this.closePickers();
            this.$store.zb.askAccept({
                title: Kind + ' Creation',
                body: accepting((this.$wire.get('name') || '').trim()),
                onYes: () => this.commitCreate(),
            });
        },
        async commitCreate() {
            try {
                const rec = await this.$wire.saveSingle();
                if (!rec) return;
                if (cfg.kind === 'group') this.$store.masters.addGroup(rec);
                else this.$store.masters.addLedger(rec);
                this.flashMsg('✔ ' + rec.name + ' created');
                this.$store.zb.note('Saved ' + cfg.kind + ': ' + rec.name, 'commit');
                // Through the engine's helper, which retries until the field is really
                // there: a bare focus() here raced Livewire's re-render and, when it
                // lost, left focus on <body> — where every keystroke is swallowed and
                // the screen looks frozen right after a successful save.
                this.$nextTick(() => this.$store.zb._focusInto(cfg.createFirst));
                this.markFormPristine();
            } catch (e) {
                /* validation errors render inline via @error */
            }
        },

        /* ---- multiple ---- */
        moveGrid(dir) {
            const cell = document.activeElement;
            if (!cell || !cell.dataset || cell.dataset.zbRow == null) return;
            const col = cell.dataset.zbCol;
            const row = parseInt(cell.dataset.zbRow, 10) + dir;
            const target = document.querySelector(
                '[data-zb-row="' + row + '"][data-zb-col="' + col + '"]'
            );
            if (target) {
                target.focus();
                if (target.select) {
                    try {
                        target.select();
                    } catch (_) {}
                }
            }
        },
        saveMulti() {
            this.closePickers();
            const n = (this.$wire.get('rows') || []).filter((r) => (r.name || '').trim() !== '').length;
            this.$store.zb.askAccept({
                title: 'Multi ' + Kind + ' Creation',
                // A row count is the only thing worth confirming on a grid, and
                // only when there is one — an empty grid is the server's to reject.
                body: n ? 'Accept ' + n + ' ' + Kind.toLowerCase() + (n === 1 ? '' : 's') + '?' : 'Accept?',
                onYes: () => this.commitMulti(),
            });
        },
        async commitMulti() {
            try {
                const recs = await this.$wire.saveMulti();
                if (Array.isArray(recs)) {
                    recs.forEach((r) => (cfg.kind === 'group' ? this.$store.masters.addGroup(r) : this.$store.masters.addLedger(r)));
                    this.$store.zb.note('Saved ' + recs.length + ' ' + cfg.kind + '(s)', 'commit');
                }
                this.backToMenu();
            } catch (e) {
                /* inline errors */
            }
        },

        /* ---- alter ---- */
        async openAlter(item) {
            if (!item) return;
            await this.$wire.loadForAlter(item.id);
            this.mode = 'alter-form';
            this.markFormPristine();
            this.$store.zb.pushContext({
                name: 'master.alter-form',
                label: 'Alter: ' + item.name,
                focusEl: cfg.alterFirst,
                actions: [],
                onPop: () => (this.mode = 'alter'),
            });
        },
        async saveAlter() {
            try {
                const rec = await this.$wire.saveAlter();
                if (!rec) return;
                if (cfg.kind === 'group') this.$store.masters.addGroup(rec);
                else this.$store.masters.addLedger(rec);
                this.$store.zb.note('Altered ' + cfg.kind + ': ' + rec.name, 'commit');
                // pop the alter-form context (and any picker still open on top)
                this.$store.zb.popToContext('master.alter-form');
            } catch (e) {
                /* inline errors */
            }
        },

        /* ---- delete (with keyboard confirm) ---- */
        /**
         * Retire / restore a master (Alt+A, or the button in the detail pane).
         *
         * Retiring is the answer to "this ledger is dead but I can't delete it":
         * a master with vouchers behind it can never be deleted without orphaning
         * them, so Tally retires it instead — it stays readable on its existing
         * vouchers and stops being offered for new ones.
         *
         * No confirm prompt, unlike delete: this is fully reversible, and making
         * the user confirm a reversible action trains them to dismiss prompts.
         */
        async toggleActive(item) {
            if (!item) return;
            const next = item.is_active === false; // currently retired → restore
            try {
                const res = await this.$wire.saveActiveState(item.id, next);
                if (res && res.ok) {
                    // Update the client cache in place so every open picker and
                    // the list reflect it without a round-trip.
                    item.is_active = res.is_active;
                    const cached = (this.$store.masters[cfg.source || cfg.kind + 's'] || []).find(
                        (x) => x.id === item.id
                    );
                    if (cached) cached.is_active = res.is_active;
                    this.flashMsg((next ? '✔ Restored ' : '✔ Retired ') + item.name);
                    this.$store.zb.note((next ? 'Restored ' : 'Retired ') + cfg.kind + ': ' + item.name, 'commit');
                } else {
                    this.flashMsg((res && res.message) || 'Could not change active state.', 'warn');
                }
            } catch (e) {
                this.flashMsg('Could not change active state.', 'warn');
            }
        },

        askDelete(item) {
            if (!item) return;
            if (item.is_reserved) {
                this.flashMsg('“' + item.name + '” is a reserved master and cannot be deleted.', 'warn');
                this.$store.zb.note('Delete blocked: reserved master', 'warn');
                return;
            }
            this.confirming = item;
            this.$store.zb.pushContext({
                name: 'master.confirm',
                label: 'Confirm delete',
                focusEl: null,
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
                    if (cfg.kind === 'group') this.$store.masters.removeGroup(item.id);
                    else this.$store.masters.removeLedger(item.id);
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

        /* ---- inline quick-create group (Alt+C from a picker) ---- */
        openQuickGroup(detail) {
            this.pendingComboId = detail.comboId;
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
            this.$store.zb.askAccept({
                title: 'Create Group (inline)',
                body: accepting((this.$wire.get('qg_name') || '').trim()),
                onYes: () => this.commitQuickGroup(),
            });
        },
        async commitQuickGroup() {
            try {
                const rec = await this.$wire.saveQuickGroup();
                if (!rec) return;
                this.$store.masters.addGroup(rec);
                // pop the quick-group context (and any picker still open on top),
                // leaving the requesting combobox context as the top of stack
                this.$store.zb.popToContext('quick-group');
                this.$store.zb.emit('zb:combo-fill', { comboId: this.pendingComboId, item: rec });
                this.$store.zb.note('Created group inline: ' + rec.name, 'commit');
                this.pendingComboId = null;
            } catch (e) {
                /* inline errors */
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

export function registerWorkspace(Alpine) {
    Alpine.data('masterWorkspace', makeWorkspace);
}
