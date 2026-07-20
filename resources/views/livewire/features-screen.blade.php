<div class="zb-features"
     x-data="{
        active: 0,
        items: [
            { key: 'bill_by_bill', label: 'Maintain bill-by-bill (bill-wise details)' },
            { key: 'cost_centres', label: 'Maintain cost centres' },
            { key: 'gst', label: 'Enable Goods & Services Tax (GST · India)' },
            { key: 'vat', label: 'Enable Value Added Tax (VAT · Nepal)' },
            { key: 'tds', label: 'Enable Tax Deducted at Source (TDS · India)' },
            { key: 'multi_currency', label: 'Enable multi-currency' },
            { key: 'inventory', label: 'Maintain inventory (stock items, godowns, orders)' },
            { key: 'budgets', label: 'Maintain budgets (targets & variance)' },
            { key: 'ratio_analysis', label: 'Enable ratio analysis (financial health ratios)' },
            { key: 'scenarios', label: 'Enable scenarios (provisional what-if vouchers)' }
        ],
        flash: '',
        regimeNote: '',
        locked: @js($lockedFeatures),
        planName: @js($planName ?? ''),
        isLocked(k) { return this.locked.includes(k); },
        init() {
            this.$store.zb.pushContext({
                name: 'features',
                label: 'F11: Features',
                focusEl: '#features-surface',
                actions: [
                    { key: 'arrowdown', label: 'Next', hint: '↓', run: () => this.move(1) },
                    { key: 'arrowup', label: 'Previous', hint: '↑', run: () => this.move(-1) },
                    { key: 'enter', label: 'Toggle', run: () => this.toggle() },
                    { key: 'space', label: 'Toggle', hidden: true, run: () => this.toggle() },
                    { key: 'ctrl+a', label: 'Accept', allowInInput: true, run: () => this.accept() }
                ],
                onEsc: () => (window.location.href = @js(route('gateway')))
            });
            this.$nextTick(() => { const el = document.querySelector('#features-surface'); if (el) el.focus(); });
        },
        move(d) { this.active = Math.max(0, Math.min(this.items.length - 1, this.active + d)); },
        toggle() {
            const k = this.items[this.active].key;
            // Plan gate (UX layer — the server rejects it too). A locked feature
            // cannot be turned on; show the upgrade hint instead.
            if (this.isLocked(k)) {
                this.regimeNote = 'This feature is not in the ' + (this.planName || 'current') + ' plan — upgrade to enable.';
                return;
            }
            const next = !this.$wire.get(k);
            this.$wire.set(k, next, false);
            // GST and VAT are mutually exclusive — turning one on turns the other off.
            if (next && k === 'gst' && this.$wire.get('vat')) { this.$wire.set('vat', false, false); this.regimeNote = 'VAT turned off — GST and VAT are mutually exclusive.'; }
            if (next && k === 'vat' && this.$wire.get('gst')) { this.$wire.set('gst', false, false); this.regimeNote = 'GST turned off — GST and VAT are mutually exclusive.'; }
            if (!next && (k === 'gst' || k === 'vat')) { this.regimeNote = ''; }
        },
        val(k) { return this.$wire.get(k); },
        async accept() {
            const flags = await this.$wire.save();
            if (!flags) { return; } // validation failed — inline errors are shown
            if (window.ZB_FEATURES) Object.assign(window.ZB_FEATURES, flags);
            this.flash = '✔ Features saved';
            this.$store.zb.note('F11 features saved', 'commit');
            setTimeout(() => (window.location.href = @js(route('gateway'))), 500);
        }
     }">

    <div class="zb-ws-flash is-ok" x-show="flash" x-cloak x-text="flash"></div>

    <div class="zb-gateway" style="max-width:620px">
        <h1 class="zb-gateway-heading">Company Features</h1>
        <p class="zb-gateway-sub">
            <span class="zb-kbd">F11</span> · toggle with <span class="zb-kbd">Enter</span>, accept with <span class="zb-kbd">Ctrl+A</span>.
            Turning a feature on reveals its (scaffolded) fields on the master screens.
        </p>

        {{-- Phase 7B — the tenant's subscription plan gates which features may be
             switched on. Locked toggles are disabled here; the server rejects them
             too (PlanGate is the security boundary). --}}
        <div x-show="planName" x-cloak
             style="margin:.3rem 0 .9rem;padding:.5rem .75rem;border:1px solid rgba(11,110,79,.35);border-radius:6px;background:rgba(11,110,79,.06);font-size:.82rem">
            Plan: <strong x-text="planName"></strong>
            <template x-if="locked.length">
                <span> — upgrade to unlock <span x-text="locked.map(k => (items.find(i => i.key===k)||{label:k}).label).join(', ')"></span>.</span>
            </template>
            <template x-if="!locked.length">
                <span> — all features unlocked.</span>
            </template>
        </div>

        <ul id="features-surface" tabindex="-1" class="zb-menu">
            <template x-for="(it, i) in items" :key="it.key">
                <li class="zb-menu-item zb-feature-item" :class="{ 'is-active': i === active, 'is-locked': isLocked(it.key) }"
                    :style="isLocked(it.key) ? 'opacity:.6' : ''"
                    @click="active = i; toggle()" @mousemove="active = i">
                    <span x-text="it.label"></span>
                    <span x-show="isLocked(it.key)" x-cloak
                          style="font-size:.72rem;color:#0B6E4F;font-weight:600;letter-spacing:.02em"
                          title="Not included in your plan — upgrade to enable">🔒 Upgrade</span>
                    <span x-show="!isLocked(it.key)" class="zb-feature-toggle" :class="{ 'is-on': val(it.key) }" x-text="val(it.key) ? 'Yes' : 'No'"></span>
                </li>
            </template>
        </ul>

        <p class="zb-regime-note" x-show="regimeNote" x-cloak x-text="regimeNote"></p>
        @error('regime') <div class="zb-field-error">{{ $message }}</div> @enderror
        @error('plan') <div class="zb-field-error">{{ $message }}</div> @enderror

        {{-- Company GST profile — revealed when GST is on. Its state drives
             intra- vs inter-state tax on every invoice. --}}
        <div class="zb-gst-profile" x-show="val('gst')" x-cloak>
            <div class="zb-subhead">Company GST details</div>
            <div class="zb-form-row">
                <label for="f-company-state">Company state</label>
                <input id="f-company-state" class="form-control zb-field" data-zb-field data-zb-label="Company state"
                       wire:model="company_state" autocomplete="off" placeholder="e.g. Maharashtra">
            </div>
            @error('company_state') <div class="zb-field-error">{{ $message }}</div> @enderror
            <div class="zb-form-row">
                <label for="f-company-gstin">Company GSTIN</label>
                <input id="f-company-gstin" class="form-control zb-field" data-zb-field data-zb-label="Company GSTIN"
                       wire:model="company_gstin" autocomplete="off" placeholder="e.g. 27ABCDE1234F1Z5">
            </div>
            @error('company_gstin') <div class="zb-field-error">{{ $message }}</div> @enderror
            <p class="text-muted" style="font-size:.76rem;margin-top:.3rem">
                A supply is <strong>intra-state</strong> (CGST + SGST) when the party's state equals the company state,
                otherwise <strong>inter-state</strong> (IGST).
            </p>
        </div>

        {{-- Company VAT profile — revealed when VAT is on. Nepal VAT is a single
             flat rate, so there is no state / intra-inter concept. --}}
        <div class="zb-gst-profile" x-show="val('vat')" x-cloak>
            <div class="zb-subhead">Company VAT details (Nepal)</div>
            <div class="zb-form-row">
                <label for="f-company-pan">Company PAN</label>
                <input id="f-company-pan" class="form-control zb-field" data-zb-field data-zb-label="Company PAN"
                       wire:model="company_pan" autocomplete="off" placeholder="e.g. 301234567">
            </div>
            @error('company_pan') <div class="zb-field-error">{{ $message }}</div> @enderror
            <p class="text-muted" style="font-size:.76rem;margin-top:.3rem">
                Nepal VAT is a single flat rate (currently <strong>13%</strong>) on the taxable value —
                no intra/inter-state split. The rate lives on each Sales/Purchase ledger.
            </p>
        </div>

        {{-- TDS (Phase 10A) — orthogonal to the GST/VAT regime: an Indian company
             deducts tax at source whether or not it is GST-registered. --}}
        <div class="zb-gst-profile" x-show="val('tds')" x-cloak>
            <div class="zb-subhead">Tax Deducted at Source</div>
            <p class="text-muted" style="font-size:.76rem;margin-top:.3rem">
                Tag your vendors as deductees on the <strong>Ledger</strong> master (PAN, deductee type and a
                default section), then a Payment to them offers to deduct TDS automatically.
                Rates and thresholds live in the
                <a href="{{ route('masters.tds-sections') }}">TDS Sections</a> table — edit them there when the
                Finance Act changes.
            </p>
            <p class="text-muted" style="font-size:.76rem;margin-top:.3rem">
                <strong>Have a CA verify the seeded rates and thresholds against the current Finance Act
                before using them on a live book.</strong>
            </p>

            {{-- Phase 10B — the deductor's Form 26Q filing identity. Optional to set here,
                 but the quarterly 26Q return cannot be filed without it. --}}
            <div class="zb-subhead" style="margin-top:.9rem">26Q Deductor Details</div>
            <p class="text-muted" style="font-size:.74rem;margin-bottom:.4rem">Needed to export the quarterly Form 26Q return.</p>
            <div class="zb-form-row">
                <label for="d-tan">TAN</label>
                <input id="d-tan" class="form-control zb-field" data-zb-field wire:model="deductor.company_tan" maxlength="10" autocomplete="off" placeholder="e.g. MUMZ12345A">
            </div>
            <div class="zb-form-row">
                <label for="d-pan">Company PAN</label>
                <input id="d-pan" class="form-control zb-field" data-zb-field wire:model="company_pan" maxlength="10" autocomplete="off" placeholder="e.g. AAACZ1234F">
            </div>
            <div class="zb-form-row">
                <label for="d-name">Deductor name</label>
                <input id="d-name" class="form-control zb-field" data-zb-field wire:model="deductor.deductor_name" maxlength="75" autocomplete="off">
            </div>
            <div class="zb-form-row">
                <label for="d-addr">Address (line 1)</label>
                <input id="d-addr" class="form-control zb-field" data-zb-field wire:model="deductor.deductor_address1" maxlength="25" autocomplete="off">
            </div>
            <div class="zb-form-row">
                <label for="d-state">State code</label>
                <input id="d-state" class="form-control zb-field" data-zb-field wire:model="deductor.deductor_state_code" maxlength="2" autocomplete="off" placeholder="e.g. 19 (Maharashtra)">
            </div>
            <div class="zb-form-row">
                <label for="d-pin">PIN code</label>
                <input id="d-pin" class="form-control zb-field" data-zb-field wire:model="deductor.deductor_pincode" maxlength="6" autocomplete="off">
            </div>
            <div class="zb-form-row">
                <label for="d-email">Email</label>
                <input id="d-email" class="form-control zb-field" data-zb-field wire:model="deductor.deductor_email" maxlength="75" autocomplete="off">
            </div>
            <div class="zb-form-row">
                <label for="d-phone">Phone</label>
                <input id="d-phone" class="form-control zb-field" data-zb-field wire:model="deductor.deductor_phone" maxlength="10" autocomplete="off">
            </div>
            <div class="zb-form-row">
                <label for="d-type">Deductor category</label>
                <select id="d-type" class="form-select zb-field" data-zb-field wire:model="deductor.deductor_type">
                    <option value="">— select —</option>
                    <option value="K">Company</option>
                    <option value="M">Branch / Division of Company</option>
                    <option value="Q">Individual / HUF</option>
                    <option value="F">Firm</option>
                    <option value="A">Central Government</option>
                    <option value="S">State Government</option>
                </select>
            </div>

            <div class="zb-subhead" style="margin-top:.7rem">Person responsible for deduction</div>
            <div class="zb-form-row">
                <label for="r-name">Name</label>
                <input id="r-name" class="form-control zb-field" data-zb-field wire:model="deductor.resp_name" maxlength="75" autocomplete="off">
            </div>
            <div class="zb-form-row">
                <label for="r-desig">Designation</label>
                <input id="r-desig" class="form-control zb-field" data-zb-field wire:model="deductor.resp_designation" maxlength="20" autocomplete="off">
            </div>
            <div class="zb-form-row">
                <label for="r-pan">PAN</label>
                <input id="r-pan" class="form-control zb-field" data-zb-field wire:model="deductor.resp_pan" maxlength="10" autocomplete="off">
            </div>
            <div class="zb-form-row">
                <label for="r-addr">Address (line 1)</label>
                <input id="r-addr" class="form-control zb-field" data-zb-field wire:model="deductor.resp_address1" maxlength="25" autocomplete="off">
            </div>
            <div class="zb-form-row">
                <label for="r-state">State code</label>
                <input id="r-state" class="form-control zb-field" data-zb-field wire:model="deductor.resp_state_code" maxlength="2" autocomplete="off">
            </div>
            <div class="zb-form-row">
                <label for="r-pin">PIN code</label>
                <input id="r-pin" class="form-control zb-field" data-zb-field wire:model="deductor.resp_pincode" maxlength="6" autocomplete="off">
            </div>
            <div class="zb-form-row">
                <label for="r-email">Email</label>
                <input id="r-email" class="form-control zb-field" data-zb-field wire:model="deductor.resp_email" maxlength="75" autocomplete="off">
            </div>
            <div class="zb-form-row">
                <label for="r-phone">Phone</label>
                <input id="r-phone" class="form-control zb-field" data-zb-field wire:model="deductor.resp_phone" maxlength="10" autocomplete="off">
            </div>
        </div>
    </div>
</div>
