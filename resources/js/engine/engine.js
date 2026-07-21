/* =========================================================================
   ZeroBook keyboard engine — core
   - ONE global keydown dispatcher (window, capture phase)
   - a context stack (push/pop/peek) with Esc-to-pop + focus restore
   - a (context,key) -> action registry
   - field chaining (Enter) + whole-form commit (Ctrl+A)
   - button-bar / status-bar data derived from the active context
   100% client-side. No server round-trip is ever performed here.
   ========================================================================= */

import {
    clearAllDirty,
    combo,
    isBareChar,
    isDirty,
    isEditable,
    isReloadCombo,
    mayRepeat,
    platform,
    prettyHint,
    shouldHardBlock,
    shouldIgnore,
    uncapturableKeys,
} from './keys.js';
import { startLabelLocalisation } from './labels.js';

/* ---- display helpers ---------------------------------------------------- */
// prettyHint now lives in keys.js, where it can consult the detected platform
// and print ⌘/⌥ on a Mac instead of Ctrl/Alt. Re-exported here so existing
// importers of the engine keep working.
export { prettyHint };

/* ---- store factory ------------------------------------------------------ */
export function zbStore() {
    return {
        /* identity shown in the top region (set from Blade via boot()) */
        product: 'ZeroBook',
        company: 'ZeroBook Foundation Co.',
        companyGroup: null, // Phase 12B — group name when the active company is in one
        dateLabel: '',
        periodLabel: '',

        /* engine state */
        stack: [],
        registry: {}, // { ctxName: { list, map } } — only 'global' is used as static here
        rev: 0, // reactivity nonce; bump to recompute derived UI
        calc: { open: false, expr: '', result: '', error: '', tape: [] },
        goto: { open: false },
        help: { open: false }, // F1 — Tally-style shortcut reference
        period: { open: false },
        companyPicker: { open: false }, // Phase 12A — F1 Select Company
        // Tally's "Accept? Yes or No" gate. One overlay for the whole app,
        // rendered by partials/accept.blade.php; see askAccept().
        accept: { open: false, title: '', body: '', yes: null, no: null },
        quitFlash: false,
        fallbacks: uncapturableKeys(),

        urls: {},

        boot(cfg) {
            if (cfg) {
                if (cfg.product) this.product = cfg.product;
                if (cfg.company) this.company = cfg.company;
                this.companyGroup = cfg.companyGroup || null;
                if (cfg.date) this.dateLabel = cfg.date;
                if (cfg.period) this.periodLabel = cfg.period;
                if (cfg.urls) this.urls = cfg.urls;
            }
            this.registerGlobals();
        },

        /**
         * Open a voucher of the given type. If a voucher screen is already
         * mounted (window.ZB_VOUCHER), switch its type client-side (no nav,
         * as in Tally); otherwise navigate to the voucher screen.
         */
        openVoucher(type) {
            if (window.ZB_VOUCHER && typeof window.ZB_VOUCHER.switchType === 'function') {
                window.ZB_VOUCHER.switchType(type);
            } else if (this.urls.voucherCreate) {
                window.location.href = this.urls.voucherCreate.replace('__type__', type);
            }
        },

        openFeatures() {
            if (this.urls.features && window.location.pathname !== new URL(this.urls.features, window.location).pathname) {
                window.location.href = this.urls.features;
            }
        },

        /* ---- static global bindings (available in every context) -------- */
        registerGlobals() {
            const g = [
                {
                    // TallyPrime 7.x: F3 selects the company, F1 is Help.
                    // (ZeroBook previously had Select Company on F1, which is
                    // Tally.ERP 9 behaviour — corrected for parity.)
                    key: 'f3',
                    label: 'Company',
                    group: 'Anywhere',
                    run: () => this.emit('zb:open-company'),
                },
                {
                    key: 'f1',
                    label: 'Help',
                    group: 'Anywhere',
                    run: () => this.emit('zb:open-help'),
                },
                {
                    key: 'alt+g',
                    label: 'Go To',
                    group: 'Anywhere',
                    run: () => this.emit('zb:open-goto'),
                },
                {
                    key: 'ctrl+n',
                    label: 'Calculator',
                    group: 'Anywhere',
                    run: () => this.emit('zb:toggle-calc'),
                },
                {
                    // Doc §F.4: Ctrl+N opens a browser window even in an
                    // installed PWA, so the calculator needs a binding the
                    // browser will actually release.
                    key: 'ctrl+alt+n',
                    label: 'Calculator (alt)',
                    group: 'Anywhere',
                    hidden: true,
                    run: () => this.emit('zb:toggle-calc'),
                },
                {
                    key: 'f2',
                    label: 'Change Date',
                    group: 'Anywhere',
                    run: () => this.emit('zb:open-period'),
                },
                {
                    key: 'alt+f2',
                    label: 'Change Period',
                    group: 'Anywhere',
                    run: () => this.emit('zb:open-period', { range: true }),
                },
                {
                    key: 'f4',
                    label: 'Contra',
                    group: 'Vouchers',
                    allowInInput: true,
                    run: () => this.openVoucher('contra'),
                },
                {
                    key: 'f5',
                    label: 'Payment',
                    group: 'Vouchers',
                    allowInInput: true,
                    run: () => this.openVoucher('payment'),
                },
                {
                    key: 'f6',
                    label: 'Receipt',
                    group: 'Vouchers',
                    allowInInput: true,
                    run: () => this.openVoucher('receipt'),
                },
                {
                    key: 'f7',
                    label: 'Journal',
                    group: 'Vouchers',
                    allowInInput: true,
                    run: () => this.openVoucher('journal'),
                },
                {
                    key: 'f8',
                    label: 'Sales',
                    group: 'Vouchers',
                    allowInInput: true,
                    run: () => this.openVoucher('sales'),
                },
                {
                    key: 'f9',
                    label: 'Purchase',
                    group: 'Vouchers',
                    allowInInput: true,
                    run: () => this.openVoucher('purchase'),
                },
                // ── Notes and inventory vouchers, on TallyPrime 7.x's own keys ──
                // Corrected 2026-07-20 for Tally parity. ZeroBook previously used
                // Tally.ERP 9-era and ad-hoc assignments, several of which collided
                // with the voucher TallyPrime puts on that key (Ctrl+F8 was Credit
                // Note here but is Sales Order in Tally; Alt+F6 was Sales Order here
                // but is Credit Note in Tally). Authority: _docs/keyboard-shortcuts.md
                // §B and its out-of-scope appendix.
                {
                    // TallyPrime: Credit Note is Alt+F6 (changed from ERP 9's Ctrl+F8).
                    key: 'alt+f6',
                    label: 'Credit Note',
                    group: 'Vouchers',
                    allowInInput: true,
                    run: () => this.openVoucher('credit_note'),
                },
                {
                    // TallyPrime: Debit Note is Alt+F5 (changed from ERP 9's Ctrl+F9).
                    key: 'alt+f5',
                    label: 'Debit Note',
                    group: 'Vouchers',
                    allowInInput: true,
                    run: () => this.openVoucher('debit_note'),
                },
                {
                    key: 'alt+f7',
                    label: 'Stock Journal',
                    group: 'Inventory Vouchers',
                    allowInInput: true,
                    run: () => this.openVoucher('stock_journal'),
                },
                {
                    key: 'ctrl+f7',
                    label: 'Physical Stock',
                    group: 'Inventory Vouchers',
                    allowInInput: true,
                    run: () => this.openVoucher('physical_stock'),
                },
                {
                    key: 'alt+f8',
                    label: 'Delivery Note',
                    group: 'Inventory Vouchers',
                    allowInInput: true,
                    run: () => this.openVoucher('delivery_note'),
                },
                {
                    key: 'alt+f9',
                    label: 'Receipt Note',
                    group: 'Inventory Vouchers',
                    allowInInput: true,
                    run: () => this.openVoucher('receipt_note'),
                },
                {
                    key: 'ctrl+f8',
                    label: 'Sales Order',
                    group: 'Inventory Vouchers',
                    allowInInput: true,
                    run: () => this.openVoucher('sales_order'),
                },
                {
                    key: 'ctrl+f9',
                    label: 'Purchase Order',
                    group: 'Inventory Vouchers',
                    allowInInput: true,
                    run: () => this.openVoucher('purchase_order'),
                },
                {
                    key: 'ctrl+f5',
                    label: 'Rejections Out',
                    group: 'Inventory Vouchers',
                    allowInInput: true,
                    run: () => this.openVoucher('rejection_out'),
                },
                {
                    key: 'ctrl+f6',
                    label: 'Rejections In',
                    group: 'Inventory Vouchers',
                    allowInInput: true,
                    run: () => this.openVoucher('rejection_in'),
                },
                {
                    key: 'f11',
                    label: 'Features',
                    group: 'Anywhere',
                    allowInInput: true,
                    run: () => this.openFeatures(),
                },
                {
                    key: 'alt+f11',
                    label: 'Features (alt)',
                    hidden: true,
                    allowInInput: true,
                    run: () => this.openFeatures(),
                },
                {
                    key: 'f12',
                    label: 'Configure',
                    group: 'Anywhere',
                    allowInInput: true,
                    run: () => this.emit('zb:open-config'),
                },
                {
                    key: 'alt+f12',
                    label: 'Configure (alt)',
                    hidden: true,
                    allowInInput: true,
                    run: () => this.emit('zb:open-config'),
                },
                {
                    key: 'ctrl+a',
                    label: 'Accept',
                    hidden: true, // shown in the bottom status bar instead
                    run: (e) => this.commitNearestForm(e),
                },
                {
                    key: 'enter',
                    label: 'Next field',
                    hidden: true, // implicit flow key; shown in bottom bar
                    run: (e) => this.fieldAdvance(e),
                },
                {
                    // Enter's mirror image: step back one field. It only claims the
                    // key once the focused field has nothing left to erase, so
                    // Backspace still deletes a character wherever one exists.
                    key: 'backspace',
                    label: 'Previous field',
                    hidden: true, // implicit flow key; shown in bottom bar
                    yieldWhen: (e) => !this.fieldSpent(e.target),
                    run: (e) => this.fieldRetreat(e),
                },
                {
                    key: 'escape',
                    label: 'Back',
                    hidden: true,
                    run: () => this.escape(),
                },
            ];
            this.registry.global = this._prep(g);
        },

        _prep(actions) {
            const list = (actions || []).map((a) => ({
                key: a.key,
                label: a.label,
                hint: a.hint || prettyHint(a.key),
                group: a.group || null,
                hidden: !!a.hidden,
                allowInInput: !!a.allowInInput,
                // When true, the key is left to the browser while focus is in a
                // multi-line text field — so a repurposed clipboard combo (e.g.
                // Ctrl+V As-Invoice toggle) never eats a paste into a narration.
                yieldInTextarea: !!a.yieldInTextarea,
                // Predicate: return true to hand this keypress back to the browser
                // untouched. Lets a key carry a shortcut meaning only in the states
                // where its native behaviour isn't wanted — Backspace navigates back
                // a field, but still deletes a character while there is one to delete.
                yieldWhen: a.yieldWhen || null,
                disabled: a.disabled || null,
                run: a.run,
            }));
            const map = {};
            list.forEach((a) => {
                map[a.key] = a;
            });
            return { list, map };
        },

        /* ---- context stack --------------------------------------------- */
        peek() {
            return this.stack.length ? this.stack[this.stack.length - 1] : null;
        },
        depth() {
            return this.stack.length;
        },
        activeName() {
            const a = this.peek();
            return a ? a.name : 'root';
        },
        activeLabel() {
            const a = this.peek();
            return a ? a.label || a.name : 'Gateway of ZeroBook';
        },
        trail() {
            return this.stack.map((c) => c.label || c.name);
        },

        pushContext(ctx) {
            const prepped = this._prep(ctx.actions);
            const node = {
                name: ctx.name,
                label: ctx.label || ctx.name,
                list: prepped.list,
                map: prepped.map,
                focusEl: ctx.focusEl || null,
                onEsc: ctx.onEsc || null,
                onPop: ctx.onPop || null,
                restoreEl: document.activeElement,
                meta: ctx.meta || {},
            };
            this.stack.push(node);
            this.rev++;
            this.note('push ▸ ' + node.label + '  (depth ' + this.stack.length + ')', 'ctx');
            this._focusInto(node.focusEl);
            return node;
        },

        popContext() {
            const node = this.stack.pop();
            if (!node) return;
            this.rev++;
            try {
                node.onPop && node.onPop();
            } catch (err) {
                console.error('[ZB] onPop failed', err);
            }
            this.note('pop ◂ ' + node.label + '  (depth ' + this.stack.length + ')', 'ctx');
            const restore =
                node.restoreEl && document.contains(node.restoreEl)
                    ? node.restoreEl
                    : this.peek()
                      ? this.peek().focusEl
                      : null;
            this._focusInto(restore);
            return node;
        },

        /**
         * Pop contexts until the named one has been removed (popping anything
         * stacked above it too). Safe no-op if the name isn't on the stack.
         * Used when committing a sub-screen whose picker may still be open on top.
         */
        popToContext(name) {
            if (!this.stack.some((c) => c.name === name)) return;
            let guard = 0;
            while (this.stack.length && guard++ < 25) {
                const top = this.peek();
                this.popContext();
                if (top && top.name === name) break;
            }
        },

        replaceTop(ctx) {
            if (this.stack.length) {
                const node = this.stack.pop();
                try {
                    node.onPop && node.onPop();
                } catch (_) {}
            }
            return this.pushContext(ctx);
        },

        resetTo(ctx) {
            while (this.stack.length) {
                const n = this.stack.pop();
                try {
                    n.onPop && n.onPop();
                } catch (_) {}
            }
            this.rev++;
            return ctx ? this.pushContext(ctx) : null;
        },

        /**
         * Tally's accept gate: put a Yes/No question between a form and its
         * commit. Answering runs onYes/onNo AFTER the prompt has closed, so a
         * handler is free to open another one (a save that chains a second
         * question) without tripping the re-entrancy guard below.
         *
         * The pushed context owns every key that could otherwise reach the form
         * underneath — Enter and Ctrl+A both mean Yes here, so the keys that
         * opened the prompt can't commit twice by being pressed again.
         */
        askAccept(opts) {
            // The gate is modal by definition: a second question over the first
            // would stack two contexts on one form and leave the loser stranded.
            if (this.accept.open) return;
            const o = opts || {};
            this.accept.title = o.title || 'Accept?';
            this.accept.body = o.body || 'Accept?';
            const settle = (answer) => {
                // One answer only. Both the keys and the buttons route here, and
                // the outcome is a save — a second pass would commit twice.
                if (!this.accept.open) return;
                // popToContext, not popContext: nothing should outlive the prompt
                // it was stacked over, and this pops by name rather than trusting
                // the prompt to still be exactly on top.
                this.popToContext('zb.accept'); // onPop clears .open
                if (answer) answer();
            };
            this.accept.yes = () => settle(o.onYes);
            this.accept.no = () => settle(o.onNo);
            this.accept.open = true;
            const yes = () => this.accept.yes();
            this.pushContext({
                name: 'zb.accept',
                label: 'Accept?',
                focusEl: '#zb-accept-yes',
                actions: [
                    { key: 'enter', label: 'Yes', run: yes },
                    // allowInInput so Y/N still answer if focus never made it to
                    // the buttons — nothing under a modal prompt wants the letter.
                    { key: 'y', label: 'Yes', hidden: true, allowInInput: true, run: yes },
                    { key: 'n', label: 'No', hidden: true, allowInInput: true, run: () => this.accept.no() },
                    { key: 'ctrl+a', label: 'Accept', hidden: true, run: yes },
                ],
                onEsc: () => this.accept.no(),
                onPop: () => {
                    this.accept.open = false;
                },
            });
        },

        escape() {
            const a = this.peek();
            if (a && a.onEsc) {
                a.onEsc();
                return;
            }
            if (this.stack.length) {
                this.popContext();
                return;
            }
            this.flashQuit();
        },

        flashQuit() {
            this.quitFlash = true;
            this.note('Esc at base — "Quit ZeroBook? (press again)"', 'warn');
            setTimeout(() => {
                this.quitFlash = false;
            }, 1200);
        },

        _focusInto(target) {
            if (!target) return;
            // The target may live inside an x-show block that is only just being
            // revealed, so a single attempt can race the reveal — retry for up to
            // ~1s. A generation token cancels any earlier in-flight focus loop, so
            // two rapid context changes never fight over focus (the newest wins).
            // Stops the instant focus lands.
            const gen = (this._focusGen = (this._focusGen || 0) + 1);
            let attempts = 0;
            let rafId = null;
            let timerId = null;
            const stop = () => {
                if (rafId !== null) cancelAnimationFrame(rafId);
                if (timerId !== null) clearTimeout(timerId);
                rafId = timerId = null;
            };
            const tryFocus = () => {
                let el = target;
                if (typeof el === 'string') el = document.querySelector(el);
                if (el && typeof el.focus === 'function' && el.offsetParent !== null && !el.disabled) {
                    el.focus();
                    if (document.activeElement === el) {
                        if (isEditable(el) && typeof el.select === 'function') {
                            try {
                                el.select();
                            } catch (_) {}
                        }
                        return true;
                    }
                }
                return false;
            };
            const tick = () => {
                stop();
                if (gen !== this._focusGen) return; // superseded by a newer focus request
                if (tryFocus()) return;
                if (attempts++ < 60) schedule();
            };
            // rAF alone is not enough: the browser pauses it entirely while the tab
            // is hidden or backgrounded, which left focus stranded on the previous
            // field — and since every screen here is keyboard-driven, a lost focus
            // reads as a frozen page. Race a timer against it so the retry still
            // runs when no frame is ever painted.
            const schedule = () => {
                stop();
                rafId = requestAnimationFrame(tick);
                timerId = setTimeout(tick, 32);
            };
            // The target is usually already in the DOM and visible, so land focus
            // now rather than waiting on a frame that may never come.
            if (tryFocus()) return;
            schedule();
        },

        /* ---- resolution ------------------------------------------------- */
        resolve(c) {
            const active = this.peek();
            if (active && active.map[c]) return active.map[c];
            const an = active ? active.name : 'root';
            if (this.registry[an] && this.registry[an].map[c]) return this.registry[an].map[c];
            if (this.registry.global && this.registry.global.map[c]) return this.registry.global.map[c];
            return null;
        },

        /* ---- field chaining + commit ----------------------------------- */
        fieldAdvance(e) {
            const el = e && e.target;
            if (!el || !el.closest) return;
            const form = el.closest('[data-zb-form]');
            if (!form) return;
            // Shift+Enter inside a textarea = newline, don't advance.
            if (el.tagName === 'TEXTAREA' && e.shiftKey) return;
            let next = null;
            const nextSel = el.getAttribute && el.getAttribute('data-zb-next');
            if (nextSel) next = form.querySelector(nextSel) || document.querySelector(nextSel);
            if (!next) {
                const fields = Array.from(form.querySelectorAll('[data-zb-field]')).filter(
                    (f) => !f.disabled && f.offsetParent !== null
                );
                const idx = fields.indexOf(el);
                if (idx > -1 && idx < fields.length - 1) next = fields[idx + 1];
            }
            if (next) {
                next.focus();
                if (isEditable(next) && next.select) {
                    try {
                        next.select();
                    } catch (_) {}
                }
                // If we advanced into a searchable combobox, tell it to open its
                // picker (robust against focus-event timing).
                if (next.hasAttribute('data-zb-combo-input')) {
                    next.dispatchEvent(new CustomEvent('zb-open', { bubbles: false }));
                }
                this.note(
                    'Enter ▸ ' + (next.getAttribute('data-zb-label') || next.name || next.id || 'next field'),
                    'flow'
                );
            } else {
                form.dispatchEvent(new CustomEvent('zb:commit', { bubbles: true }));
                this.note('Enter ▸ end of form → commit', 'flow');
            }
        },

        /**
         * Backspace's shortcut meaning (step back a field) may only take over when
         * the key has no editing work left to do — otherwise it could never erase a
         * character. A field is "spent" when:
         *   - it holds no text at all (a select, a checkbox), or
         *   - it is empty, or
         *   - its whole value is still selected. Every field auto-selects on focus
         *     (see autoSelectOnFocus), so this is the untouched just-arrived state:
         *     stepping back off it is what the user meant, and deleting the value
         *     they never chose to edit is not. Once they place a caret or type, the
         *     selection collapses and Backspace goes back to deleting, or
         *   - the caret sits at the very start with nothing selected — there is
         *     nothing to the left to delete.
         */
        fieldSpent(el) {
            if (!el) return true;
            const tag = el.tagName;
            if (tag === 'SELECT') return true;
            if (el.isContentEditable) return false;
            if (tag !== 'INPUT' && tag !== 'TEXTAREA') return true;
            const type = (el.getAttribute('type') || 'text').toLowerCase();
            if (['checkbox', 'radio', 'button', 'submit', 'reset', 'range', 'file'].includes(type)) {
                return true;
            }
            const value = el.value || '';
            if (value === '') return true;
            // number/date inputs throw on selectionStart, so "empty" above is the only
            // signal they offer — Backspace clears them first, then steps back.
            if (tag !== 'TEXTAREA' && !['text', 'search', 'url', 'tel', 'password'].includes(type)) {
                return false;
            }
            if (el.selectionStart == null) return false;
            const whollySelected = el.selectionStart === 0 && el.selectionEnd === value.length;
            const atStart = el.selectionStart === 0 && el.selectionEnd === 0;
            return whollySelected || atStart;
        },

        /** Exactly fieldAdvance's inverse: the previous field of the same form. */
        fieldRetreat(e) {
            const el = e && e.target;
            if (!el || !el.closest) return false;
            const form = el.closest('[data-zb-form]');
            if (!form) return false;
            let prev = null;
            const prevSel = el.getAttribute && el.getAttribute('data-zb-prev');
            if (prevSel) prev = form.querySelector(prevSel) || document.querySelector(prevSel);
            if (!prev) {
                const fields = Array.from(form.querySelectorAll('[data-zb-field]')).filter(
                    (f) => !f.disabled && f.offsetParent !== null
                );
                const idx = fields.indexOf(el);
                if (idx > 0) prev = fields[idx - 1];
            }
            if (!prev) return false;
            prev.focus();
            if (isEditable(prev) && prev.select) {
                try {
                    prev.select();
                } catch (_) {}
            }
            if (prev.hasAttribute('data-zb-combo-input')) {
                prev.dispatchEvent(new CustomEvent('zb-open', { bubbles: false }));
            }
            this.note(
                'Backspace ◂ ' + (prev.getAttribute('data-zb-label') || prev.name || prev.id || 'previous field'),
                'flow'
            );
            return true;
        },

        commitNearestForm(e) {
            const el = e && e.target;
            let form = el && el.closest ? el.closest('[data-zb-form]') : null;
            if (!form) {
                // Focus escaped the form entirely (a stray click, an overlay that
                // never took focus). Every entry layout sits in the DOM at once and
                // only x-show tells them apart, so fall back to the innermost VISIBLE
                // form — a bare querySelector would return whichever comes first in
                // the markup, hidden or not, and commit the wrong screen.
                const shown = Array.from(document.querySelectorAll('[data-zb-form]')).filter(
                    (f) => f.offsetParent !== null
                );
                form = shown.length ? shown[shown.length - 1] : null;
            }
            if (form) {
                form.dispatchEvent(new CustomEvent('zb:commit', { bubbles: true }));
                this.note(
                    'Ctrl+A ▸ commit ' + (form.getAttribute('data-zb-form') || 'form'),
                    'commit'
                );
            } else {
                this.note('Ctrl+A ▸ no form in this context', 'warn');
            }
        },

        /* ---- button bar / status bar ----------------------------------- */
        barGroups() {
            void this.rev; // touch nonce for reactivity
            const groups = [];
            const active = this.peek();
            if (active) {
                const items = active.list.filter((a) => !a.hidden);
                if (items.length) groups.push({ label: active.label, items });
            }
            const g = this.registry.global;
            if (g) {
                // Split globals by their optional `group` label (e.g. Vouchers),
                // defaulting to "Anywhere".
                const byGroup = {};
                const order = [];
                g.list
                    .filter((a) => !a.hidden)
                    .forEach((a) => {
                        const key = a.group || 'Anywhere';
                        if (!byGroup[key]) {
                            byGroup[key] = [];
                            order.push(key);
                        }
                        byGroup[key].push(a);
                    });
                // Vouchers first, Anywhere last, others in insertion order
                order.sort((a, b) => (a === 'Vouchers' ? -1 : b === 'Vouchers' ? 1 : a === 'Anywhere' ? 1 : b === 'Anywhere' ? -1 : 0));
                order.forEach((key) => groups.push({ label: key, items: byGroup[key] }));
            }
            return groups;
        },
        /**
         * Every shortcut currently live, grouped for the F1 help screen.
         *
         * Differs from barGroups() in one way that matters: it KEEPS the hidden
         * actions. Enter, Backspace, Esc and Ctrl+A are hidden from the button
         * bar because the status strip already shows them, but they are exactly
         * what someone opening a shortcut reference needs to see.
         */
        helpGroups() {
            void this.rev; // touch nonce for reactivity
            const groups = [];
            const active = this.peek();
            if (active && active.list.length) {
                groups.push({ label: 'This screen — ' + active.label, items: active.list });
            }
            const g = this.registry.global;
            if (g) {
                const byGroup = {};
                const order = [];
                g.list.forEach((a) => {
                    const key = a.group || 'Anywhere';
                    if (!byGroup[key]) {
                        byGroup[key] = [];
                        order.push(key);
                    }
                    byGroup[key].push(a);
                });
                order.sort((a, b) =>
                    a === 'Vouchers' ? -1 : b === 'Vouchers' ? 1 : a === 'Anywhere' ? 1 : b === 'Anywhere' ? -1 : 0
                );
                order.forEach((key) => groups.push({ label: key, items: byGroup[key] }));
            }
            return groups;
        },

        quitLabel() {
            void this.rev;
            return this.depth() > 0 ? 'Back' : 'Quit';
        },

        /* ---- misc ------------------------------------------------------- */
        /**
         * Leave for another screen, warning first if that would discard unsaved
         * input. The single safe exit used by the manage gear beside every
         * master dropdown.
         *
         * It lives on the store rather than on one screen's controller because
         * the gear renders on nine different workspaces. When it was a method on
         * the Groups/Ledgers controller alone, the gear on Cost Centres, Units,
         * Godowns and the stock masters silently fell through to a raw
         * `location.href` — navigating away with no warning at all, which is the
         * exact failure the gear was supposed to be careful about.
         *
         * Screens that can hold unsaved input register a probe (registerDirty);
         * screens that cannot simply never report dirty, and this navigates
         * straight away.
         */
        leaveTo(href) {
            if (!href) return;
            if (!isDirty()) {
                window.location.href = href;

                return;
            }
            this.askAccept({
                title: 'Leave this screen?',
                body: 'There is unsaved input on this screen. Leaving will discard it.',
                onYes: () => {
                    // Disarm first, or beforeunload fires a second, native
                    // prompt on top of the one just answered.
                    clearAllDirty();
                    window.location.href = href;
                },
            });
        },

        emit(name, detail) {
            window.dispatchEvent(new CustomEvent(name, { detail }));
        },
        note(msg, kind) {
            this.emit('zb:log', { msg, kind: kind || 'info' });
        },
        noteKey(c) {
            this.emit('zb:log', { msg: '⌨ ' + prettyHint(c), kind: 'key' });
            this.emit('zb:key', { combo: c, hint: prettyHint(c) });
        },
        hint(key) {
            return prettyHint(key);
        },
    };
}

