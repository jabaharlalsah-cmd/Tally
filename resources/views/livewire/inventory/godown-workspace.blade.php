@php($__cache = $this->cache())
<div class="zb-ws"
     x-data="inventoryWorkspace({
        kind: 'Godown', source: 'godowns',
        hubUrl: @js(route('inventory.index')),
        createFirst: '#gd-name', alterFirst: '#gda-name', multiFirst: '[data-zb-form=\'godown-multi\'] .zb-grid-input',
        seed: @js($__cache)
     })">

    <div class="zb-ws-flash" x-show="flash" x-cloak x-transition :class="_flashKind === 'warn' ? 'is-warn' : 'is-ok'" x-text="flash"></div>

    {{-- MENU --}}
    <div x-show="mode === 'menu'" id="ws-menu" tabindex="-1" class="zb-gateway" style="max-width:560px">
        <h1 class="zb-gateway-heading">Godowns / Locations</h1>
        <p class="zb-gateway-sub">Inventory Info &middot; where stock is held (nestable)</p>
        <ul class="zb-menu">
            <template x-for="(it, i) in menuItems" :key="it.mode">
                <li class="zb-menu-item" :class="{ 'is-active': i === menuActive }" @click="menuActive = i; enterMode(it.mode)" @mousemove="menuActive = i">
                    <span><span x-text="hotSplit(it).pre"></span><span class="zb-hot" x-text="hotSplit(it).hot"></span><span x-text="hotSplit(it).post"></span></span>
                    <span class="zb-menu-desc" x-text="it.desc"></span>
                </li>
            </template>
        </ul>
        <p class="text-muted" style="text-align:center;margin-top:.9rem;font-size:.78rem">{{ \App\Models\Godown::count() }} godowns &middot; “Main Location” is reserved</p>
    </div>

    {{-- CREATE (single) --}}
    <form x-show="mode === 'create'" class="zb-panel zb-ws-form" data-zb-form="godown" x-on:zb:commit.prevent="saveCreate()" @submit.prevent>
        <div class="zb-panel-title">Godown Creation</div>
        <div class="zb-form-row"><label for="gd-name">Name</label>
            <input id="gd-name" class="form-control zb-field" data-zb-field data-zb-label="Name" wire:model="name" autocomplete="off" placeholder="e.g. Rack A"></div>
        @error('name') <div class="zb-field-error">{{ $message }}</div> @enderror
        <div class="zb-form-row"><label>Under (parent)</label>
            <x-master-select id="gd-under" source="godowns" model="parent_id" model-label="parent_label" :allow-primary="false" label="Under" placeholder="Optional — blank = top-level location" /></div>
        @error('parent_id') <div class="zb-field-error">{{ $message }}</div> @enderror
        <p class="text-muted zb-ws-hint"><span class="zb-kbd">Enter</span> next &middot; <span class="zb-kbd">Ctrl+A</span> accept &middot; <span class="zb-kbd">Esc</span> back</p>
    </form>

    {{-- CREATE MULTIPLE --}}
    <form x-show="mode === 'multi'" class="zb-panel zb-ws-form" data-zb-form="godown-multi" x-on:zb:commit.prevent="saveMulti()" @submit.prevent>
        <div class="zb-panel-title">Multi Godown Creation</div>
        <div class="zb-form-row"><label>Under (parent)</label>
            <x-master-select id="gdm-under" source="godowns" model="multi_parent_id" model-label="multi_parent_label" :allow-primary="false" label="Under" placeholder="Optional parent for all rows" /></div>
        @error('multi_parent_id') <div class="zb-field-error">{{ $message }}</div> @enderror
        <table class="zb-grid">
            <thead><tr><th style="width:2.5rem">#</th><th>Name of Godown</th></tr></thead>
            <tbody>
                @foreach ($rows as $i => $row)
                    <tr>
                        <td class="zb-grid-idx">{{ $i + 1 }}</td>
                        <td><input class="form-control zb-field zb-grid-input" data-zb-field data-zb-row="{{ $i }}" data-zb-col="name" wire:model="rows.{{ $i }}.name" autocomplete="off">
                            @error("rows.$i.name") <div class="zb-field-error">{{ $message }}</div> @enderror</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="text-muted zb-ws-hint"><span class="zb-kbd">Enter</span> next &middot; <span class="zb-kbd">↑/↓</span> rows &middot; <span class="zb-kbd">Ctrl+A</span> accept all &middot; <span class="zb-kbd">Esc</span> back</p>
    </form>

    {{-- DISPLAY / ALTER (list) --}}
    <div x-show="mode === 'display' || mode === 'alter'" class="zb-ws-browse">
        <div class="zb-panel">
            <div class="zb-panel-title"><span x-text="mode === 'alter' ? 'Alter Godown' : 'Display Godown'"></span> <span class="text-muted" style="font-size:.72rem;font-weight:400"> — type to filter</span></div>
            <input id="ws-list-search" class="form-control zb-field" x-model="listQuery" @input="onListQuery()" placeholder="Search godowns…" autocomplete="off" spellcheck="false">
            <div class="zb-browse-split">
                <ul class="zb-list zb-browse-list" id="ws-list">
                    <template x-for="(d, i) in list" :key="d.id">
                        <li class="zb-list-item" :class="{ 'is-active': i === listActive }" @click="listActive = i; mode === 'alter' ? openAlter(d) : null" @mousemove="listActive = i">
                            <span x-text="d.name"></span><span class="zb-list-sub"><span x-text="d.path"></span><span class="zb-reserved-tag" x-show="d.is_reserved">reserved</span></span>
                        </li>
                    </template>
                    <li class="zb-list-empty" x-show="list.length === 0">No godowns match.</li>
                </ul>
                <div class="zb-browse-detail" x-show="current" x-cloak>
                    <div class="zb-detail-name" x-text="current?.name"></div>
                    <dl class="zb-detail-dl"><dt>Path</dt><dd x-text="current?.path"></dd><dt>Reserved</dt><dd x-text="current?.is_reserved ? 'Yes (non-deletable)' : 'No'"></dd></dl>
                    <p class="text-muted" style="font-size:.76rem">
                        <template x-if="mode === 'alter'"><span><span class="zb-kbd">Enter</span> alter &middot; </span></template>
                        <span class="zb-kbd">Alt+D</span> delete &middot; <span class="zb-kbd">Esc</span> back
                    </p>
                </div>
            </div>
        </div>
    </div>

    {{-- ALTER form --}}
    <form x-show="mode === 'alter-form'" class="zb-panel zb-ws-form" data-zb-form="godown-alter" x-on:zb:commit.prevent="saveAlter()" @submit.prevent>
        <div class="zb-panel-title">Alter Godown <span class="zb-reserved-tag" x-show="$wire.d_is_reserved">reserved</span></div>
        <div class="zb-form-row"><label for="gda-name">Name</label>
            <input id="gda-name" class="form-control zb-field" data-zb-field data-zb-label="Name" wire:model="d_name" autocomplete="off"></div>
        @error('d_name') <div class="zb-field-error">{{ $message }}</div> @enderror
        <div class="zb-form-row"><label>Under (parent)</label>
            <div style="flex:1" x-show="!$wire.d_is_reserved">
                <x-master-select id="gda-under" source="godowns" model="d_parent_id" model-label="d_parent_label" :allow-primary="false" exclude-model="alter_id" label="Under" placeholder="Optional parent" />
            </div>
            <input class="form-control zb-field" x-show="$wire.d_is_reserved" :value="$wire.d_parent_label || '⌂ Top level'" disabled>
        </div>
        @error('d_parent_id') <div class="zb-field-error">{{ $message }}</div> @enderror
        <p class="text-muted zb-ws-hint"><span class="zb-kbd">Ctrl+A</span> accept &middot; <span class="zb-kbd">Esc</span> back</p>
    </form>

    {{-- delete confirm --}}
    <div class="zb-modal-backdrop" x-show="confirming" x-cloak style="z-index:1260">
        <div class="zb-modal">
            <div class="zb-modal-head">Delete Godown</div>
            <div class="zb-modal-body">Delete “<strong x-text="confirming?.name"></strong>”? This cannot be undone.</div>
            <div class="zb-modal-foot">
                <button type="button" class="btn btn-sm" @click="$store.zb.escape()">Esc — No</button>
                <button type="button" class="btn btn-sm zb-btn-primary" @click="doDelete()">Enter — Yes</button>
            </div>
        </div>
    </div>
</div>
