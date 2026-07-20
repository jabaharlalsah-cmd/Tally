@php($__cc = $this->costCache())
<div class="zb-ws"
     x-data="costCentreWorkspace({ hubUrl: @js(route('masters.index')), costCentres: @js($__cc) })">

    <div class="zb-ws-flash" x-show="flash" x-cloak x-transition
         :class="_flashKind === 'warn' ? 'is-warn' : 'is-ok'" x-text="flash"></div>

    {{-- ================= MENU ================= --}}
    <div x-show="mode === 'menu'" id="ws-menu" tabindex="-1" class="zb-gateway" style="max-width:560px">
        <h1 class="zb-gateway-heading">Cost Centres</h1>
        <p class="zb-gateway-sub">Primary cost category &middot; allocate expenses/incomes analytically</p>
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
            {{ \App\Models\CostCentre::count() }} cost centres
        </p>
    </div>

    {{-- ================= CREATE (single) ================= --}}
    <form x-show="mode === 'create'" class="zb-panel zb-ws-form" data-zb-form="costcentre"
          x-on:zb:commit.prevent="saveCreate()" @submit.prevent>
        <div class="zb-panel-title">Cost Centre Creation</div>

        <div class="zb-form-row">
            <label for="cc-name">Name</label>
            <input id="cc-name" class="form-control zb-field" data-zb-field data-zb-label="Name"
                   wire:model="name" autocomplete="off" placeholder="e.g. North Zone">
        </div>
        @error('name') <div class="zb-field-error">{{ $message }}</div> @enderror

        <div class="zb-form-row">
            <label>Under (parent)</label>
            <x-master-select id="cc-under" source="costCentres" model="parent_id" model-label="parent_label"
                             :allow-primary="false" label="Under"
                             placeholder="Optional — leave blank for a top-level cost centre" />
        </div>
        @error('parent_id') <div class="zb-field-error">{{ $message }}</div> @enderror

        <p class="text-muted zb-ws-hint">
            <span class="zb-kbd">Enter</span> next &middot; <span class="zb-kbd">Ctrl+A</span> accept &middot;
            <span class="zb-kbd">Esc</span> back
        </p>
    </form>

    {{-- ================= CREATE MULTIPLE ================= --}}
    <form x-show="mode === 'multi'" class="zb-panel zb-ws-form" data-zb-form="costcentre-multi"
          x-on:zb:commit.prevent="saveMulti()" @submit.prevent>
        <div class="zb-panel-title">Multi Cost Centre Creation</div>
        <div class="zb-form-row">
            <label>Under (parent)</label>
            <x-master-select id="ccm-under" source="costCentres" model="multi_parent_id" model-label="multi_parent_label"
                             :allow-primary="false" label="Under" placeholder="Optional parent for all rows" />
        </div>
        @error('multi_parent_id') <div class="zb-field-error">{{ $message }}</div> @enderror

        <table class="zb-grid">
            <thead><tr><th style="width:2.5rem">#</th><th>Name of Cost Centre</th></tr></thead>
            <tbody>
                @foreach ($rows as $i => $row)
                    <tr>
                        <td class="zb-grid-idx">{{ $i + 1 }}</td>
                        <td>
                            <input id="ccm-r{{ $i }}-name" class="form-control zb-field zb-grid-input"
                                   data-zb-field data-zb-row="{{ $i }}" data-zb-col="name"
                                   wire:model="rows.{{ $i }}.name" autocomplete="off">
                            @error("rows.$i.name") <div class="zb-field-error">{{ $message }}</div> @enderror
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="text-muted zb-ws-hint">
            <span class="zb-kbd">Enter</span> next &middot; <span class="zb-kbd">↑/↓</span> move rows &middot;
            <span class="zb-kbd">Ctrl+A</span> accept all &middot; <span class="zb-kbd">Esc</span> back
        </p>
    </form>

    {{-- ================= DISPLAY / ALTER (list) ================= --}}
    <div x-show="mode === 'display' || mode === 'alter'" class="zb-ws-browse">
        <div class="zb-panel">
            <div class="zb-panel-title">
                <span x-text="mode === 'alter' ? 'Alter Cost Centre' : 'Display Cost Centre'"></span>
                <span class="text-muted" style="font-size:.72rem;font-weight:400"> — type to filter</span>
            </div>
            <input id="ws-list-search" class="form-control zb-field" x-model="listQuery" @input="onListQuery()"
                   placeholder="Search cost centres…" autocomplete="off" spellcheck="false">
            <div class="zb-browse-split">
                <ul class="zb-list zb-browse-list" id="ws-list">
                    <template x-for="(c, i) in list" :key="c.id">
                        <li class="zb-list-item" :class="{ 'is-active': i === listActive }"
                            @click="listActive = i; mode === 'alter' ? openAlter(c) : null" @mousemove="listActive = i">
                            <span x-text="c.name"></span>
                            <span class="zb-list-sub"><span x-text="c.path"></span></span>
                        </li>
                    </template>
                    <li class="zb-list-empty" x-show="list.length === 0">No cost centres match.</li>
                </ul>
                <div class="zb-browse-detail" x-show="current" x-cloak>
                    <div class="zb-detail-name" x-text="current?.name"></div>
                    <dl class="zb-detail-dl">
                        <dt>Path</dt><dd x-text="current?.path"></dd>
                    </dl>
                    <p class="text-muted" style="font-size:.76rem">
                        <template x-if="mode === 'alter'"><span><span class="zb-kbd">Enter</span> alter &middot; </span></template>
                        <span class="zb-kbd">Alt+D</span> delete &middot; <span class="zb-kbd">Esc</span> back
                    </p>
                </div>
            </div>
        </div>
    </div>

    {{-- ================= ALTER form ================= --}}
    <form x-show="mode === 'alter-form'" class="zb-panel zb-ws-form" data-zb-form="costcentre-alter"
          x-on:zb:commit.prevent="saveAlter()" @submit.prevent>
        <div class="zb-panel-title">Alter Cost Centre</div>

        <div class="zb-form-row">
            <label for="cca-name">Name</label>
            <input id="cca-name" class="form-control zb-field" data-zb-field data-zb-label="Name"
                   wire:model="a_name" autocomplete="off">
        </div>
        @error('a_name') <div class="zb-field-error">{{ $message }}</div> @enderror

        <div class="zb-form-row">
            <label>Under (parent)</label>
            <x-master-select id="cca-under" source="costCentres" model="a_parent_id" model-label="a_parent_label"
                             :allow-primary="false" exclude-model="alter_id" label="Under"
                             placeholder="Optional parent" />
        </div>
        @error('a_parent_id') <div class="zb-field-error">{{ $message }}</div> @enderror

        <p class="text-muted zb-ws-hint">
            <span class="zb-kbd">Ctrl+A</span> accept &middot; <span class="zb-kbd">Esc</span> back
        </p>
    </form>

    {{-- ================= delete confirm ================= --}}
    <div class="zb-modal-backdrop" x-show="confirming" x-cloak style="z-index:1260">
        <div class="zb-modal">
            <div class="zb-modal-head">Delete Cost Centre</div>
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