/* ---- the single global dispatcher -------------------------------------- */
let attached = false;
function getStore() {
    return window.Alpine && window.Alpine.store ? window.Alpine.store('zb') : null;
}

function dispatch(e) {
    const store = getStore();
    if (!store) return;

    // Keystrokes that are never ours: IME composition, dead keys, AltGr typing,
    // macOS-reserved Cmd combos, a held Windows key, and the native clipboard /
    // undo combos while focus is in a field. Checked before anything else so a
    // shortcut can never eat ordinary typing. See keys.js `shouldIgnore`.
    if (shouldIgnore(e)) return;

    // A reload must never fire from inside the app: F5 is Tally's Payment key,
    // and Ctrl+R sits under the fingers mid-entry. Either one would discard an
    // unsaved voucher. Claimed here whether or not a binding exists, so the
    // guarantee does not depend on a screen having registered anything.
    // (⌘R on macOS is left to the OS — the beforeunload guard covers it.)
    if (isReloadCombo(e)) {
        e.preventDefault();
        e.stopPropagation();
        // F5 still carries its Tally meaning; only the browser's reload is
        // suppressed. Fall through so the Payment binding can run.
        if (!/^f5$/i.test(e.code || e.key || '')) return;
    }

    const c = combo(e);
    if (!c) return;

    // Held keys repeat navigation, never actions — holding F5 must not open a
    // stack of Payment vouchers.
    if (e.repeat && !mayRepeat(c)) {
        if (shouldHardBlock(c)) e.preventDefault();
        return;
    }

    // Escape is always handled by the engine (pop context / custom onEsc).
    if (c === 'escape') {
        e.preventDefault();
        const a = store.resolve(c);
        store.noteKey(c);
        if (a && !(a.disabled && a.disabled())) a.run(e, store.peek());
        else store.escape();
        return;
    }

    const action = store.resolve(c);

    // Neutralise browser-reserved keys even when we have no binding for them,
    // so the browser's native behaviour never fires inside ZeroBook. Desktop-aware:
    // in the Tauri edition this also covers Ctrl+W / Ctrl+T (see shouldHardBlock).
    if (shouldHardBlock(c)) e.preventDefault();

    if (!action) return;

    // Don't steal plain typing: a bare letter/number only fires as a shortcut
    // when focus is NOT in an editable field (unless explicitly allowed).
    if (isBareChar(c) && isEditable(e.target) && !action.allowInInput) return;

    // A combo that yields in multi-line text (a repurposed clipboard key like
    // Ctrl+V) is handed back to the browser when a textarea has focus, so the
    // native paste still works where it matters.
    if (
        action.yieldInTextarea &&
        e.target &&
        (e.target.tagName === 'TEXTAREA' || e.target.isContentEditable)
    ) {
        return;
    }

    // The action itself gets the final say on whether this keypress is its own.
    // Checked before preventDefault so a declined key reaches the browser intact.
    if (action.yieldWhen) {
        let yielded = false;
        try {
            yielded = !!action.yieldWhen(e);
        } catch (err) {
            console.error('[ZB] yieldWhen failed for', c, err);
        }
        if (yielded) return;
    }

    if (action.disabled && action.disabled()) {
        e.preventDefault();
        return;
    }

    e.preventDefault();
    e.stopPropagation();
    store.noteKey(c);
    try {
        action.run(e, store.peek());
    } catch (err) {
        console.error('[ZB] action failed for', c, err);
    }
}

