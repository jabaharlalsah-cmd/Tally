/* =========================================================================
   ZeroBook — reusable Tally-style searchable combobox (Alpine 'zbSelect')
   - filters the cached masters list live (0 network) as you type
   - ↑/↓ moves the highlight, Enter selects, Esc closes
   - registers its own context on the Phase 1 engine stack (open = push,
     close = pop), so keys route correctly while it is open
   - "Alt+C — Create" opens inline creation of a new master of the right type;
     the created master is appended to the cache and selected here.

   Config (via x-data="zbSelect({...})"):
     id          unique id for event routing (e.g. 'ledger-under')
     source      'groups' | 'ledgers'  — which cache to filter
     model       Livewire prop to set with the chosen id
     modelLabel  (optional) Livewire prop to set with the chosen name
     next        (optional) CSS selector of the next field to focus after select
     createType  'group' | null — Alt+C target master type
     allowPrimary bool — include a "⌂ Primary" (id=null) option (groups only)
     excludeId   (optional) group id whose subtree is excluded (alter re-parent)
     initialId / initialLabel  prefill (alter)
     label, placeholder
   ========================================================================= */

export function zbSelect(cfg) {
    return {
        cfg: Object.assign(
            {
                id: 'combo',
                source: 'groups',
                model: null,
                modelLabel: null,
                next: null,
                createType: null,
                allowPrimary: false,
                excludeId: null,
                excludeModel: null,
                sink: 'wire', // 'wire' -> $wire.set; 'event' -> emit zb:combo-pick
                initialId: null,
                initialLabel: '',
                label: 'Select',
                placeholder: 'Type to search…',
            },
            cfg || {}
        ),
        open: false,
        query: '',
        active: 0,
        selId: null,
        selLabel: '',

        init() {
            this.selId = this.cfg.initialId ?? null;
            this.selLabel = this.cfg.initialLabel || '';
            this.syncFromWire();
            // receive an inline-created master targeted at this combo
            this._onFill = (e) => {
                if (!e.detail || e.detail.comboId !== this.cfg.id) return;
                const item = e.detail.item;
                if (this.cfg.source === 'groups') this.$store.masters.addGroup(item);
                else if (this.cfg.source === 'ledgers') this.$store.masters.addLedger(item);
                else if (this.cfg.source === 'costCentres') this.$store.masters.addCostCentre(item);
                else this.$store.masters.addTo(this.cfg.source, item); // Phase 6A inventory sources
                this.pick(item);
            };
            window.addEventListener('zb:combo-fill', this._onFill);
        },
        destroy() {
            window.removeEventListener('zb:combo-fill', this._onFill);
        },

        /**
         * Keep the visible selection in step with the Livewire property, so that
         * Alter prefill and inline-create fill both reflect correctly even though
         * Alpine init() only runs once. Called from an x-effect on the root.
         */
        syncFromWire() {
            if (this.cfg.sink !== 'wire') return;
            if (this.open || !this.cfg.model || !this.$wire) return;
            const id = this.$wire[this.cfg.model] ?? null;
            let label = this.cfg.modelLabel ? this.$wire[this.cfg.modelLabel] || '' : this.selLabel;
            if (!label && id != null) {
                const item = (this.$store.masters[this.cfg.source] || []).find((x) => x.id === id);
                if (item) label = item.name;
            }
            this.selId = id;
            this.selLabel = label || '';
        },

        get baseList() {
            let list = this.$store.masters[this.cfg.source] || [];

            // Dropdowns offer ACTIVE masters only (Dibi Tech master-data rule).
            // A retired ledger must stay readable on the vouchers that already
            // reference it, but must not be offered for new ones.
            //
            // The currently selected id is kept even when inactive: altering an
            // old voucher whose ledger has since been retired must not silently
            // blank that field, which would look like data loss and would change
            // the entry on save. Records seeded before is_active existed have no
            // such key, so `!== false` treats missing as active.
            list = list.filter((x) => x.is_active !== false || x.id === this.selId);

            // excludeModel (a live Livewire prop) takes precedence over the
            // static excludeId, so Alter re-parenting excludes self+subtree
            // reactively after the record loads.
            const exId =
                this.cfg.excludeModel && this.$wire ? this.$wire[this.cfg.excludeModel] : this.cfg.excludeId;
            if (exId != null) {
                let banned;
                if (this.cfg.source === 'groups') banned = this.$store.masters.descendantsOf(exId);
                else if (this.cfg.source === 'costCentres') banned = this.$store.masters.costCentreDescendantsOf(exId);
                else banned = this.$store.masters.descendantsIn(this.cfg.source, exId); // stockGroups / godowns
                banned.add(exId);
                list = list.filter((x) => !banned.has(x.id));
            }
            return list;
        },
        get filtered() {
            const q = this.query.trim().toLowerCase();
            let list = this.baseList;
            if (q) {
                // Match on name + alias only (names are unique). Matching on the
                // ancestor path would over-match — e.g. "current assets" would
                // also match every child whose path contains it.
                list = list.filter(
                    (x) => x.name.toLowerCase().includes(q) || (x.alias && x.alias.toLowerCase().includes(q))
                );
                // Rank: exact name, then name-prefix, then the rest — so the
                // typed name is the default highlight rather than an alphabetical
                // sibling.
                const score = (x) => {
                    const n = x.name.toLowerCase();
                    if (n === q) return 0;
                    if (n.startsWith(q)) return 1;
                    return 2;
                };
                list = list.slice().sort((a, b) => score(a) - score(b) || a.name.localeCompare(b.name));
            }
            const out = list.slice(0, 200);
            if (this.cfg.allowPrimary && (!q || 'primary'.includes(q))) {
                out.unshift({ id: null, name: '⌂ Primary', primary: true });
            }
            return out;
        },

        onFocus() {
            this.query = '';
            this.openList();
        },
        onInput() {
            if (!this.open) this.openList();
            this.active = 0;
        },
        get ctxName() {
            return 'combo:' + this.cfg.id;
        },
        openList() {
            // An accept prompt owns the keyboard until it is answered. Commits
            // close their pickers and then ask, and popping a picker hands focus
            // back to this input — whose focus handler lands here. Opening now
            // would stack a picker ABOVE the prompt and shadow its Y/N keys,
            // leaving it unanswerable.
            if (this.$store.zb.accept.open) return;
            // Self-heal: treat "already open" as true only if our context is on
            // the stack. This prevents a stale `open` flag from blocking a
            // re-open after the context was popped elsewhere.
            if (this.$store.zb.stack.some((c) => c.name === this.ctxName)) {
                this.open = true;
                return;
            }
            this.open = true;
            // highlight the currently-selected item so a bare Enter keeps it
            const idx = this.filtered.findIndex((x) => x.id === this.selId);
            this.active = idx >= 0 ? idx : 0;
            const actions = [
                { key: 'arrowdown', label: 'Next', hint: '↓', run: () => this.move(1) },
                { key: 'arrowup', label: 'Previous', hint: '↑', run: () => this.move(-1) },
                { key: 'enter', label: 'Select', run: () => this.enter() },
                {
                    // Backspace steps back a field, but an open picker sits on top of
                    // the screen's own context and would shadow its binding — leaving
                    // the key dead on any field whose "previous" isn't just the one
                    // before it in the form (the voucher's first field steps back onto
                    // the header date, which lives outside the form). So close the
                    // picker and hand the key down to whatever context we were
                    // covering; it knows where back goes.
                    key: 'backspace',
                    label: 'Previous field',
                    hidden: true,
                    yieldWhen: (e) => !this.$store.zb.fieldSpent(e.target),
                    run: (e) => {
                        this.close();
                        const beneath = this.$store.zb.resolve('backspace');
                        if (beneath && typeof beneath.run === 'function') beneath.run(e);
                    },
                },
            ];
            if (this.cfg.createType) {
                actions.push({
                    key: 'alt+c',
                    label: 'Create ' + this.cfg.createType,
                    run: () => this.create(),
                });
            }
            this.$store.zb.pushContext({
                name: this.ctxName,
                label: this.cfg.label + ' (picker)',
                focusEl: null, // keep focus on the text input
                actions,
                onPop: () => {
                    this.open = false;
                    this.query = '';
                    this.$store.zb.rev++;
                },
            });
        },
        close() {
            if (this.$store.zb.activeName() === this.ctxName) this.$store.zb.popContext();
            else this.open = false;
        },
        move(d) {
            const n = this.filtered.length;
            if (!n) return;
            this.active = (this.active + d + n) % n;
            this.$nextTick(() => this.scrollActive());
        },
        scrollActive() {
            const el = this.$refs.panel && this.$refs.panel.querySelector('.is-active');
            if (el && el.scrollIntoView) el.scrollIntoView({ block: 'nearest' });
        },
        enter() {
            const item = this.filtered[this.active];
            if (item) {
                this.pick(item);
            } else if (this.cfg.createType && this.query.trim()) {
                this.create();
            } else {
                // No match and nothing to create — don't dead-end: clear the
                // filter so the full list reappears and the user can retry.
                this.query = '';
                this.active = 0;
            }
        },
        pick(item) {
            this.selId = item.id;
            this.selLabel = item.primary ? '⌂ Primary' : item.name;
            this.query = '';
            if (this.cfg.sink === 'event') {
                this.$store.zb.emit('zb:combo-pick', {
                    comboId: this.cfg.id,
                    id: item.id,
                    label: this.selLabel,
                    item,
                });
            } else {
                if (this.cfg.model) this.$wire.set(this.cfg.model, item.id, false);
                if (this.cfg.modelLabel) this.$wire.set(this.cfg.modelLabel, this.selLabel, false);
            }
            this.close();
            this.$store.zb.note(this.cfg.label + ' = ' + this.selLabel, 'flow');
            // advance to the next field in the form via the engine (handles
            // conditionally-shown fields correctly)
            const input = this.$refs.input;
            if (input) this.$nextTick(() => this.$store.zb.fieldAdvance({ target: input }));
        },
        create() {
            if (!this.cfg.createType) return;
            // ask the host workspace to open its inline quick-create sub-screen,
            // prefilled with the typed name, tagged with this combo's id.
            this.$store.zb.emit('zb:combo-create', {
                comboId: this.cfg.id,
                createType: this.cfg.createType,
                name: this.query.trim(),
            });
        },
        displayText() {
            return this.selLabel || '';
        },
    };
}

export function registerSelect(Alpine) {
    Alpine.data('zbSelect', zbSelect);
}
