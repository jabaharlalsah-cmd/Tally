/* =========================================================================
   ZeroBook — /dev/keyboard-harness screen logic (Alpine.data 'zbHarness')
   Exercises every engine feature: field chaining, whole-form commit, list
   arrow-nav, and 3 levels of nested sub-screens with Esc-pop + focus restore.
   Pure client-side; no network calls anywhere in here.
   ========================================================================= */

function zbHarness() {
    return {
        /* ---- visible engine log ---- */
        log: [],
        seq: 0,
        lastKey: '—',

        /* ---- party form (base level) ---- */
        party: { name: '', alias: '', amount: '', type: 'Sundry Debtor', notes: '' },
        partyAccepted: false,

        /* ---- ledger list (level 1 sub-screen) ---- */
        showList: false,
        ledgers: ['Cash', 'Bank of ZeroBook', 'Sales', 'Purchases', 'Rent Paid'],
        listActive: 0,

        /* ---- ledger detail (level 2 sub-screen) ---- */
        showDetail: false,
        detail: { name: '', under: 'Current Assets', opening: '' },

        /* ---- address entry (level 3 sub-screen) ---- */
        showAddress: false,
        address: { line1: '', city: '', pin: '' },

        init() {
            // capture engine events into the on-screen log
            this._onLog = (e) => this.push(e.detail.msg, e.detail.kind);
            this._onKey = (e) => (this.lastKey = e.detail.hint);
            window.addEventListener('zb:log', this._onLog);
            window.addEventListener('zb:key', this._onKey);

            // base context for the harness page
            this.$store.zb.pushContext({
                name: 'harness',
                label: 'Keyboard Harness',
                focusEl: '#h-name',
                actions: [
                    { key: 'alt+l', label: 'Browse Ledgers', run: () => this.openList() },
                ],
            });
            this.push('Harness ready — start typing, press Enter to chain fields.', 'info');
        },
        destroy() {
            window.removeEventListener('zb:log', this._onLog);
            window.removeEventListener('zb:key', this._onKey);
        },

        push(msg, kind) {
            this.seq++;
            this.log.unshift({ n: this.seq, msg, kind: kind || 'info' });
            if (this.log.length > 60) this.log = this.log.slice(0, 60);
        },

        /* ---- base form commit ---- */
        commitParty() {
            this.partyAccepted = true;
            this.push('✔ Party form ACCEPTED: ' + (this.party.name || '(blank)'), 'commit');
            setTimeout(() => (this.partyAccepted = false), 1500);
        },

        /* ---- level 1: ledger list ---- */
        openList() {
            if (this.showList) return;
            this.showList = true;
            this.listActive = 0;
            this.$store.zb.pushContext({
                name: 'ledger-list',
                label: 'Ledger List',
                focusEl: '#h-ledger-list',
                actions: [
                    { key: 'arrowdown', label: 'Next', hint: '↓', run: () => this.listMove(1) },
                    { key: 'arrowup', label: 'Previous', hint: '↑', run: () => this.listMove(-1) },
                    { key: 'enter', label: 'Open ledger', run: () => this.openDetail() },
                ],
                onPop: () => (this.showList = false),
            });
        },
        listMove(delta) {
            const n = this.ledgers.length;
            this.listActive = (this.listActive + delta + n) % n;
        },

        /* ---- level 2: ledger detail ---- */
        openDetail() {
            this.detail.name = this.ledgers[this.listActive];
            this.detail.under = 'Current Assets';
            this.detail.opening = '';
            this.showDetail = true;
            this.$store.zb.pushContext({
                name: 'ledger-detail',
                label: 'Ledger: ' + this.detail.name,
                focusEl: '#h-detail-name',
                actions: [
                    { key: 'alt+a', label: 'Add Address', run: () => this.openAddress() },
                ],
                onPop: () => (this.showDetail = false),
            });
        },
        commitDetail() {
            this.push('✔ Ledger detail ACCEPTED: ' + this.detail.name, 'commit');
            this.$store.zb.popContext(); // back to list
        },

        /* ---- level 3: address entry ---- */
        openAddress() {
            this.address = { line1: '', city: '', pin: '' };
            this.showAddress = true;
            this.$store.zb.pushContext({
                name: 'address-entry',
                label: 'Address Entry',
                focusEl: '#h-addr-line1',
                actions: [],
                onPop: () => (this.showAddress = false),
            });
        },
        commitAddress() {
            this.push('✔ Address ACCEPTED: ' + (this.address.city || '(blank)'), 'commit');
            this.$store.zb.popContext(); // back to detail
        },

        kindClass(kind) {
            return (
                {
                    ctx: 'text-primary',
                    commit: 'text-success',
                    flow: 'text-info',
                    key: 'text-secondary',
                    warn: 'text-warning',
                    calc: 'text-info',
                    goto: 'text-info',
                }[kind] || ''
            );
        },
    };
}

export function registerHarness(Alpine) {
    Alpine.data('zbHarness', zbHarness);
}
