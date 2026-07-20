@php($__cache = $this->cache())
<div class="zb-ws"
     x-data="companyWorkspace({
        kind: 'Company', source: 'companies',
        hubUrl: @js(route('gateway')),
        createFirst: '#co-name', alterFirst: '#coa-name', multiFirst: '#com-r0-name',
        seed: @js($__cache)
     })">

    <div class="zb-ws-flash" x-show="flash" x-cloak x-transition
         :class="_flashKind === 'warn' ? 'is-warn' : 'is-ok'" x-text="flash"></div>

    {{-- MENU --}}
    <div x-show="mode === 'menu'" id="ws-menu" tabindex="-1" class="zb-gateway" style="max-width:560px">
        <h1 class="zb-gateway-heading">Companies</h1>
        <p class="zb-gateway-sub">One tenant &middot; many isolated books &middot; switch with <span class="zb-kbd">F1</span></p>
        <ul class="zb-menu">
            <template x-for="(it, i) in menuItems" :key="it.mode">
                <li class="zb-menu-item" :class="{ 'is-active': i === menuActive }"
                    @click="menuActive = i; enterMode(it.mode)" @mousemove="menuActive = i">
                    <span><span x-text="hotSplit(it).pre"></span><span class="zb-hot" x-text="hotSplit(it).hot"></span><span x-text="hotSplit(it).post"></span></span>
                    <span class="zb-menu-desc" x-text="it.desc"></span>
                </li>
            </template>
        </ul>
        <p class="text-muted" style="text-align:center;margin-top:.9rem;font-size:.78rem">{{ \App\Models\Company::count() }} companies &middot; every one a fully isolated set of books</p>
    </div>

    {{-- CREATE (single) --}}
    <form x-show="mode === 'create'" class="zb-panel zb-ws-form" data-zb-form="company" x-on:zb:commit.prevent="saveCreate()" @submit.prevent>
        <div class="zb-panel-title">Company Creation</div>
        <div class="zb-form-row">
            <label for="co-name">Name</label>
            <input id="co-name" class="form-control zb-field" data-zb-field data-zb-label="Name" wire:model="name" autocomplete="off" placeholder="e.g. Acme Traders Pvt Ltd">
        </div>
        @error('name') <div class="zb-field-error">{{ $message }}</div> @enderror
        <div class="zb-form-row">
            <label for="co-slug">Short name</label>
            <input id="co-slug" class="form-control zb-field" data-zb-field data-zb-label="Short name" wire:model="slug" autocomplete="off" placeholder="optional — derived from the name">
        </div>
        @error('slug') <div class="zb-field-error">{{ $message }}</div> @enderror
        <p class="text-muted zb-ws-hint">Creating a company seeds its own chart of accounts (28 groups, Cash, P&amp;L), duty ledgers, TDS rate table, base currency and Main Location — a fresh, fully isolated book. Configure its features in <span class="zb-kbd">F11</span> after switching to it.</p>
        <p class="text-muted zb-ws-hint"><span class="zb-kbd">Enter</span> next &middot; <span class="zb-kbd">Ctrl+A</span> accept &middot; <span class="zb-kbd">Esc</span> back</p>
    </form>

    {{-- CREATE MULTIPLE --}}
    <form x-show="mode === 'multi'" class="zb-panel zb-ws-form" data-zb-form="company-multi" x-on:zb:commit.prevent="saveMulti()" @submit.prevent>
        <div class="zb-panel-title">Multi Company Creation</div>
        <table class="zb-grid">
            <thead><tr><th style="width:2.5rem">#</th><th>Name</th></tr></thead>
            <tbody>
                @foreach ($rows as $i => $row)
                    <tr>
                        <td class="zb-grid-idx">{{ $i + 1 }}</td>
                        <td><input id="com-r{{ $i }}-name" class="form-control zb-field zb-grid-input" data-zb-field data-zb-row="{{ $i }}" data-zb-col="name" wire:model="rows.{{ $i }}.name" autocomplete="off">
                            @error("rows.$i.name") <div class="zb-field-error">{{ $message }}</div> @enderror</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="text-muted zb-ws-hint">Each company is seeded with its full chart — useful when onboarding several client books at once.</p>
        <p class="text-muted zb-ws-hint"><span class="zb-kbd">Enter</span> next &middot; <span class="zb-kbd">↑/↓</span> rows &middot; <span class="zb-kbd">Ctrl+A</span> accept all &middot; <span class="zb-kbd">Esc</span> back</p>
    </form>

    {{-- DISPLAY / ALTER (list) --}}
    <div x-show="mode === 'display' || mode === 'alter'" class="zb-ws-browse">
        <div class="zb-panel">
            <div class="zb-panel-title"><span x-text="mode === 'alter' ? 'Alter Company' : 'Display Company'"></span> <span class="text-muted" style="font-size:.72rem;font-weight:400"> — type to filter</span></div>
            <input id="ws-list-search" class="form-control zb-field" x-model="listQuery" @input="onListQuery()" placeholder="Search companies…" autocomplete="off" spellcheck="false">
            <div class="zb-browse-split">
                <ul class="zb-list zb-browse-list" id="ws-list">
                    <template x-for="(c, i) in list" :key="c.id">
                        <li class="zb-list-item" :class="{ 'is-active': i === listActive }" @click="listActive = i; mode === 'alter' ? openAlter(c) : null" @mousemove="listActive = i">
                            <span x-text="c.name"></span>
                            <span class="zb-list-sub"><span x-text="(c.is_current ? 'current · ' : '') + (c.is_active ? c.slug : 'inactive')"></span></span>
                        </li>
                    </template>
                    <li class="zb-list-empty" x-show="list.length === 0">No companies match.</li>
                </ul>
                <div class="zb-browse-detail" x-show="current" x-cloak>
                    <div class="zb-detail-name" x-text="current?.name"></div>
                    <dl class="zb-detail-dl">
                        <dt>Short name</dt><dd x-text="current?.slug"></dd>
                        <dt>FY starts</dt><dd x-text="['','January','February','March','April','May','June','July','August','September','October','November','December'][current?.financial_year_start_month || 4]"></dd>
                        <dt>Status</dt><dd x-text="current?.is_active ? (current?.is_current ? 'active · current' : 'active') : 'deactivated'"></dd>
                        <dt>State</dt><dd x-text="current?.state || '—'"></dd>
                        <dt>GSTIN</dt><dd x-text="current?.gstin || '—'"></dd>
                    </dl>
                    <p class="text-muted" style="font-size:.76rem">
                        <template x-if="mode === 'alter'"><span><span class="zb-kbd">Enter</span> alter &middot; </span></template>
                        <span class="zb-kbd">Alt+D</span> deactivate &middot; <span class="zb-kbd">Esc</span> back
                    </p>
                </div>
            </div>
        </div>
    </div>

    {{-- ALTER form --}}
    <form x-show="mode === 'alter-form'" class="zb-panel zb-ws-form" data-zb-form="company-alter" x-on:zb:commit.prevent="saveAlter()" @submit.prevent>
        <div class="zb-panel-title">Alter Company</div>
        <div class="zb-form-row"><label for="coa-name">Name</label>
            <input id="coa-name" class="form-control zb-field" data-zb-field data-zb-label="Name" wire:model="c_name" autocomplete="off"></div>
        @error('c_name') <div class="zb-field-error">{{ $message }}</div> @enderror
        <div class="zb-form-row"><label for="coa-slug">Short name</label>
            <input id="coa-slug" class="form-control zb-field" data-zb-field data-zb-label="Short name" wire:model="c_slug" autocomplete="off"></div>
        @error('c_slug') <div class="zb-field-error">{{ $message }}</div> @enderror
        <div class="zb-form-row"><label for="coa-fy">FY start month</label>
            <select id="coa-fy" class="form-select zb-field" data-zb-field data-zb-label="FY start month" wire:model="c_fy_month">
                @foreach ([1=>'January',2=>'February',3=>'March',4=>'April (standard)',5=>'May',6=>'June',7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'] as $m => $label)
                    <option value="{{ $m }}">{{ $label }}</option>
                @endforeach
            </select></div>
        @error('c_fy_month') <div class="zb-field-error">{{ $message }}</div> @enderror
        <div class="zb-form-row"><label for="coa-active">Active</label>
            <label class="form-check form-switch" style="margin:0">
                <input id="coa-active" type="checkbox" class="form-check-input zb-field" data-zb-field data-zb-label="Active" wire:model="c_is_active">
            </label></div>
        @error('c_is_active') <div class="zb-field-error">{{ $message }}</div> @enderror
        <p class="text-muted zb-ws-hint">The FY start month is locked once the company has vouchers. Identity (state, GSTIN, PAN, TAN) is edited per company on <span class="zb-kbd">F11</span>.</p>
        <p class="text-muted zb-ws-hint"><span class="zb-kbd">Ctrl+A</span> accept &middot; <span class="zb-kbd">Esc</span> back</p>
    </form>

    {{-- deactivate confirm --}}
    <div class="zb-modal-backdrop" x-show="confirming" x-cloak style="z-index:1260">
        <div class="zb-modal">
            <div class="zb-modal-head">Deactivate Company</div>
            <div class="zb-modal-body">Deactivate “<strong x-text="confirming?.name"></strong>”? Its books are preserved — it just leaves the company picker. Reactivate any time from Alter.</div>
            <div class="zb-modal-foot">
                <button type="button" class="btn btn-sm" @click="$store.zb.escape()">Esc — No</button>
                <button type="button" class="btn btn-sm zb-btn-primary" @click="doDelete()">Enter — Yes</button>
            </div>
        </div>
    </div>
</div>