/* Standing rule: every text/number input auto-selects its content on focus
   (matches Tally field behaviour). Implemented once, globally. */
function autoSelectOnFocus(e) {
    const el = e.target;
    if (!el || !el.matches) return;
    if (el.hasAttribute('data-zb-noselect')) return;
    if (
        !el.matches(
            'input:not([type=checkbox]):not([type=radio]):not([type=button]):not([type=submit]):not([type=file]):not([type=range])'
        )
    ) {
        return;
    }
    // Deferred so a value Livewire is still writing is selected once it lands, but
    // NOT on rAF alone: the browser pauses rAF while the tab is hidden, which
    // silently dropped the select and let a typed amount append to the old value.
    // Race a timer against it — whichever fires first wins, `done` keeps it to one
    // selection, and re-focusing elsewhere in the meantime cancels it.
    let done = false;
    const select = () => {
        if (done) return;
        done = true;
        if (document.activeElement !== el) return;
        try {
            el.select();
        } catch (_) {}
    };
    requestAnimationFrame(select);
    setTimeout(select, 32);
}

/* Standing rule: an amount / quantity / rate field takes a number and nothing else.
   Those fields are type="text" inputmode="decimal" rather than type="number", because
   select() is spec'd NOT to apply to a number input — Safari honours that and silently
   refuses, which broke auto-select-on-focus (and so "type to replace") on every Mac and
   iPad. A text input won't police its own content, so the filtering type=number gave us
   for free lives here instead, in one place, exactly as the date segments already do it.
   Capture phase, so Alpine/Livewire read the cleaned value rather than the raw one. */
