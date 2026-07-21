@php($__cache = $this->mastersCache())
<div class="zb-ws"
     {{-- Real user typing arms the unsaved-work guard; framework-dispatched
          events do not (isTrusted). See hasUnsavedWork() in workspace.js. --}}
     @input="noteUserInput($event)" @change="noteUserInput($event)"
     x-data="masterWorkspace({
        kind: 'ledger',
        title: 'Ledgers',
        hubUrl: @js(route('masters.index')),
        createFirst: '#l-name',
        multiFirst: '[data-zb-form=\'ledger-multi\'] .zb-combo-input',
        alterFirst: '#la-name'
     })"
     x-init="$store.masters.seed(@js($__cache['groups']), @js($__cache['ledgers']));
             $store.masters.seedInventory('tdsSections', @js($__cache['tdsSections']))">

    <div class="zb-ws-flash" x-show="flash" x-cloak x-transition
         :class="_flashKind === 'warn' ? 'is-warn' : 'is-ok'" x-text="flash"></div>

    {{-- ================= MENU ================= --}}
    <div x-show="mode === 'menu'" id="ws-menu" tabindex="-1" class="zb-gateway" style="max-width:560px">
        <h1 class="zb-gateway-heading">Ledgers</h1>
        <p class="zb-gateway-sub">Chart of Accounts &middot; Ledger Accounts</p>
        <ul class="zb-menu">
            <template x-for="(it, i) in menuItems" :key="it.mode">
                <li class="zb-menu-item" :class="{ 'is-active': i === menuActive }"
                    @click="menuActive = i; enterMode(it.mode)" @mousemove="menuActive = i">
                    <span>
                        <span x-text="hotSplit(it).pre"></span><span class="zb-hot" x-text="hotSplit(it).hot"></span><span x-text="hotSplit(it).post"></span>
                    </span>
                    <span class="zb-menu-desc" x-text="it.desc"></span>
                </li>
            </template>
        </ul>
        <p class="text-muted" style="text-align:center;margin-top:.9rem;font-size:.78rem">
            {{ \App\Models\Ledger::count() }} ledgers &middot; Cash &amp; Profit &amp; Loss A/c are reserved
        </p>
    </div>

    {{-- ================= CREATE (single) ================= --}}
    <form x-show="mode === 'create'" class="zb-panel zb-ws-form" data-zb-form="ledger"
          x-on:zb:commit.prevent="saveCreate()" @submit.prevent>
        <div class="zb-panel-title">Ledger Creation</div>

        <div class="zb-form-row">
            <label for="l-name">Name</label>
            <input id="l-name" class="form-control zb-field" data-zb-field data-zb-label="Name"
                   wire:model="name" autocomplete="off" placeholder="e.g. State Bank">
        </div>
        @error('name') <div class="zb-field-error">{{ $message }}</div> @enderror

        <div class="zb-form-row">
            <label for="l-alias">Alias</label>
            <input id="l-alias" class="form-control zb-field" data-zb-field data-zb-label="Alias"
                   wire:model="alias" autocomplete="off">
        </div>

        <div class="zb-form-row">
            <label>Under</label>
            <x-master-select id="l-under" source="groups" model="group_id" model-label="group_label"
                             create-type="group" :allow-primary="false" label="Under"
                             placeholder="Group (Alt+C to create a new group)" />
        </div>
        @error('group_id') <div class="zb-field-error">{{ $message }}</div> @enderror

        {{-- TallyPrime asks the behaviour questions immediately after Under, before
             any address or tax detail, because they change what the rest of the
             form and every later voucher on this ledger will ask for. --}}

        {{-- F11-gated: only shown when "Maintain bill-by-bill" feature is on --}}
        <div class="zb-form-row" x-show="feature('bill_by_bill')" x-cloak>
            <label for="l-billbybill">Maintain balances bill-by-bill?</label>
            <select id="l-billbybill" class="form-select zb-field" data-zb-field data-zb-label="Bill-by-bill" wire:model="maintain_bill_by_bill">
                <option value="0">No</option>
                <option value="1">Yes</option>
            </select>
        </div>

        {{-- F11-gated: only shown when "Cost centres" feature is on (Phase 5D) --}}
        <div class="zb-form-row" x-show="feature('cost_centres')" x-cloak>
            <label for="l-costcentre">Cost centres applicable?</label>
            <select id="l-costcentre" class="form-select zb-field" data-zb-field data-zb-label="Cost centres" wire:model="cost_centres_applicable">
                <option value="0">No</option>
                <option value="1">Yes</option>
            </select>
        </div>

        <div class="zb-subhead">Mailing Details <span class="text-muted">(optional)</span></div>
        <div class="zb-form-row">
            <label for="l-mailname">Mailing name</label>
            <input id="l-mailname" class="form-control zb-field" data-zb-field data-zb-label="Mailing name" wire:model="mailing_name" autocomplete="off">
        </div>
        <div class="zb-form-row">
            <label for="l-address">Address</label>
            <input id="l-address" class="form-control zb-field" data-zb-field data-zb-label="Address" wire:model="address" autocomplete="off">
        </div>
        <div class="zb-form-row">
            <label for="l-state">State</label>
            <input id="l-state" class="form-control zb-field" data-zb-field data-zb-label="State" wire:model="state" autocomplete="off">
        </div>
        <div class="zb-form-row">
            <label for="l-country">Country</label>
            <input id="l-country" class="form-control zb-field" data-zb-field data-zb-label="Country" wire:model="country" autocomplete="off">
        </div>
        <div class="zb-form-row">
            <label for="l-pincode">PIN code</label>
            <input id="l-pincode" class="form-control zb-field" data-zb-field data-zb-label="PIN" wire:model="pincode" autocomplete="off">
        </div>
        {{-- Indian income-tax PAN — GST regime only (Nepal reuses the GSTIN column as PAN). --}}
        <div class="zb-form-row" x-show="!feature('vat')">
            <label for="l-pan">PAN</label>
            <input id="l-pan" class="form-control zb-field" data-zb-field data-zb-label="PAN" wire:model="pan" autocomplete="off">
        </div>
        {{-- Tax ID — the same `gstin` column, labelled by the active regime. --}}
        <div class="zb-form-row">
            <label for="l-gstin" x-text="feature('vat') ? 'PAN (VAT)' : 'GSTIN'">GSTIN</label>
            <input id="l-gstin" class="form-control zb-field" data-zb-field :data-zb-label="feature('vat') ? 'PAN' : 'GSTIN'" wire:model="gstin" autocomplete="off">
        </div>

        {{-- F11-gated tax details — shown under whichever regime is active (GST or VAT).
             The gst_rate/hsn_sac columns are reused for both. --}}
        <div x-show="feature('gst') || feature('vat')" x-cloak
             x-init="if (feature('vat') && !$wire.get('gst_rate')) $wire.set('gst_rate', '13', false)">
            <div class="zb-subhead" x-text="feature('vat') ? 'VAT Details' : 'GST Details'">GST Details</div>
            <div class="zb-form-row">
                <label for="l-gstrate" x-text="(feature('vat') ? 'VAT' : 'GST') + ' rate (%)'">GST rate (%)</label>
                <input id="l-gstrate" type="text" inputmode="decimal" class="form-control zb-field" data-zb-field
                       data-zb-label="Tax rate" wire:model="gst_rate" :placeholder="feature('vat') ? 'e.g. 13 (Nepal VAT)' : 'e.g. 18 (on a Sales/Purchase ledger)'">
            </div>
            @error('gst_rate') <div class="zb-field-error">{{ $message }}</div> @enderror
            <div class="zb-form-row">
                <label for="l-hsn" x-text="feature('vat') ? 'HS Code' : 'HSN / SAC'">HSN / SAC</label>
                <input id="l-hsn" class="form-control zb-field" data-zb-field data-zb-label="HS code" wire:model="hsn_sac" autocomplete="off">
            </div>
            {{-- Registration type is a GST-only concept — hidden under VAT. --}}
            <div class="zb-form-row" x-show="feature('gst')">
                <label for="l-regtype">Registration type</label>
                <select id="l-regtype" class="form-select zb-field" data-zb-field data-zb-label="Registration type" wire:model="gst_registration_type">
                    <option value="">—</option>
                    <option value="Regular">Regular</option>
                    <option value="Composition">Composition</option>
                    <option value="Unregistered">Unregistered</option>
                    <option value="Consumer">Consumer</option>
                </select>
            </div>
        </div>

        {{-- F11-gated: TDS deductee tagging (Phase 10A). Tag the vendors you withhold tax
             from — a Payment to them then offers the deduction, defaulted to this section.
             Clearing the PAN is what makes Section 206AA bite (20%, or 5% under 194Q). --}}
        <div x-show="feature('tds')" x-cloak>
            <div class="zb-subhead">TDS Deductee Details</div>
            <div class="zb-form-row">
                <label for="l-deductee-pan">Deductee PAN</label>
                <input id="l-deductee-pan" class="form-control zb-field" data-zb-field data-zb-label="Deductee PAN"
                       wire:model="deductee_pan" autocomplete="off" maxlength="10"
                       placeholder="e.g. ABCDE1234F — leave blank and TDS is deducted at the Section 206AA rate">
            </div>
            @error('deductee_pan') <div class="zb-field-error">{{ $message }}</div> @enderror

            <div class="zb-form-row">
                <label for="l-deductee-type">Deductee type</label>
                <select id="l-deductee-type" class="form-select zb-field" data-zb-field data-zb-label="Deductee type" wire:model="deductee_type">
                    <option value="">— not a deductee —</option>
                    <option value="individual_huf">Individual / HUF</option>
                    <option value="company_firm_llp">Company / Firm / LLP</option>
                    <option value="other">Other</option>
                </select>
            </div>
            @error('deductee_type') <div class="zb-field-error">{{ $message }}</div> @enderror

            <div class="zb-form-row">
                <label>Default TDS section</label>
                <x-master-select id="l-tds-section" source="tdsSections" model="default_tds_section_id"
                                 model-label="default_tds_section_label" :allow-primary="false" label="Default TDS section"
                                 placeholder="Optional — e.g. 393-194J for a professional" />
            </div>
            @error('default_tds_section_id') <div class="zb-field-error">{{ $message }}</div> @enderror
        </div>

        {{-- Phase 12B — inter-company link (only when the active company is in a group) --}}
        @php($__linkable = $__cache['linkableCompanies'] ?? [])
        @if (count($__linkable) > 0)
            <div class="zb-subhead">Inter-Company</div>
            <div class="zb-form-row">
                <label for="l-linked-company">Linked to company</label>
                <select id="l-linked-company" class="form-select zb-field" data-zb-field data-zb-label="Linked company" wire:model="linked_company_id">
                    <option value="">— not an inter-company party —</option>
                    @foreach ($__linkable as $c)
                        <option value="{{ $c['id'] }}">{{ $c['name'] }}</option>
                    @endforeach
                </select>
            </div>
            @error('linked_company_id') <div class="zb-field-error">{{ $message }}</div> @enderror
            <p class="text-muted zb-ws-hint">A linked party marks every voucher on it as INTER-COMPANY (tagged for consolidation). Only party-tracking ledgers (Debtors/Creditors/Loans) can be linked.</p>
        @endif

        {{-- Opening Balance is LAST, as in TallyPrime: identity and behaviour first,
             the number last. It is also the field that ends the Enter chain, so
             Enter here accepts the ledger — which is why Tally puts it here. --}}
        <div class="zb-form-row">
            <label for="l-opening">Opening Balance</label>
            <div class="zb-opening">
                <span class="zb-amt-prefix">{{ baseSymbol() }}</span>
                <input id="l-opening" type="text" inputmode="decimal" class="form-control zb-field zb-opening-amt"
                       data-zb-field data-zb-label="Opening balance" wire:model="opening_balance" placeholder="0.00">
                <select class="form-select zb-field zb-opening-side" data-zb-field data-zb-label="Dr/Cr"
                        wire:model="opening_balance_type">
                    <option value="Dr">Dr</option>
                    <option value="Cr">Cr</option>
                </select>
            </div>
        </div>
        @error('opening_balance') <div class="zb-field-error">{{ $message }}</div> @enderror
        @error('opening_balance_type') <div class="zb-field-error">{{ $message }}</div> @enderror

        <p class="text-muted zb-ws-hint">
            <span class="zb-kbd">Enter</span> next &middot; <span class="zb-kbd">Ctrl+A</span> accept &middot;
            <span class="zb-kbd">Alt+C</span> create group inline &middot; <span class="zb-kbd">Esc</span> back
        </p>
    </form>

    {{-- ================= CREATE MULTIPLE ================= --}}
    <form x-show="mode === 'multi'" class="zb-panel zb-ws-form" data-zb-form="ledger-multi"
          x-on:zb:commit.prevent="saveMulti()" @submit.prevent>
        <div class="zb-panel-title">Multi Ledger Creation</div>
        <div class="zb-form-row">
            <label>Under</label>
            <x-master-select id="lm-under" source="groups" model="multi_group_id" model-label="multi_group_label"
                             create-type="group" :allow-primary="false" label="Under"
                             placeholder="Group for all rows (Alt+C to create)" />
        </div>
        @error('multi_group_id') <div class="zb-field-error">{{ $message }}</div> @enderror

        <table class="zb-grid">
            <thead><tr><th style="width:2.5rem">#</th><th>Name of Ledger</th><th style="width:9rem">Opening</th><th style="width:5rem">Dr/Cr</th></tr></thead>
            <tbody>
                @foreach ($rows as $i => $row)
                    <tr>
                        <td class="zb-grid-idx">{{ $i + 1 }}</td>
                        <td>
                            <input id="lm-r{{ $i }}-name" class="form-control zb-field zb-grid-input"
                                   data-zb-field data-zb-row="{{ $i }}" data-zb-col="name"
                                   wire:model="rows.{{ $i }}.name" autocomplete="off">
                            @error("rows.$i.name") <div class="zb-field-error">{{ $message }}</div> @enderror
                        </td>
                        <td>
                            <input type="text" inputmode="decimal" class="form-control zb-field zb-grid-input"
                                   data-zb-field data-zb-row="{{ $i }}" data-zb-col="opening"
                                   wire:model="rows.{{ $i }}.opening">
                            @error("rows.$i.opening") <div class="zb-field-error">{{ $message }}</div> @enderror
                        </td>
                        <td>
                            <select class="form-select zb-field zb-grid-input"
                                    data-zb-field data-zb-row="{{ $i }}" data-zb-col="type"
                                    wire:model="rows.{{ $i }}.type">
                                <option value="Dr">Dr</option>
                                <option value="Cr">Cr</option>
                            </select>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="text-muted zb-ws-hint">
            <span class="zb-kbd">Enter</span> next cell &middot; <span class="zb-kbd">↑/↓</span> move rows &middot;
            <span class="zb-kbd">Ctrl+A</span> accept all &middot; <span class="zb-kbd">Esc</span> back
        </p>
    </form>

    {{-- ================= DISPLAY / ALTER (list) ================= --}}
    <div x-show="mode === 'display' || mode === 'alter'" class="zb-ws-browse">
        <div class="zb-panel">
            <div class="zb-panel-title">
                <span x-text="mode === 'alter' ? 'Alter Ledger' : 'Display Ledger'"></span>
                <span class="text-muted" style="font-size:.72rem;font-weight:400"> — type to filter</span>
            </div>
            <input id="ws-list-search" class="form-control zb-field" x-model="listQuery" @input="onListQuery()"
                   placeholder="Search ledgers…" autocomplete="off" spellcheck="false">
            <div class="zb-browse-split">
                <ul class="zb-list zb-browse-list" id="ws-list">
                    <template x-for="(l, i) in list" :key="l.id">
                        <li class="zb-list-item" :class="{ 'is-active': i === listActive }"
                            @click="listActive = i; mode === 'alter' ? openAlter(l) : null" @mousemove="listActive = i">
                            <span x-text="l.name" :class="{ 'is-retired': l.is_active === false }"></span>
                            <span class="zb-list-sub">
                                <span x-text="l.group || '⌂ Primary'"></span>
                                <span class="zb-reserved-tag" x-show="l.is_reserved">reserved</span>
                                <span class="zb-retired-tag" x-show="l.is_active === false" x-cloak>retired</span>
                            </span>
                        </li>
                    </template>
                    <li class="zb-list-empty" x-show="list.length === 0">No ledgers match.</li>
                </ul>
                <div class="zb-browse-detail" x-show="current" x-cloak>
                    <div class="zb-detail-name" x-text="current?.name"></div>
                    <dl class="zb-detail-dl">
                        <dt>Under</dt><dd x-text="current?.group || '⌂ Primary'"></dd>
                        <dt>Opening</dt><dd x-text="(current?.opening_balance ? current.opening_balance.toLocaleString() : '0') + ' ' + (current?.opening_balance_type || '')"></dd>
                        <dt>Alias</dt><dd x-text="current?.alias || '—'"></dd>
                        <dt>Reserved</dt><dd x-text="current?.is_reserved ? 'Yes (non-deletable)' : 'No'"></dd>
                        <dt>Status</dt>
                        <dd>
                            <span x-text="current?.is_active === false ? 'Retired' : 'Active'"></span>
                            {{-- A ledger with vouchers behind it can never be deleted without
                                 orphaning them, so retiring is the only way to get it out of
                                 the pickers. Reserved ledgers stay active always. --}}
                            <button type="button" class="zb-linkbutton zb-retire-btn"
                                    x-show="current && !current.is_reserved" x-cloak
                                    @click="toggleActive(current)"
                                    x-text="current?.is_active === false ? 'Restore' : 'Retire'"></button>
                        </dd>
                    </dl>
                    <p class="text-muted" style="font-size:.76rem">
                        <template x-if="mode === 'alter'"><span><span class="zb-kbd">Enter</span> alter &middot; </span></template>
                        <span class="zb-kbd">Alt+D</span> delete &middot;
                        <span class="zb-kbd">Alt+A</span> retire/restore &middot;
                        <span class="zb-kbd">Esc</span> back
                    </p>
                </div>
            </div>
        </div>
    </div>

    {{-- ================= ALTER form ================= --}}
    <form x-show="mode === 'alter-form'" class="zb-panel zb-ws-form" data-zb-form="ledger-alter"
          x-on:zb:commit.prevent="saveAlter()" @submit.prevent>
        <div class="zb-panel-title">
            Alter Ledger
            <span class="zb-reserved-tag" x-show="$wire.l_is_reserved">reserved</span>
        </div>

        <div class="zb-form-row">
            <label for="la-name">Name</label>
            <input id="la-name" class="form-control zb-field" data-zb-field data-zb-label="Name"
                   wire:model="l_name" :disabled="$wire.l_is_reserved" autocomplete="off">
        </div>
        @error('l_name') <div class="zb-field-error">{{ $message }}</div> @enderror

        <div class="zb-form-row">
            <label for="la-alias">Alias</label>
            <input id="la-alias" class="form-control zb-field" data-zb-field data-zb-label="Alias" wire:model="l_alias" autocomplete="off">
        </div>

        <div class="zb-form-row">
            <label>Under</label>
            <div style="flex:1" x-show="!$wire.l_is_pl && !$wire.l_is_reserved">
                <x-master-select id="la-under" source="groups" model="l_group_id" model-label="l_group_label"
                                 create-type="group" :allow-primary="false" label="Under" />
            </div>
            <input class="form-control zb-field" x-show="$wire.l_is_pl || $wire.l_is_reserved"
                   :value="$wire.l_group_label || '⌂ Primary'" disabled>
        </div>
        @error('l_group_id') <div class="zb-field-error">{{ $message }}</div> @enderror

        {{-- Behaviour questions immediately after Under, then Opening Balance last —
             the same TallyPrime order the create form uses. The two must not
             diverge, or altering a ledger would feel unlike creating one. --}}
        <div class="zb-form-row" x-show="feature('bill_by_bill')" x-cloak>
            <label for="la-billbybill">Maintain balances bill-by-bill?</label>
            <select id="la-billbybill" class="form-select zb-field" data-zb-field data-zb-label="Bill-by-bill" wire:model="l_maintain_bill_by_bill">
                <option value="0">No</option>
                <option value="1">Yes</option>
            </select>
        </div>

        <div class="zb-form-row" x-show="feature('cost_centres')" x-cloak>
            <label for="la-costcentre">Cost centres applicable?</label>
            <select id="la-costcentre" class="form-select zb-field" data-zb-field data-zb-label="Cost centres" wire:model="l_cost_centres_applicable">
                <option value="0">No</option>
                <option value="1">Yes</option>
            </select>
        </div>

        <div class="zb-subhead">Mailing Details <span class="text-muted">(optional)</span></div>
        <div class="zb-form-row"><label for="la-mailname">Mailing name</label>
            <input id="la-mailname" class="form-control zb-field" data-zb-field data-zb-label="Mailing name" wire:model="l_mailing_name" autocomplete="off"></div>
        <div class="zb-form-row"><label for="la-address">Address</label>
            <input id="la-address" class="form-control zb-field" data-zb-field data-zb-label="Address" wire:model="l_address" autocomplete="off"></div>
        <div class="zb-form-row"><label for="la-state">State</label>
            <input id="la-state" class="form-control zb-field" data-zb-field data-zb-label="State" wire:model="l_state" autocomplete="off"></div>
        <div class="zb-form-row"><label for="la-country">Country</label>
            <input id="la-country" class="form-control zb-field" data-zb-field data-zb-label="Country" wire:model="l_country" autocomplete="off"></div>
        <div class="zb-form-row"><label for="la-pincode">PIN code</label>
            <input id="la-pincode" class="form-control zb-field" data-zb-field data-zb-label="PIN" wire:model="l_pincode" autocomplete="off"></div>
        <div class="zb-form-row" x-show="!feature('vat')"><label for="la-pan">PAN</label>
            <input id="la-pan" class="form-control zb-field" data-zb-field data-zb-label="PAN" wire:model="l_pan" autocomplete="off"></div>
        <div class="zb-form-row"><label for="la-gstin" x-text="feature('vat') ? 'PAN (VAT)' : 'GSTIN'">GSTIN</label>
            <input id="la-gstin" class="form-control zb-field" data-zb-field :data-zb-label="feature('vat') ? 'PAN' : 'GSTIN'" wire:model="l_gstin" autocomplete="off"></div>

        <div x-show="feature('gst') || feature('vat')" x-cloak>
            <div class="zb-subhead" x-text="feature('vat') ? 'VAT Details' : 'GST Details'">GST Details</div>
            <div class="zb-form-row"><label for="la-gstrate" x-text="(feature('vat') ? 'VAT' : 'GST') + ' rate (%)'">GST rate (%)</label>
                <input id="la-gstrate" type="text" inputmode="decimal" class="form-control zb-field" data-zb-field
                       data-zb-label="Tax rate" wire:model="l_gst_rate" :placeholder="feature('vat') ? 'e.g. 13' : 'e.g. 18'"></div>
            @error('l_gst_rate') <div class="zb-field-error">{{ $message }}</div> @enderror
            <div class="zb-form-row"><label for="la-hsn" x-text="feature('vat') ? 'HS Code' : 'HSN / SAC'">HSN / SAC</label>
                <input id="la-hsn" class="form-control zb-field" data-zb-field data-zb-label="HS code" wire:model="l_hsn_sac" autocomplete="off"></div>
            <div class="zb-form-row" x-show="feature('gst')"><label for="la-regtype">Registration type</label>
                <select id="la-regtype" class="form-select zb-field" data-zb-field data-zb-label="Registration type" wire:model="l_gst_registration_type">
                    <option value="">—</option>
                    <option value="Regular">Regular</option>
                    <option value="Composition">Composition</option>
                    <option value="Unregistered">Unregistered</option>
                    <option value="Consumer">Consumer</option>
                </select></div>
        </div>

        {{-- F11-gated: TDS deductee tagging (Phase 10A). --}}
        <div x-show="feature('tds')" x-cloak>
            <div class="zb-subhead">TDS Deductee Details</div>
            <div class="zb-form-row">
                <label for="la-deductee-pan">Deductee PAN</label>
                <input id="la-deductee-pan" class="form-control zb-field" data-zb-field data-zb-label="Deductee PAN"
                       wire:model="l_deductee_pan" autocomplete="off" maxlength="10"
                       placeholder="Blank — deducted at the Section 206AA rate">
            </div>
            @error('l_deductee_pan') <div class="zb-field-error">{{ $message }}</div> @enderror

            <div class="zb-form-row">
                <label for="la-deductee-type">Deductee type</label>
                <select id="la-deductee-type" class="form-select zb-field" data-zb-field data-zb-label="Deductee type" wire:model="l_deductee_type">
                    <option value="">— not a deductee —</option>
                    <option value="individual_huf">Individual / HUF</option>
                    <option value="company_firm_llp">Company / Firm / LLP</option>
                    <option value="other">Other</option>
                </select>
            </div>
            @error('l_deductee_type') <div class="zb-field-error">{{ $message }}</div> @enderror

            <div class="zb-form-row">
                <label>Default TDS section</label>
                <x-master-select id="la-tds-section" source="tdsSections" model="l_default_tds_section_id"
                                 model-label="l_default_tds_section_label" :allow-primary="false" label="Default TDS section"
                                 placeholder="Optional" />
            </div>
            @error('l_default_tds_section_id') <div class="zb-field-error">{{ $message }}</div> @enderror
        </div>

        {{-- Phase 12B — inter-company link + one-click reciprocal --}}
        @php($__linkableA = $__cache['linkableCompanies'] ?? [])
        {{-- Shown when linkable companies exist OR the ledger carries a (possibly
             stale) link — so a stale link is always visible and clearable. --}}
        @if (count($__linkableA) > 0 || $l_linked_company_id)
            <div class="zb-subhead">Inter-Company</div>
            <div class="zb-form-row">
                <label for="la-linked-company">Linked to company</label>
                <select id="la-linked-company" class="form-select zb-field" data-zb-field data-zb-label="Linked company" wire:model="l_linked_company_id">
                    <option value="">— not an inter-company party —</option>
                    @foreach ($__linkableA as $c)
                        <option value="{{ $c['id'] }}">{{ $c['name'] }}</option>
                    @endforeach
                    @if ($l_linked_company_id && ! collect($__linkableA)->contains('id', (int) $l_linked_company_id))
                        {{-- a stale link (group deleted / member removed) — keep or clear --}}
                        <option value="{{ $l_linked_company_id }}">{{ \App\Models\Company::find($l_linked_company_id)?->name }} (link inert — no shared group)</option>
                    @endif
                </select>
            </div>
            @error('l_linked_company_id') <div class="zb-field-error">{{ $message }}</div> @enderror
            @if ($l_linked_company_id)
                <div class="zb-form-row">
                    <label></label>
                    <button type="button" class="btn btn-sm"
                            @click="(async () => { const r = await $wire.createReciprocal({{ (int) ($alter_id ?? 0) }}); flashMsg(r.message, r.ok ? 'ok' : 'warn'); })()">
                        Create reciprocal ledger in linked company
                    </button>
                </div>
            @endif
        @endif

        {{-- Opening Balance last, as in TallyPrime — identity first, number last. --}}
        <div class="zb-form-row">
            <label for="la-opening">Opening Balance</label>
            <div class="zb-opening">
                <span class="zb-amt-prefix">{{ baseSymbol() }}</span>
                <input id="la-opening" type="text" inputmode="decimal" class="form-control zb-field zb-opening-amt"
                       data-zb-field data-zb-label="Opening balance" wire:model="l_opening" placeholder="0.00">
                <select class="form-select zb-field zb-opening-side" data-zb-field data-zb-label="Dr/Cr" wire:model="l_type">
                    <option value="Dr">Dr</option>
                    <option value="Cr">Cr</option>
                </select>
            </div>
        </div>
        @error('l_opening') <div class="zb-field-error">{{ $message }}</div> @enderror

        <p class="text-muted zb-ws-hint">
            <span class="zb-kbd">Ctrl+A</span> accept &middot; <span class="zb-kbd">Esc</span> back
        </p>
    </form>

    {{-- ================= inline quick-create group ================= --}}
    @include('partials.quick-group')

    {{-- ================= delete confirm ================= --}}
    <div class="zb-modal-backdrop" x-show="confirming" x-cloak style="z-index:1260">
        <div class="zb-modal">
            <div class="zb-modal-head">Delete Ledger</div>
            <div class="zb-modal-body">
                Delete “<strong x-text="confirming?.name"></strong>”? This cannot be undone.
            </div>
            <div class="zb-modal-foot">
                <button type="button" class="btn btn-sm" @click="$store.zb.escape()">Esc — No</button>
                <button type="button" class="btn btn-sm zb-btn-primary" @click="doDelete()">Enter — Yes</button>
            </div>
        </div>
    </div>
</div>
