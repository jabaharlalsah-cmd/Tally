/* =========================================================================
   ZeroBook — List of Accounts (Alpine 'listOfAccounts')

   The whole chart of accounts as one tree: Nature → Group → sub-group →
   Ledger, which is how TallyPrime presents it and how the approved build
   arranges it.

   It is a DIFFERENT screen from the Chart of Accounts hub. The hub is a menu
   of places to go; this is the data itself, in one view, for reading and
   drilling into.

   Everything is client-side over the masters cache the page already seeds, so
   expanding and collapsing never touches the server.
   ========================================================================= */

import { ensureElementVisible } from '../engine/scroll.js';

const NATURES = ['Assets', 'Liabilities', 'Income', 'Expenses'];

export function makeListOfAccounts(cfg) {
    return {
        selected: 0,
        // Natures start open: a tree that opens fully collapsed makes the user
        // work before seeing anything, and there are only four of them.
        expanded: new Set(NATURES.map((n) => 'nature:' + n)),
        // Retired masters are hidden by default — they are the exception, and
        // showing them by default would bury the live accounts. Toggled with
        // Alt+I, and the count is always visible so they can never be forgotten.
        showRetired: false,

        init() {
            this.$store.zb.pushContext({
                name: 'masters.list-of-accounts',
                label: 'List of Accounts',
                focusEl: '#zb-loa',
                actions: [
                    { key: 'arrowdown', label: 'Next', hint: '↓', run: () => this.move(1) },
                    { key: 'arrowup', label: 'Previous', hint: '↑', run: () => this.move(-1) },
                    { key: 'arrowright', label: 'Expand', hint: '→', run: () => this.setOpen(true) },
                    { key: 'arrowleft', label: 'Collapse', hint: '←', run: () => this.setOpen(false) },
                    { key: 'enter', label: 'Alter', run: () => this.drill() },
                    {
                        key: 'alt+i',
                        label: 'Show / hide retired',
                        run: () => {
                            this.showRetired = !this.showRetired;
                            this.selected = 0;
                        },
                    },
                ],
                onEsc: () => (window.location.href = cfg.hubUrl),
            });
        },

        get groups() {
            return this.$store.masters.groups || [];
        },
        get ledgers() {
            return this.$store.masters.ledgers || [];
        },

        /** How many masters are hidden right now because they are retired. */
        get retiredCount() {
            const dead = (x) => x.is_active === false;

            return this.groups.filter(dead).length + this.ledgers.filter(dead).length;
        },

        visible(rec) {
            return this.showRetired || rec.is_active !== false;
        },

        /**
         * The tree, flattened to the rows actually on screen.
         *
         * Flat rather than nested for the same reason the Gateway is: ↑/↓ then
         * walk one list, and the selection is a single index. A nested render
         * would need per-level indices and would break wrap-around.
         */
        get rows() {
            const out = [];
            const childrenOf = new Map();
            for (const g of this.groups) {
                if (!this.visible(g)) continue;
                const list = childrenOf.get(g.parent_id) || [];
                list.push(g);
                childrenOf.set(g.parent_id, list);
            }
            const ledgersOf = new Map();
            for (const l of this.ledgers) {
                if (!this.visible(l)) continue;
                const list = ledgersOf.get(l.group_id) || [];
                list.push(l);
                ledgersOf.set(l.group_id, list);
            }

            const pushGroup = (group, depth) => {
                const key = 'group:' + group.id;
                const kids = childrenOf.get(group.id) || [];
                const leds = ledgersOf.get(group.id) || [];
                out.push({
                    key,
                    depth,
                    label: group.name,
                    sub: group.alias || '',
                    kind: 'group',
                    id: group.id,
                    hasChildren: kids.length > 0 || leds.length > 0,
                    retired: group.is_active === false,
                    reserved: !!group.is_reserved,
                });
                if (!this.expanded.has(key)) return;
                kids.forEach((k) => pushGroup(k, depth + 1));
                leds.forEach((l) =>
                    out.push({
                        key: 'ledger:' + l.id,
                        depth: depth + 1,
                        label: l.name,
                        sub: this.balanceLabel(l),
                        kind: 'ledger',
                        id: l.id,
                        hasChildren: false,
                        retired: l.is_active === false,
                        reserved: !!l.is_reserved,
                    })
                );
            };

            for (const nature of NATURES) {
                const key = 'nature:' + nature;
                const primaries = (childrenOf.get(null) || []).filter((g) => g.nature === nature);
                out.push({
                    key,
                    depth: 0,
                    label: nature,
                    kind: 'nature',
                    hasChildren: primaries.length > 0,
                });
                if (!this.expanded.has(key)) continue;
                primaries.forEach((g) => pushGroup(g, 1));
                // A ledger with no group is a primary ledger — Profit & Loss A/c
                // is the seeded one. Tally files it under Liabilities.
                if (nature === 'Liabilities') {
                    (ledgersOf.get(null) || []).forEach((l) =>
                        out.push({
                            key: 'ledger:' + l.id,
                            depth: 1,
                            label: l.name,
                            sub: '(Primary)',
                            kind: 'ledger',
                            id: l.id,
                            hasChildren: false,
                            retired: l.is_active === false,
                            reserved: !!l.is_reserved,
                        })
                    );
                }
            }

            return out;
        },

        balanceLabel(l) {
            const amount = Number(l.opening_balance || 0);
            if (!amount) return '';

            return cfg.symbol + amount.toLocaleString(undefined, {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            }) + ' ' + (l.opening_balance_type || '');
        },

        current() {
            return this.rows[this.selected] || null;
        },

        move(delta) {
            const n = this.rows.length;
            if (!n) return;
            this.selected = (this.selected + delta + n) % n;
            this.$nextTick(() => this.scrollActive());
        },

        scrollActive() {
            const el = this.$el && this.$el.querySelector('.zb-loa-row.is-active');
            if (el) ensureElementVisible(el, { block: 'nearest', inline: 'nearest' });
        },

        /**
         * → opens, ← closes. On a row that cannot open, ← jumps to its parent
         * instead of doing nothing — that is what makes a keyboard tree feel
         * navigable rather than stuck.
         */
        setOpen(open) {
            const row = this.current();
            if (!row) return;
            if (open) {
                if (row.hasChildren) this.expanded.add(row.key);
                else this.move(1);
            } else if (this.expanded.has(row.key)) {
                this.expanded.delete(row.key);
            } else if (row.depth > 0) {
                for (let i = this.selected - 1; i >= 0; i--) {
                    if (this.rows[i].depth < row.depth) {
                        this.selected = i;
                        break;
                    }
                }
                this.$nextTick(() => this.scrollActive());
            }
            // Set is not reactive in Alpine; the nonce forces the re-render.
            this.expanded = new Set(this.expanded);
        },

        toggleRow(i) {
            this.selected = i;
            const row = this.current();
            if (!row || !row.hasChildren) return;
            this.setOpen(!this.expanded.has(row.key));
        },

        /** Enter on a group or ledger opens it for alteration, as in Tally. */
        drill() {
            const row = this.current();
            if (!row || row.kind === 'nature') {
                this.setOpen(!this.expanded.has(row ? row.key : ''));

                return;
            }
            const base = row.kind === 'group' ? cfg.groupsUrl : cfg.ledgersUrl;
            this.$store.zb.leaveTo(base + '?mode=alter&id=' + row.id);
        },

        isOpen(key) {
            return this.expanded.has(key);
        },
    };
}

export function registerListOfAccounts(Alpine) {
    Alpine.data('listOfAccounts', makeListOfAccounts);
}