function filterNumeric(e) {
    const el = e.target;
    if (!el || !el.matches || !el.matches('input[inputmode="decimal"]')) return;
    const raw = el.value;
    let out = raw.replace(/[^0-9.\-]/g, '');
    const negative = out.startsWith('-'); // a minus is only a minus in front
    out = out.replace(/-/g, '');
    const dot = out.indexOf('.'); // keep the first point, drop the rest
    if (dot !== -1) out = out.slice(0, dot + 1) + out.slice(dot + 1).replace(/\./g, '');
    if (negative) out = '-' + out;
    if (out === raw) return;
    const caret = el.selectionStart;
    el.value = out;
    // Hold the caret where the user was typing rather than throwing it to the end.
    try {
        const dropped = raw.length - out.length;
        const at = Math.max(0, (caret == null ? out.length : caret) - dropped);
        el.setSelectionRange(at, at);
    } catch (_) {}
}

export function attachDispatcher() {
    if (attached) return;
    window.addEventListener('keydown', dispatch, true);
    window.addEventListener('focusin', autoSelectOnFocus, true);
    window.addEventListener('input', filterNumeric, true);
    // On a Mac, rewrite every literal "Ctrl+A"/"Alt+F1" chip in the markup to
    // ⌘A / ⌥F1 so the on-screen hints match the keys that actually work.
    // No-op on Windows and Linux. See labels.js.
    startLabelLocalisation();
    attached = true;
    // Expose a tiny debug surface for verification (devtools / harness).
    window.ZB = {
        store: getStore,
        depth: () => (getStore() ? getStore().depth() : 0),
        stack: () => (getStore() ? getStore().stack.map((c) => c.name) : []),
        activeName: () => (getStore() ? getStore().activeName() : 'root'),
    };
}
