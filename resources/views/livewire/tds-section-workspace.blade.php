@php($__ts = $this->sectionCache())
@php($__fy = $this->currentFy())
<div class="zb-ws"
     x-data="tdsSectionWorkspace({ hubUrl: @js(route('masters.index')), sections: @js($__ts), fy: @js($__fy) })">

    <div class="zb-ws-flash" x-show="flash" x-cloak x-transition
         :class="_flashKind === 'warn' ? 'is-warn' : 'is-ok'" x-text="flash"></div>

    {{-- ================= MENU ================= --}}
    <div x-show="mode === 'menu'" id="ws-menu" tabindex="-1" class="zb-gateway" style="max-width:620px">
        <h1 class="zb-gateway-heading">TDS Sections</h1>
        <p class="zb-gateway-sub">The rate table &middot; rates and thresholds are data, not code</p>
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
            <span x-text="inForceCount"></span> in force for FY {{ $__fy['label'] }} &middot;
            <span x-text="expiredCount"></span> repealed (kept for historical vouchers)
        </p>
        <div class="zb-tds-ca-note">
            <strong>Have a chartered accountant verify these rates and thresholds against the current
            Finance Act before using them on a live book.</strong>
            They are seeded as reasonable defaults and they change annually. Edit them here — nothing
            in ZeroBook hardcodes a rate or a threshold.
        </div>
    </div>

    {{-- ================= CREATE ================= --}}
    <form x-show="mode === 'create'" class="zb-panel zb-ws-form" data-zb-form="tdssection"
          x-on:zb:commit.prevent="saveCreate()" @submit.prevent>
        <div class="zb-panel-title">TDS Section Creation</div>

        <div class="zb-form-row">
            <label for="ts-code">Section code</label>
            <input id="ts-code" class="form-control zb-field" data-zb-field data-zb-label="Section code"
                   wire:model="code" autocomplete="off" placeholder="e.g. 393-194J">
        </div>
        @error('code') <div class="zb-field-error">{{ $message }}</div> @enderror

        <div class="zb-form-row">
            <label for="ts-label">Description</label>
            <input id="ts-label" class="form-control zb-field" data-zb-field data-zb-label="Description"
                   wire:model="label" autocomplete="off" placeholder="e.g. Fees for professional or technical services">
        </div>
        @error('label') <div class="zb-field-error">{{ $message }}</div> @enderror

        <div class="zb-subhead">Rates</div>
        <div class="zb-form-row">
            <label for="ts-rate">Rate (%)</label>
            <input id="ts-rate" type="text" inputmode="decimal" class="form-control zb-field" data-zb-field data-zb-label="Rate"
                   wire:model="rate" placeholder="e.g. 10">
        </div>
        @error('rate') <div class="zb-field-error">{{ $message }}</div> @enderror

        <div class="zb-form-row">
            <label for="ts-ratecoy">Rate for companies / firms (%)</label>
            <input id="ts-ratecoy" type="text" inputmode="decimal" class="form-control zb-field" data-zb-field data-zb-label="Company rate"
                   wire:model="rate_company" placeholder="Optional — leave blank for one rate for everyone">
        </div>
        @error('rate_company') <div class="zb-field-error">{{ $message }}</div> @enderror

        <div class="zb-form-row">
            <label for="ts-nopan">Rate without PAN (%)</label>
            <input id="ts-nopan" type="text" inputmode="decimal" class="form-control zb-field" data-zb-field data-zb-label="No-PAN rate"
                   wire:model="no_pan_rate" placeholder="Optional — blank means the statutory 20% (Section 206AA)">
        </div>
        @error('no_pan_rate') <div class="zb-field-error">{{ $message }}</div> @enderror

        <div class="zb-subhead">Thresholds</div>
        <div class="zb-form-row">
            <label for="ts-single">Single-transaction threshold</label>
            <input id="ts-single" type="text" inputmode="decimal" class="form-control zb-field" data-zb-field data-zb-label="Single threshold"
                   wire:model="threshold_single" placeholder="Optional — e.g. 30000 for contractors (194C)">
        </div>
        @error('threshold_single') <div class="zb-field-error">{{ $message }}</div> @enderror

        <div class="zb-form-row">
            <label for="ts-annual">Aggregate threshold</label>
            <input id="ts-annual" type="text" inputmode="decimal" class="form-control zb-field" data-zb-field data-zb-label="Aggregate threshold"
                   wire:model="threshold_annual" placeholder="e.g. 50000">
        </div>
        @error('threshold_annual') <div class="zb-field-error">{{ $message }}</div> @enderror

        <div class="zb-form-row">
            <label for="ts-period">Aggregate over</label>
            <select id="ts-period" class="form-select zb-field" data-zb-field data-zb-label="Aggregate window" wire:model="threshold_period">
                <option value="annual">The fiscal year</option>
                <option value="monthly">Each calendar month (rent — 194I)</option>
            </select>
        </div>

        <div class="zb-form-row">
            <label for="ts-basis">Once crossed, deduct on</label>
            <select id="ts-basis" class="form-select zb-field" data-zb-field data-zb-label="Deduction basis" wire:model="deduct_basis">
                <option value="aggregate">The whole aggregate (catch up on earlier payments)</option>
                <option value="excess">Only the excess above the threshold (goods — 194Q)</option>
            </select>
        </div>

        <div class="zb-subhead">In force</div>
        <div class="zb-form-row">
            <label for="ts-from">From fiscal year starting</label>
            <input id="ts-from" type="text" inputmode="decimal" class="form-control zb-field" data-zb-field data-zb-label="Effective from"
                   wire:model="effective_from" placeholder="e.g. 2026 for FY 2026-27">
        </div>
        @error('effective_from') <div class="zb-field-error">{{ $message }}</div> @enderror

        <div class="zb-form-row">
            <label for="ts-to">Until fiscal year starting</label>
            <input id="ts-to" type="text" inputmode="decimal" class="form-control zb-field" data-zb-field data-zb-label="Effective to"
                   wire:model="effective_to" placeholder="Blank — still in force">
        </div>
        @error('effective_to') <div class="zb-field-error">{{ $message }}</div> @enderror

        <div class="zb-form-row">
            <label for="ts-notes">Notes</label>
            <input id="ts-notes" class="form-control zb-field" data-zb-field data-zb-label="Notes"
                   wire:model="notes" autocomplete="off" placeholder="Anything a colleague should know before using this section">
        </div>
        @error('notes') <div class="zb-field-error">{{ $message }}</div> @enderror

        <p class="text-muted zb-ws-hint">
            <span class="zb-kbd">Enter</span> next &middot; <span class="zb-kbd">Ctrl+A</span> accept &middot;
            <span class="zb-kbd">Esc</span> back
        </p>
    </form>

    {{-- ================= DISPLAY / ALTER (list) ================= --}}
    <div x-show="mode === 'display' || mode === 'alter'" class="zb-ws-browse">
        <div class="zb-panel">
            <div class="zb-panel-title">
                <span x-text="mode === 'alter' ? 'Alter TDS Section' : 'Display TDS Section'"></span>
                <span class="text-muted" style="font-size:.72rem;font-weight:400"> — type to filter</span>
            </div>
            <input id="ws-list-search" class="form-control zb-field" x-model="listQuery" @input="onListQuery()"
                   placeholder="Search sections…" autocomplete="off" spellcheck="false">

            <p class="text-muted" style="font-size:.76rem;margin:.45rem 0 0">
                Showing <strong x-text="showExpired ? 'every section ever defined' : 'the sections in force for FY ' + fy.label"></strong>.
                <span class="zb-kbd">Alt+H</span> <span x-text="showExpired ? 'hide repealed' : 'show repealed'"></span>
            </p>

            <div class="zb-browse-split">
                <ul class="zb-list zb-browse-list" id="ws-list">
                    <template x-for="(s, i) in list" :key="s.id">
                        <li class="zb-list-item" :class="{ 'is-active': i === listActive }"
                            @click="listActive = i; mode === 'alter' ? openAlter(s) : null" @mousemove="listActive = i">
                            <span>
                                <span x-text="s.code"></span>
                                <span class="zb-tds-repealed" x-show="!s.in_force" x-cloak>repealed</span>
                            </span>
                            <span class="zb-list-sub"><span x-text="s.label"></span></span>
                        </li>
                    </template>
                    <li class="zb-list-empty" x-show="list.length === 0">No sections match.</li>
                </ul>
                <div class="zb-browse-detail" x-show="current" x-cloak>
                    <div class="zb-detail-name" x-text="current?.code"></div>
                    <dl class="zb-detail-dl">
                        <dt>Description</dt><dd x-text="current?.label"></dd>
                        <dt>Rate</dt>
                        <dd>
                            <span x-text="current?.rate + '%'"></span>
                            <template x-if="current?.rate_company != null">
                                <span x-text="' · ' + current.rate_company + '% for companies / firms'"></span>
                            </template>
                        </dd>
                        <dt>Without PAN</dt><dd x-text="current?.no_pan_rate + '% (Section 206AA)'"></dd>
                        <dt>Threshold</dt><dd x-text="current?.threshold_label"></dd>
                        <dt>Once crossed</dt>
                        <dd x-text="current?.deduct_basis === 'excess' ? 'Deduct on the excess above the threshold' : 'Deduct on the whole aggregate (catch up on earlier payments)'"></dd>
                        <dt>In force</dt><dd x-text="current?.effective_label"></dd>
                        <dt>Used by</dt><dd x-text="current?.usage + ' deduction' + (current?.usage === 1 ? '' : 's')"></dd>
                        <template x-if="current?.notes">
                            <div><dt>Notes</dt><dd x-text="current.notes"></dd></div>
                        </template>
                    </dl>
                    <p class="text-muted" style="font-size:.76rem">
                        <template x-if="mode === 'alter'"><span><span class="zb-kbd">Enter</span> alter &middot; </span></template>
                        <span class="zb-kbd">Alt+E</span> expire &middot;
                        <span class="zb-kbd">Alt+D</span> delete &middot; <span class="zb-kbd">Esc</span> back
                    </p>
                </div>
            </div>
        </div>
    </div>

    {{-- ================= ALTER form ================= --}}
    <form x-show="mode === 'alter-form'" class="zb-panel zb-ws-form" data-zb-form="tdssection-alter"
          x-on:zb:commit.prevent="saveAlter()" @submit.prevent>
        <div class="zb-panel-title">Alter TDS Section</div>

        <div class="zb-form-row">
            <label for="tsa-code">Section code</label>
            <input id="tsa-code" class="form-control zb-field" data-zb-field data-zb-label="Section code"
                   wire:model="a_code" autocomplete="off">
        </div>
        @error('a_code') <div class="zb-field-error">{{ $message }}</div> @enderror

        <div class="zb-form-row">
            <label for="tsa-label">Description</label>
            <input id="tsa-label" class="form-control zb-field" data-zb-field data-zb-label="Description"
                   wire:model="a_label" autocomplete="off">
        </div>
        @error('a_label') <div class="zb-field-error">{{ $message }}</div> @enderror

        <div class="zb-subhead">Rates</div>
        <div class="zb-form-row">
            <label for="tsa-rate">Rate (%)</label>
            <input id="tsa-rate" type="text" inputmode="decimal" class="form-control zb-field" data-zb-field data-zb-label="Rate" wire:model="a_rate">
        </div>
        @error('a_rate') <div class="zb-field-error">{{ $message }}</div> @enderror

        <div class="zb-form-row">
            <label for="tsa-ratecoy">Rate for companies / firms (%)</label>
            <input id="tsa-ratecoy" type="text" inputmode="decimal" class="form-control zb-field" data-zb-field data-zb-label="Company rate" wire:model="a_rate_company">
        </div>
        @error('a_rate_company') <div class="zb-field-error">{{ $message }}</div> @enderror

        <div class="zb-form-row">
            <label for="tsa-nopan">Rate without PAN (%)</label>
            <input id="tsa-nopan" type="text" inputmode="decimal" class="form-control zb-field" data-zb-field data-zb-label="No-PAN rate" wire:model="a_no_pan_rate">
        </div>
        @error('a_no_pan_rate') <div class="zb-field-error">{{ $message }}</div> @enderror

        <div class="zb-subhead">Thresholds</div>
        <div class="zb-form-row">
            <label for="tsa-single">Single-transaction threshold</label>
            <input id="tsa-single" type="text" inputmode="decimal" class="form-control zb-field" data-zb-field data-zb-label="Single threshold" wire:model="a_threshold_single">
        </div>
        @error('a_threshold_single') <div class="zb-field-error">{{ $message }}</div> @enderror

        <div class="zb-form-row">
            <label for="tsa-annual">Aggregate threshold</label>
            <input id="tsa-annual" type="text" inputmode="decimal" class="form-control zb-field" data-zb-field data-zb-label="Aggregate threshold" wire:model="a_threshold_annual">
        </div>
        @error('a_threshold_annual') <div class="zb-field-error">{{ $message }}</div> @enderror

        <div class="zb-form-row">
            <label for="tsa-period">Aggregate over</label>
            <select id="tsa-period" class="form-select zb-field" data-zb-field data-zb-label="Aggregate window" wire:model="a_threshold_period">
                <option value="annual">The fiscal year</option>
                <option value="monthly">Each calendar month (rent — 194I)</option>
            </select>
        </div>

        <div class="zb-form-row">
            <label for="tsa-basis">Once crossed, deduct on</label>
            <select id="tsa-basis" class="form-select zb-field" data-zb-field data-zb-label="Deduction basis" wire:model="a_deduct_basis">
                <option value="aggregate">The whole aggregate (catch up on earlier payments)</option>
                <option value="excess">Only the excess above the threshold (goods — 194Q)</option>
            </select>
        </div>

        <div class="zb-subhead">In force</div>
        <div class="zb-form-row">
            <label for="tsa-from">From fiscal year starting</label>
            <input id="tsa-from" type="text" inputmode="decimal" class="form-control zb-field" data-zb-field data-zb-label="Effective from" wire:model="a_effective_from">
        </div>
        @error('a_effective_from') <div class="zb-field-error">{{ $message }}</div> @enderror

        <div class="zb-form-row">
            <label for="tsa-to">Until fiscal year starting</label>
            <input id="tsa-to" type="text" inputmode="decimal" class="form-control zb-field" data-zb-field data-zb-label="Effective to"
                   wire:model="a_effective_to" placeholder="Blank — still in force">
        </div>
        @error('a_effective_to') <div class="zb-field-error">{{ $message }}</div> @enderror

        <div class="zb-form-row">
            <label for="tsa-notes">Notes</label>
            <input id="tsa-notes" class="form-control zb-field" data-zb-field data-zb-label="Notes" wire:model="a_notes" autocomplete="off">
        </div>
        @error('a_notes') <div class="zb-field-error">{{ $message }}</div> @enderror

        <p class="zb-tds-alter-warn">
            Changing a rate or a threshold affects the deduction on every voucher posted from now on.
            Vouchers already posted keep the rate they were deducted at.
        </p>
        <p class="text-muted zb-ws-hint">
            <span class="zb-kbd">Ctrl+A</span> accept &middot; <span class="zb-kbd">Esc</span> back
        </p>
    </form>

    {{-- ================= expire confirm ================= --}}
    <div class="zb-modal-backdrop" x-show="expiring" x-cloak style="z-index:1260">
        <div class="zb-modal">
            <div class="zb-modal-head">Expire TDS Section</div>
            <div class="zb-modal-body">
                Expire “<strong x-text="expiring?.code"></strong>” at the end of the previous fiscal year?
                It will disappear from every picker, while the vouchers that already deducted under it
                keep pointing at it and still report correctly. This is how you retire a repealed section.
            </div>
            <div class="zb-modal-foot">
                <button type="button" class="btn btn-sm" @click="$store.zb.escape()">Esc — No</button>
                <button type="button" class="btn btn-sm zb-btn-primary" @click="doExpire()">Enter — Yes</button>
            </div>
        </div>
    </div>

    {{-- ================= delete confirm ================= --}}
    <div class="zb-modal-backdrop" x-show="confirming" x-cloak style="z-index:1260">
        <div class="zb-modal">
            <div class="zb-modal-head">Delete TDS Section</div>
            <div class="zb-modal-body">
                Delete “<strong x-text="confirming?.code"></strong>”? This cannot be undone.
                A section that any voucher has deducted under cannot be deleted — expire it instead.
            </div>
            <div class="zb-modal-foot">
                <button type="button" class="btn btn-sm" @click="$store.zb.escape()">Esc — No</button>
                <button type="button" class="btn btn-sm zb-btn-primary" @click="doDelete()">Enter — Yes</button>
            </div>
        </div>
    </div>
</div>
