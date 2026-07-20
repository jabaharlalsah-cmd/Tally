/* =========================================================================
   ZeroBook — client-side masters cache (Alpine store 'masters')
   Groups and ledgers are loaded once per page and cached here so the
   searchable pickers filter instantly with ZERO network calls. After a
   successful create, the new master is appended in-place (no reload).
   ========================================================================= */

export function mastersStore() {
    return {
        groups: [],
        ledgers: [],
        costCentres: [], // Phase 5D
        // Phase 6A inventory masters
        units: [],
        stockGroups: [],
        godowns: [],
        stockItems: [],
        // Phase 10A — the TDS rate table (only the sections in force this fiscal year)
        // and the party ledgers tagged as deductees. Both are picker sources; seed them
        // with the generic seedInventory()/addTo()/removeFrom() helpers above.
        tdsSections: [],
        tdsDeductees: [],
        // Phase 12A — the Companies management workspace
        companies: [],

        seed(groups, ledgers) {
            this.groups = Array.isArray(groups) ? groups.slice() : [];
            this.ledgers = Array.isArray(ledgers) ? ledgers.slice() : [];
            this.sortGroups();
            this.sortLedgers();
        },
        /** Seed the cost-centre cache (separate call; not every screen needs it). */
        seedCostCentres(costCentres) {
            this.costCentres = Array.isArray(costCentres) ? costCentres.slice() : [];
            this.sortCostCentres();
        },
        /** Seed one inventory cache by source key (Phase 6A). */
        seedInventory(source, rows) {
            if (!(source in this)) return;
            this[source] = Array.isArray(rows) ? rows.slice() : [];
            this.sortSource(source);
        },
        /** Generic name-sort of any cache array. */
        sortSource(source) {
            if (Array.isArray(this[source])) this[source].sort((a, b) => a.name.localeCompare(b.name));
        },
        /** Generic upsert into any cache (Phase 6A masters + reused by pickers). */
        addTo(source, rec) {
            if (!rec || rec.id == null || !Array.isArray(this[source])) return;
            const i = this[source].findIndex((x) => x.id === rec.id);
            if (i >= 0) this[source].splice(i, 1, rec);
            else this[source].push(rec);
            this.sortSource(source);
        },
        removeFrom(source, id) {
            if (Array.isArray(this[source])) this[source] = this[source].filter((x) => x.id !== id);
        },
        /** Descendant ids of `id` within any parent_id-linked cache (acyclic guard). */
        descendantsIn(source, id) {
            const out = new Set();
            const list = this[source] || [];
            const walk = (pid) => {
                list.filter((x) => x.parent_id === pid).forEach((x) => {
                    if (!out.has(x.id)) {
                        out.add(x.id);
                        walk(x.id);
                    }
                });
            };
            walk(id);
            return out;
        },

        sortGroups() {
            this.groups.sort((a, b) => a.name.localeCompare(b.name));
        },
        sortLedgers() {
            this.ledgers.sort((a, b) => a.name.localeCompare(b.name));
        },
        sortCostCentres() {
            this.costCentres.sort((a, b) => a.name.localeCompare(b.name));
        },

        addGroup(g) {
            if (!g || g.id == null) return;
            const i = this.groups.findIndex((x) => x.id === g.id);
            if (i >= 0) this.groups.splice(i, 1, g);
            else this.groups.push(g);
            this.sortGroups();
        },
        addLedger(l) {
            if (!l || l.id == null) return;
            const i = this.ledgers.findIndex((x) => x.id === l.id);
            if (i >= 0) this.ledgers.splice(i, 1, l);
            else this.ledgers.push(l);
            this.sortLedgers();
        },
        addCostCentre(c) {
            if (!c || c.id == null) return;
            const i = this.costCentres.findIndex((x) => x.id === c.id);
            if (i >= 0) this.costCentres.splice(i, 1, c);
            else this.costCentres.push(c);
            this.sortCostCentres();
        },
        removeGroup(id) {
            this.groups = this.groups.filter((g) => g.id !== id);
        },
        removeLedger(id) {
            this.ledgers = this.ledgers.filter((l) => l.id !== id);
        },
        removeCostCentre(id) {
            this.costCentres = this.costCentres.filter((c) => c.id !== id);
        },

        groupById(id) {
            return this.groups.find((g) => g.id === id) || null;
        },
        costCentreById(id) {
            return this.costCentres.find((c) => c.id === id) || null;
        },
        /** ids of every descendant cost centre of `id` (cyclic re-parent guard). */
        costCentreDescendantsOf(id) {
            const out = new Set();
            const walk = (pid) => {
                this.costCentres
                    .filter((c) => c.parent_id === pid)
                    .forEach((c) => {
                        if (!out.has(c.id)) {
                            out.add(c.id);
                            walk(c.id);
                        }
                    });
            };
            walk(id);
            return out;
        },

        /** ids of every descendant of `id` (used to prevent cyclic re-parenting). */
        descendantsOf(id) {
            const out = new Set();
            const walk = (pid) => {
                this.groups
                    .filter((g) => g.parent_id === pid)
                    .forEach((g) => {
                        if (!out.has(g.id)) {
                            out.add(g.id);
                            walk(g.id);
                        }
                    });
            };
            walk(id);
            return out;
        },
    };
}

export function registerMastersStore(Alpine) {
    Alpine.store('masters', mastersStore());
}
