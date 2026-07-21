@php($__caches = $this->caches())
<div class="zb-ws"
     x-data="stockItemWorkspace({
        kind: 'Stock Item', source: 'stockItems',
        hubUrl: @js(route('inventory.index')),
        createFirst: '#si-name', alterFirst: '#sia-name', multiFirst: '[data-zb-form=\'stockitem-multi\'] .zb-grid-input',
        seed: @js($__caches['stockItems']),
        stockGroups: @js($__caches['stockGroups']),
        units: @js($__caches['units']),
        godowns: @js($__caches['godowns'])
     })">

    <div class="zb-ws-flash" x-show="flash" x-cloak x-transition :class="_flashKind === 'warn' ? 'is-warn' : 'is-ok'" x-text="flash"></div>

    {{-- MENU --}}
    <div x-show="mode === 'menu'" id="ws-menu" tabindex="-1" class="zb-gateway" style="max-width:560px">
        <h1 class="zb-gateway-heading">Stock Items</h1>
        <p class="zb-gateway-sub">Inventory Info &middot; the things you buy &amp; sell</p>
        <ul class="zb-menu">
            <template x-for="(it, i) in menuItems" :key="it.mode">
                <li class="zb-menu-item" :class="{ 'is-active': i === menuActive }" @click="menuActive = i; enterMode(it.mode)" @mousemove="menuActive = i">
                    <span><span x-text="hotSplit(it).pre"></span><span class="zb-hot" x-text="hotSplit(it).hot"></span><span x-text="hotSplit(it).post"></span></span>
                    <span class="zb-menu-desc" x-text="it.desc"></span>
                </li>
            </template>
        </ul>
        <p class="text-muted" style="text-align:center;margin-top:.9rem;font-size:.78rem">{{ \App\Models\StockItem::count() }} stock items · valued weighted-average</p>
    </div>

    {{-- CREATE (single) --}}
    <form x-show="mode === 'create'" class="zb-panel zb-ws-form" data-zb-form="stockitem" x-on:zb:commit.prevent="saveCreate()" @submit.prevent>
        <div class="zb-panel-title">Stock Item Creation</div>

        <div class="zb-form-row"><label for="si-name">Name</label>
            <input id="si-name" class="form-control zb-field" data-zb-field data-zb-label="Name" wire:model="name" autocomplete="off" placeholder="e.g. Cement 50kg Bag"></div>
        @error('name') <div class="zb-field-error">{{ $message }}</div> @enderror

        <div class="zb-form-row"><label for="si-alias">Alias</label>
            <input id="si-alias" class="form-control zb-field" data-zb-field data-zb-label="Alias" wire:model="alias" autocomplete="off"></div>

        <div class="zb-form-row"><label>Under (Stock Group)</label>
            <x-master-select id="si-under" source="stockGroups" model="stock_group_id" model-label="stock_group_label" create-type="stockgroup" :allow-primary="false" label="Under" placeholder="Stock group (Alt+C to create)" /></div>
        @error('stock_group_id') <div class="zb-field-error">{{ $message }}</div> @enderror

        <div class="zb-form-row"><label>Unit</label>
            <x-master-select id="si-unit" source="units" model="unit_id" model-label="unit_label" create-type="unit" :allow-primary="false" label="Unit" placeholder="Unit (Alt+C to create)" /></div>
        @error('unit_id') <div class="zb-field-error">{{ $message }}</div> @enderror


        {{-- F11-gated tax details — mirrors the LEDGER form exactly (Phase 5E).
             The item's OWN gst_rate/hsn_sac columns, relabelled by the active regime. --}}
        <div x-show="feature('gst') || feature('vat')" x-cloak
             x-init="if (feature('vat') && !$wire.get('gst_rate')) $wire.set('gst_rate', '13', false)">
            <div class="zb-subhead" x-text="feature('vat') ? 'VAT Details' : 'GST Details'">GST Details</div>
            <div class="zb-form-row">
                <label for="si-gstrate" x-text="(feature('vat') ? 'VAT' : 'GST') + ' rate (%)'">GST rate (%)</label>
                <input id="si-gstrate" type="text" inputmode="decimal" class="form-control zb-field" data-zb-field
                       data-zb-label="Tax rate" wire:model="gst_rate" :placeholder="feature('vat') ? 'e.g. 13 (Nepal VAT)' : 'e.g. 18'">
            </div>
            @error('gst_rate') <div class="zb-field-error">{{ $message }}</div> @enderror
            <div class="zb-form-row">
                <label for="si-hsn" x-text="feature('vat') ? 'HS Code' : 'HSN / SAC'">HSN / SAC</label>
                <input id="si-hsn" class="form-control zb-field" data-zb-field data-zb-label="HS code" wire:model="hsn_sac" autocomplete="off">
            </div>
        </div>

        <div class="zb-form-row"><label for="si-costing">Costing Method</label>
            <select id="si-costing" class="form-select zb-field" data-zb-field data-zb-label="Costing method" wire:model="costing_method">
                <option value="weighted_average">Weighted Average</option>
                <option value="fifo">FIFO (First In First Out)</option>
                <option value="lifo">LIFO (Last In First Out)</option>
            </select></div>

        <div class="zb-form-row"><label for="si-reorder">Reorder level</label>
            <input id="si-reorder" type="text" inputmode="decimal" class="form-control zb-field" data-zb-field data-zb-label="Reorder level" wire:model="reorder_level" placeholder="optional"></div>

        <p class="zb-invoice-note">Item-level tax is stored now and consumed by the tax engine in Phase 6B.</p>
        {{-- Opening Balance LAST, as in TallyPrime: identity and
             classification first, the number last. Mirrors the ledger
             master, so the two feel like one app. --}}
        <div class="zb-subhead">Opening Balance</div>
        <div class="zb-form-row zb-opening-triple">
            <label>Qty × Rate = Value</label>
            <div class="zb-opening-fields">
                <input id="si-oqty" type="text" inputmode="decimal" class="form-control zb-field" data-zb-field data-zb-label="Opening qty"
                       wire:model="opening_qty" @input="recomputeOpeningValue('#si-oqty', '#si-orate', '#si-oval')" placeholder="Qty">
                <span class="zb-opening-x">×</span>
                <span class="zb-amt-prefix">{{ baseSymbol() }}</span>
                <input id="si-orate" type="text" inputmode="decimal" class="form-control zb-field" data-zb-field data-zb-label="Opening rate"
                       wire:model="opening_rate" @input="recomputeOpeningValue('#si-oqty', '#si-orate', '#si-oval')" placeholder="Rate">
                <span class="zb-opening-x">=</span>
                <span class="zb-amt-prefix">{{ baseSymbol() }}</span>
                <input id="si-oval" type="text" inputmode="decimal" class="form-control zb-field" data-zb-field data-zb-label="Opening value"
                       wire:model="opening_value" @input="markValueTouched()" placeholder="Value">
            </div>
        </div>
        @error('opening_qty') <div class="zb-field-error">{{ $message }}</div> @enderror
        @error('opening_value') <div class="zb-field-error">{{ $message }}</div> @enderror

        <div class="zb-form-row"><label>Opening Godown</label>
            <x-master-select id="si-godown" source="godowns" model="opening_godown_id" model-label="opening_godown_label" :allow-primary="false" label="Godown" placeholder="Godown" /></div>

        <p class="text-muted zb-ws-hint"><span class="zb-kbd">Enter</span> next &middot; <span class="zb-kbd">Alt+C</span> create group/unit inline &middot; <span class="zb-kbd">Ctrl+A</span> accept &middot; <span class="zb-kbd">Esc</span> back</p>
    </form>

    {{-- CREATE MULTIPLE --}}
    <form x-show="mode === 'multi'" class="zb-panel zb-ws-form" data-zb-form="stockitem-multi" x-on:zb:commit.prevent="saveMulti()" @submit.prevent>
        <div class="zb-panel-title">Multi Stock Item Creation</div>
        <div class="zb-form-row"><label>Under (Stock Group)</label>
            <x-master-select id="sim-under" source="stockGroups" model="multi_group_id" model-label="multi_group_label" create-type="stockgroup" :allow-primary="false" label="Under" placeholder="Common group (Alt+C to create)" /></div>
        <div class="zb-form-row"><label>Unit</label>
            <x-master-select id="sim-unit" source="units" model="multi_unit_id" model-label="multi_unit_label" create-type="unit" :allow-primary="false" label="Unit" placeholder="Common unit (Alt+C to create)" /></div>
        <table class="zb-grid">
            <thead><tr><th style="width:2.5rem">#</th><th>Name of Item</th><th style="width:9rem">Opening Qty</th><th style="width:9rem">Rate</th></tr></thead>
            <tbody>
                @foreach ($rows as $i => $row)
                    <tr>
                        <td class="zb-grid-idx">{{ $i + 1 }}</td>
                        <td><input class="form-control zb-field zb-grid-input" data-zb-field data-zb-row="{{ $i }}" data-zb-col="name" wire:model="rows.{{ $i }}.name" autocomplete="off">
                            @error("rows.$i.name") <div class="zb-field-error">{{ $message }}</div> @enderror</td>
                        <td><input type="text" inputmode="decimal" class="form-control zb-field zb-grid-input" data-zb-field data-zb-row="{{ $i }}" data-zb-col="qty" wire:model="rows.{{ $i }}.qty">
                            @error("rows.$i.qty") <div class="zb-field-error">{{ $message }}</div> @enderror</td>
                        <td><input type="text" inputmode="decimal" class="form-control zb-field zb-grid-input" data-zb-field data-zb-row="{{ $i }}" data-zb-col="rate" wire:model="rows.{{ $i }}.rate">
                            @error("rows.$i.rate") <div class="zb-field-error">{{ $message }}</div> @enderror</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="text-muted zb-ws-hint">Opening value = Qty × Rate (into Main Location). <span class="zb-kbd">Ctrl+A</span> accept all &middot; <span class="zb-kbd">Esc</span> back</p>
    </form>

    {{-- DISPLAY / ALTER (list) --}}
    <div x-show="mode === 'display' || mode === 'alter'" class="zb-ws-browse">
        <div class="zb-panel">
            <div class="zb-panel-title"><span x-text="mode === 'alter' ? 'Alter Stock Item' : 'Display Stock Item'"></span> <span class="text-muted" style="font-size:.72rem;font-weight:400"> — type to filter</span></div>
            <input id="ws-list-search" class="form-control zb-field" x-model="listQuery" @input="onListQuery()" placeholder="Search stock items…" autocomplete="off" spellcheck="false">
            <div class="zb-browse-split">
                <ul class="zb-list zb-browse-list" id="ws-list">
                    <template x-for="(it, i) in list" :key="it.id">
                        <li class="zb-list-item" :class="{ 'is-active': i === listActive }" @click="listActive = i; mode === 'alter' ? openAlter(it) : null" @mousemove="listActive = i">
                            <span x-text="it.name"></span><span class="zb-list-sub"><span x-text="(it.group || '⌂ Primary') + ' · ' + (it.unit || 'no unit')"></span></span>
                        </li>
                    </template>
                    <li class="zb-list-empty" x-show="list.length === 0">No stock items match.</li>
                </ul>
                <div class="zb-browse-detail" x-show="current" x-cloak>
                    <div class="zb-detail-name" x-text="current?.name"></div>
                    <dl class="zb-detail-dl">
                        <dt>Under</dt><dd x-text="current?.group || '⌂ Primary'"></dd>
                        <dt>Unit</dt><dd x-text="current?.unit || '—'"></dd>
                        <dt>Opening</dt><dd x-text="(current?.opening_qty ?? 0) + ' @ ' + (current?.opening_rate ?? 0) + ' = ' + (current?.opening_value ?? 0)"></dd>
                        <dt>Tax rate</dt><dd x-text="current?.gst_rate != null ? current.gst_rate + '%' : '—'"></dd>
                    </dl>
                    <p class="text-muted" style="font-size:.76rem">
                        <template x-if="mode === 'alter'"><span><span class="zb-kbd">Enter</span> alter &middot; </span></template>
                        <span class="zb-kbd">Alt+D</span> delete &middot; <span class="zb-kbd">Esc</span> back
                    </p>
                </div>
            </div>
        </div>
    </div>

    {{-- ALTER form --}}
    <form x-show="mode === 'alter-form'" class="zb-panel zb-ws-form" data-zb-form="stockitem-alter" x-on:zb:commit.prevent="saveAlter()" @submit.prevent>
        <div class="zb-panel-title">Alter Stock Item</div>
        <div class="zb-form-row"><label for="sia-name">Name</label>
            <input id="sia-name" class="form-control zb-field" data-zb-field data-zb-label="Name" wire:model="i_name" autocomplete="off"></div>
        @error('i_name') <div class="zb-field-error">{{ $message }}</div> @enderror
        <div class="zb-form-row"><label for="sia-alias">Alias</label>
            <input id="sia-alias" class="form-control zb-field" data-zb-field data-zb-label="Alias" wire:model="i_alias" autocomplete="off"></div>
        <div class="zb-form-row"><label>Under (Stock Group)</label>
            <x-master-select id="sia-under" source="stockGroups" model="i_stock_group_id" model-label="i_stock_group_label" create-type="stockgroup" :allow-primary="false" label="Under" /></div>
        @error('i_stock_group_id') <div class="zb-field-error">{{ $message }}</div> @enderror
        <div class="zb-form-row"><label>Unit</label>
            <x-master-select id="sia-unit" source="units" model="i_unit_id" model-label="i_unit_label" create-type="unit" :allow-primary="false" label="Unit" /></div>
        @error('i_unit_id') <div class="zb-field-error">{{ $message }}</div> @enderror


        <div x-show="feature('gst') || feature('vat')" x-cloak>
            <div class="zb-subhead" x-text="feature('vat') ? 'VAT Details' : 'GST Details'">GST Details</div>
            <div class="zb-form-row">
                <label for="sia-gstrate" x-text="(feature('vat') ? 'VAT' : 'GST') + ' rate (%)'">GST rate (%)</label>
                <input id="sia-gstrate" type="text" inputmode="decimal" class="form-control zb-field" data-zb-field data-zb-label="Tax rate" wire:model="i_gst_rate" :placeholder="feature('vat') ? 'e.g. 13' : 'e.g. 18'">
            </div>
            @error('i_gst_rate') <div class="zb-field-error">{{ $message }}</div> @enderror
            <div class="zb-form-row">
                <label for="sia-hsn" x-text="feature('vat') ? 'HS Code' : 'HSN / SAC'">HSN / SAC</label>
                <input id="sia-hsn" class="form-control zb-field" data-zb-field data-zb-label="HS code" wire:model="i_hsn_sac" autocomplete="off">
            </div>
        </div>
        <div class="zb-form-row"><label for="sia-costing">Costing Method</label>
            <select id="sia-costing" class="form-select zb-field" data-zb-field data-zb-label="Costing method" wire:model="i_costing_method" @disabled($i_costing_locked)>
                <option value="weighted_average">Weighted Average</option>
                <option value="fifo">FIFO (First In First Out)</option>
                <option value="lifo">LIFO (Last In First Out)</option>
            </select></div>
        @if ($i_costing_locked)
            <p class="text-muted zb-ws-hint">{{ \App\Models\StockItem::COSTING_LOCKED_MESSAGE }}</p>
        @endif

        <div class="zb-form-row"><label for="sia-reorder">Reorder level</label>
            <input id="sia-reorder" type="text" inputmode="decimal" class="form-control zb-field" data-zb-field data-zb-label="Reorder level" wire:model="i_reorder_level"></div>

        {{-- Opening Balance LAST, matching the create form and the ledger
             master. Altering an item must not feel unlike creating one. --}}
        <div class="zb-subhead">Opening Balance</div>
        <div class="zb-form-row zb-opening-triple">
            <label>Qty × Rate = Value</label>
            <div class="zb-opening-fields">
                <input id="sia-oqty" type="text" inputmode="decimal" class="form-control zb-field" data-zb-field data-zb-label="Opening qty"
                       wire:model="i_opening_qty" @input="recomputeAlterOpeningValue('#sia-oqty', '#sia-orate', '#sia-oval')">
                <span class="zb-opening-x">×</span>
                <span class="zb-amt-prefix">{{ baseSymbol() }}</span>
                <input id="sia-orate" type="text" inputmode="decimal" class="form-control zb-field" data-zb-field data-zb-label="Opening rate"
                       wire:model="i_opening_rate" @input="recomputeAlterOpeningValue('#sia-oqty', '#sia-orate', '#sia-oval')">
                <span class="zb-opening-x">=</span>
                <span class="zb-amt-prefix">{{ baseSymbol() }}</span>
                <input id="sia-oval" type="text" inputmode="decimal" class="form-control zb-field" data-zb-field data-zb-label="Opening value"
                       wire:model="i_opening_value" @input="markAlterValueTouched()">
            </div>
        </div>
        <div class="zb-form-row"><label>Opening Godown</label>
            <x-master-select id="sia-godown" source="godowns" model="i_opening_godown_id" model-label="i_opening_godown_label" :allow-primary="false" label="Godown" /></div>

        <p class="text-muted zb-ws-hint"><span class="zb-kbd">Ctrl+A</span> accept &middot; <span class="zb-kbd">Esc</span> back</p>
    </form>

    {{-- inline quick-create sub-screens (Alt+C from the pickers above) --}}
    @include('partials.quick-stock-group')
    @include('partials.quick-unit')

    {{-- delete confirm --}}
    <div class="zb-modal-backdrop" x-show="confirming" x-cloak style="z-index:1260">
        <div class="zb-modal">
            <div class="zb-modal-head">Delete Stock Item</div>
            <div class="zb-modal-body">Delete “<strong x-text="confirming?.name"></strong>”? This cannot be undone.</div>
            <div class="zb-modal-foot">
                <button type="button" class="btn btn-sm" @click="$store.zb.escape()">Esc — No</button>
                <button type="button" class="btn btn-sm zb-btn-primary" @click="doDelete()">Enter — Yes</button>
            </div>
        </div>
    </div>
</div>
