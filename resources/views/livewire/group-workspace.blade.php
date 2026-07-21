@php($__cache = $this->mastersCache())
<div class="zb-ws"
     x-data="masterWorkspace({
        kind: 'group',
        title: 'Groups',
        hubUrl: @js(route('masters.index')),
        createFirst: '#g-name',
        multiFirst: '[data-zb-form=\'group-multi\'] .zb-combo-input',
        alterFirst: '#ga-name'
     })"
     x-init="$store.masters.seed(@js($__cache['groups']), @js($__cache['ledgers']))">

    {{-- flash --}}
    <div class="zb-ws-flash" x-show="flash" x-cloak x-transition
         :class="_flashKind === 'warn' ? 'is-warn' : 'is-ok'" x-text="flash"></div>

    {{-- ================= MENU ================= --}}
    <div x-show="mode === 'menu'" id="ws-menu" tabindex="-1" class="zb-gateway" style="max-width:560px">
        <h1 class="zb-gateway-heading">Groups</h1>
        <p class="zb-gateway-sub">Chart of Accounts &middot; Account Groups</p>
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
            {{ \App\Models\AccountGroup::count() }} groups &middot; 28 predefined (reserved)
        </p>
    </div>

    {{-- ================= CREATE (single) ================= --}}
    <form x-show="mode === 'create'" class="zb-panel zb-ws-form" data-zb-form="group"
          x-on:zb:commit.prevent="saveCreate()" @submit.prevent>
        <div class="zb-panel-title">Group Creation</div>

        <div class="zb-form-row">
            <label for="g-name">Name</label>
            <input id="g-name" class="form-control zb-field" data-zb-field data-zb-label="Name"
                   wire:model="name" autocomplete="off" placeholder="e.g. Trade Payables">
        </div>
        @error('name') <div class="zb-field-error">{{ $message }}</div> @enderror

        <div class="zb-form-row">
            <label for="g-alias">Alias</label>
            <input id="g-alias" class="form-control zb-field" data-zb-field data-zb-label="Alias"
                   wire:model="alias" autocomplete="off">
        </div>

        <div class="zb-form-row">
            <label>Under</label>
            <x-master-select id="g-under" source="groups" model="parent_id" model-label="parent_label"
                             create-type="group" :allow-primary="true" label="Under"
                             placeholder="Parent group, or ⌂ Primary (Alt+C to create)" />
        </div>
        @error('parent_id') <div class="zb-field-error">{{ $message }}</div> @enderror

        <div class="zb-form-row" x-show="$wire.parent_id === null">
            <label for="g-nature">Nature of Group</label>
            <select id="g-nature" class="form-select zb-field" data-zb-field data-zb-label="Nature" wire:model="nature">
                <option>Assets</option>
                <option>Liabilities</option>
                <option>Income</option>
                <option>Expenses</option>
            </select>
        </div>
        @error('nature') <div class="zb-field-error">{{ $message }}</div> @enderror

        <p class="text-muted zb-ws-hint">
            <span class="zb-kbd">Enter</span> next &middot; <span class="zb-kbd">Ctrl+A</span> accept &middot;
            <span class="zb-kbd">Alt+C</span> create parent inline &middot; <span class="zb-kbd">Esc</span> back
        </p>
    </form>

    {{-- ================= CREATE MULTIPLE ================= --}}
    <form x-show="mode === 'multi'" class="zb-panel zb-ws-form" data-zb-form="group-multi"
          x-on:zb:commit.prevent="saveMulti()" @submit.prevent>
        <div class="zb-panel-title">Multi Group Creation</div>

        <div class="zb-form-row">
            <label>Under</label>
            <x-master-select id="gm-under" source="groups" model="multi_parent_id" model-label="multi_parent_label"
                             create-type="group" :allow-primary="true" label="Under"
                             placeholder="Parent group for all rows, or ⌂ Primary" />
        </div>
        <div class="zb-form-row" x-show="$wire.multi_parent_id === null">
            <label for="gm-nature">Nature</label>
            <select id="gm-nature" class="form-select zb-field" data-zb-field data-zb-label="Nature" wire:model="multi_nature">
                <option>Assets</option>
                <option>Liabilities</option>
                <option>Income</option>
                <option>Expenses</option>
            </select>
        </div>
        @error('multi_parent_id') <div class="zb-field-error">{{ $message }}</div> @enderror

        <table class="zb-grid">
            <thead><tr><th style="width:2.5rem">#</th><th>Name of Group</th><th>Alias</th></tr></thead>
            <tbody>
                @foreach ($rows as $i => $row)
                    <tr>
                        <td class="zb-grid-idx">{{ $i + 1 }}</td>
                        <td>
                            <input id="gm-r{{ $i }}-name" class="form-control zb-field zb-grid-input"
                                   data-zb-field data-zb-row="{{ $i }}" data-zb-col="name"
                                   wire:model="rows.{{ $i }}.name" autocomplete="off">
                            @error("rows.$i.name") <div class="zb-field-error">{{ $message }}</div> @enderror
                        </td>
                        <td>
                            <input class="form-control zb-field zb-grid-input"
                                   data-zb-field data-zb-row="{{ $i }}" data-zb-col="alias"
                                   wire:model="rows.{{ $i }}.alias" autocomplete="off">
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
                <span x-text="mode === 'alter' ? 'Alter Group' : 'Display Group'"></span>
                <span class="text-muted" style="font-size:.72rem;font-weight:400"> — type to filter</span>
            </div>
            <input id="ws-list-search" class="form-control zb-field" x-model="listQuery" @input="onListQuery()"
                   placeholder="Search groups…" autocomplete="off" spellcheck="false">
            <div class="zb-browse-split">
                <ul class="zb-list zb-browse-list" id="ws-list">
                    <template x-for="(g, i) in list" :key="g.id">
                        <li class="zb-list-item" :class="{ 'is-active': i === listActive }"
                            @click="listActive = i; mode === 'alter' ? openAlter(g) : null" @mousemove="listActive = i">
                            <span x-text="g.name" :class="{ 'is-retired': g.is_active === false }"></span>
                            <span class="zb-list-sub">
                                <span x-text="g.nature"></span>
                                <span class="zb-reserved-tag" x-show="g.is_reserved">reserved</span>
                                <span class="zb-retired-tag" x-show="g.is_active === false" x-cloak>retired</span>
                            </span>
                        </li>
                    </template>
                    <li class="zb-list-empty" x-show="list.length === 0">No groups match.</li>
                </ul>
                <div class="zb-browse-detail" x-show="current" x-cloak>
                    <div class="zb-detail-name" x-text="current?.name"></div>
                    <dl class="zb-detail-dl">
                        <dt>Under</dt><dd x-text="current?.parent_id ? (($store.masters.groupById(current.parent_id)?.name) || '—') : '⌂ Primary'"></dd>
                        <dt>Nature</dt><dd x-text="current?.nature"></dd>
                        <dt>Alias</dt><dd x-text="current?.alias || '—'"></dd>
                        <dt>Path</dt><dd x-text="current?.path"></dd>
                        <dt>Reserved</dt><dd x-text="current?.is_reserved ? 'Yes (non-deletable)' : 'No'"></dd>
                        <dt>Status</dt>
                        <dd>
                            <span x-text="current?.is_active === false ? 'Retired' : 'Active'"></span>
                            {{-- A group with ledgers or sub-groups under it can never be
                                 deleted, so retiring is the only way to take it out of the
                                 pickers. Reserved groups stay active always. --}}
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
    <form x-show="mode === 'alter-form'" class="zb-panel zb-ws-form" data-zb-form="group-alter"
          x-on:zb:commit.prevent="saveAlter()" @submit.prevent>
        <div class="zb-panel-title">
            Alter Group
            <span class="zb-reserved-tag" x-show="$wire.a_is_reserved">reserved — display only</span>
        </div>

        <div class="zb-form-row">
            <label for="ga-name">Name</label>
            <input id="ga-name" class="form-control zb-field" data-zb-field data-zb-label="Name"
                   wire:model="a_name" :disabled="$wire.a_is_reserved" autocomplete="off">
        </div>
        @error('a_name') <div class="zb-field-error">{{ $message }}</div> @enderror

        <div class="zb-form-row">
            <label for="ga-alias">Alias</label>
            <input id="ga-alias" class="form-control zb-field" data-zb-field data-zb-label="Alias"
                   wire:model="a_alias" :disabled="$wire.a_is_reserved" autocomplete="off">
        </div>

        <div class="zb-form-row">
            <label>Under</label>
            <div style="flex:1" x-show="!$wire.a_is_reserved">
                <x-master-select id="ga-under" source="groups" model="a_parent_id" model-label="a_parent_label"
                                 create-type="group" :allow-primary="true" label="Under" exclude-model="alter_id" />
            </div>
            <input class="form-control zb-field" x-show="$wire.a_is_reserved"
                   :value="$wire.a_parent_label || '⌂ Primary'" disabled>
        </div>
        @error('a_parent_id') <div class="zb-field-error">{{ $message }}</div> @enderror

        <div class="zb-form-row" x-show="$wire.a_parent_id === null">
            <label for="ga-nature">Nature of Group</label>
            <select id="ga-nature" class="form-select zb-field" data-zb-field data-zb-label="Nature"
                    wire:model="a_nature" :disabled="$wire.a_is_reserved">
                <option>Assets</option>
                <option>Liabilities</option>
                <option>Income</option>
                <option>Expenses</option>
            </select>
        </div>

        <p class="text-muted zb-ws-hint">
            <span class="zb-kbd">Ctrl+A</span> accept &middot; <span class="zb-kbd">Esc</span> back
        </p>
    </form>

    {{-- ================= inline quick-create group ================= --}}
    @include('partials.quick-group')

    {{-- ================= delete confirm ================= --}}
    <div class="zb-modal-backdrop" x-show="confirming" x-cloak style="z-index:1260">
        <div class="zb-modal">
            <div class="zb-modal-head">Delete Group</div>
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
