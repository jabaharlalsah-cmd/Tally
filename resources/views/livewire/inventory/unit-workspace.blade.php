@php($__cache = $this->cache())
<div class="zb-ws"
     x-data="inventoryWorkspace({
        kind: 'Unit', source: 'units',
        hubUrl: @js(route('inventory.index')),
        createFirst: '#u-name', alterFirst: '#ua-name', multiFirst: '#um-r0-name',
        seed: @js($__cache)
     })">

    <div class="zb-ws-flash" x-show="flash" x-cloak x-transition
         :class="_flashKind === 'warn' ? 'is-warn' : 'is-ok'" x-text="flash"></div>

    {{-- MENU --}}
    <div x-show="mode === 'menu'" id="ws-menu" tabindex="-1" class="zb-gateway" style="max-width:560px">
        <h1 class="zb-gateway-heading">Units of Measure</h1>
        <p class="zb-gateway-sub">Inventory Info &middot; how stock quantities are counted</p>
        <ul class="zb-menu">
            <template x-for="(it, i) in menuItems" :key="it.mode">
                <li class="zb-menu-item" :class="{ 'is-active': i === menuActive }"
                    @click="menuActive = i; enterMode(it.mode)" @mousemove="menuActive = i">
                    <span><span x-text="hotSplit(it).pre"></span><span class="zb-hot" x-text="hotSplit(it).hot"></span><span x-text="hotSplit(it).post"></span></span>
                    <span class="zb-menu-desc" x-text="it.desc"></span>
                </li>
            </template>
        </ul>
        <p class="text-muted" style="text-align:center;margin-top:.9rem;font-size:.78rem">{{ \App\Models\Unit::count() }} units</p>
    </div>

    {{-- CREATE (single) --}}
    <form x-show="mode === 'create'" class="zb-panel zb-ws-form" data-zb-form="unit" x-on:zb:commit.prevent="saveCreate()" @submit.prevent>
        <div class="zb-panel-title">Unit Creation</div>
        <div class="zb-form-row">
            <label for="u-name">Name</label>
            <input id="u-name" class="form-control zb-field" data-zb-field data-zb-label="Name" wire:model="name" autocomplete="off" placeholder="e.g. Kg">
        </div>
        @error('name') <div class="zb-field-error">{{ $message }}</div> @enderror
        <div class="zb-form-row">
            <label for="u-symbol">Symbol</label>
            <input id="u-symbol" class="form-control zb-field" data-zb-field data-zb-label="Symbol" wire:model="symbol" autocomplete="off" placeholder="e.g. kg">
        </div>
        <div class="zb-form-row">
            <label for="u-dp">Decimal places</label>
            <input id="u-dp" type="text" inputmode="decimal" min="0" max="6" class="form-control zb-field" data-zb-field data-zb-label="Decimal places" wire:model="decimal_places" placeholder="0 = whole counts, 2 = Kg">
        </div>
        @error('decimal_places') <div class="zb-field-error">{{ $message }}</div> @enderror
        <p class="text-muted zb-ws-hint"><span class="zb-kbd">Enter</span> next &middot; <span class="zb-kbd">Ctrl+A</span> accept &middot; <span class="zb-kbd">Esc</span> back</p>
    </form>

    {{-- CREATE MULTIPLE --}}
    <form x-show="mode === 'multi'" class="zb-panel zb-ws-form" data-zb-form="unit-multi" x-on:zb:commit.prevent="saveMulti()" @submit.prevent>
        <div class="zb-panel-title">Multi Unit Creation</div>
        <table class="zb-grid">
            <thead><tr><th style="width:2.5rem">#</th><th>Name</th><th style="width:8rem">Symbol</th><th style="width:7rem">Decimals</th></tr></thead>
            <tbody>
                @foreach ($rows as $i => $row)
                    <tr>
                        <td class="zb-grid-idx">{{ $i + 1 }}</td>
                        <td><input id="um-r{{ $i }}-name" class="form-control zb-field zb-grid-input" data-zb-field data-zb-row="{{ $i }}" data-zb-col="name" wire:model="rows.{{ $i }}.name" autocomplete="off">
                            @error("rows.$i.name") <div class="zb-field-error">{{ $message }}</div> @enderror</td>
                        <td><input class="form-control zb-field zb-grid-input" data-zb-field data-zb-row="{{ $i }}" data-zb-col="symbol" wire:model="rows.{{ $i }}.symbol" autocomplete="off"></td>
                        <td><input type="text" inputmode="decimal" min="0" max="6" class="form-control zb-field zb-grid-input" data-zb-field data-zb-row="{{ $i }}" data-zb-col="decimals" wire:model="rows.{{ $i }}.decimals">
                            @error("rows.$i.decimals") <div class="zb-field-error">{{ $message }}</div> @enderror</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="text-muted zb-ws-hint"><span class="zb-kbd">Enter</span> next &middot; <span class="zb-kbd">↑/↓</span> rows &middot; <span class="zb-kbd">Ctrl+A</span> accept all &middot; <span class="zb-kbd">Esc</span> back</p>
    </form>

    {{-- DISPLAY / ALTER (list) --}}
    <div x-show="mode === 'display' || mode === 'alter'" class="zb-ws-browse">
        <div class="zb-panel">
            <div class="zb-panel-title"><span x-text="mode === 'alter' ? 'Alter Unit' : 'Display Unit'"></span> <span class="text-muted" style="font-size:.72rem;font-weight:400"> — type to filter</span></div>
            <input id="ws-list-search" class="form-control zb-field" x-model="listQuery" @input="onListQuery()" placeholder="Search units…" autocomplete="off" spellcheck="false">
            <div class="zb-browse-split">
                <ul class="zb-list zb-browse-list" id="ws-list">
                    <template x-for="(u, i) in list" :key="u.id">
                        <li class="zb-list-item" :class="{ 'is-active': i === listActive }" @click="listActive = i; mode === 'alter' ? openAlter(u) : null" @mousemove="listActive = i">
                            <span x-text="u.name"></span>
                            <span class="zb-list-sub"><span x-text="(u.symbol || '') + ' · ' + u.decimal_places + ' dp'"></span></span>
                        </li>
                    </template>
                    <li class="zb-list-empty" x-show="list.length === 0">No units match.</li>
                </ul>
                <div class="zb-browse-detail" x-show="current" x-cloak>
                    <div class="zb-detail-name" x-text="current?.name"></div>
                    <dl class="zb-detail-dl"><dt>Symbol</dt><dd x-text="current?.symbol || '—'"></dd><dt>Decimals</dt><dd x-text="current?.decimal_places"></dd></dl>
                    <p class="text-muted" style="font-size:.76rem">
                        <template x-if="mode === 'alter'"><span><span class="zb-kbd">Enter</span> alter &middot; </span></template>
                        <span class="zb-kbd">Alt+D</span> delete &middot; <span class="zb-kbd">Esc</span> back
                    </p>
                </div>
            </div>
        </div>
    </div>

    {{-- ALTER form --}}
    <form x-show="mode === 'alter-form'" class="zb-panel zb-ws-form" data-zb-form="unit-alter" x-on:zb:commit.prevent="saveAlter()" @submit.prevent>
        <div class="zb-panel-title">Alter Unit</div>
        <div class="zb-form-row"><label for="ua-name">Name</label>
            <input id="ua-name" class="form-control zb-field" data-zb-field data-zb-label="Name" wire:model="u_name" autocomplete="off"></div>
        @error('u_name') <div class="zb-field-error">{{ $message }}</div> @enderror
        <div class="zb-form-row"><label for="ua-symbol">Symbol</label>
            <input id="ua-symbol" class="form-control zb-field" data-zb-field data-zb-label="Symbol" wire:model="u_symbol" autocomplete="off"></div>
        <div class="zb-form-row"><label for="ua-dp">Decimal places</label>
            <input id="ua-dp" type="text" inputmode="decimal" min="0" max="6" class="form-control zb-field" data-zb-field data-zb-label="Decimals" wire:model="u_decimals"></div>
        @error('u_decimals') <div class="zb-field-error">{{ $message }}</div> @enderror
        <p class="text-muted zb-ws-hint"><span class="zb-kbd">Ctrl+A</span> accept &middot; <span class="zb-kbd">Esc</span> back</p>
    </form>

    {{-- delete confirm --}}
    <div class="zb-modal-backdrop" x-show="confirming" x-cloak style="z-index:1260">
        <div class="zb-modal">
            <div class="zb-modal-head">Delete Unit</div>
            <div class="zb-modal-body">Delete “<strong x-text="confirming?.name"></strong>”? This cannot be undone.</div>
            <div class="zb-modal-foot">
                <button type="button" class="btn btn-sm" @click="$store.zb.escape()">Esc — No</button>
                <button type="button" class="btn btn-sm zb-btn-primary" @click="doDelete()">Enter — Yes</button>
            </div>
        </div>
    </div>
</div>
