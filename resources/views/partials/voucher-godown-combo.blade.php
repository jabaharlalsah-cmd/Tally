{{-- Godown picker for item-invoice rows (zbSelect event-sink). No inline create —
     godowns are managed from the Inventory masters. Vars: $comboId, $initId,
     $initLabel (JS exprs), $dataLine. --}}
<div class="zb-combo"
     x-data="zbSelect({ id: {{ $comboId }}, source: 'godowns', sink: 'event', createType: null, initialId: {{ $initId }}, initialLabel: {{ $initLabel }}, label: 'Godown', placeholder: 'Godown' })"
     @click.outside="close()">
    <input type="text" class="form-control zb-field zb-combo-input" x-ref="input"
           data-zb-field data-zb-combo-input {!! $dataLine ?? '' !!} data-col="{{ $col ?? 'godown' }}" data-zb-label="Godown"
           :value="open ? query : selLabel"
           @focus="onFocus()" x-on:zb-open="openList()"
           @input="query = $event.target.value; onInput()"
           autocomplete="off" spellcheck="false" placeholder="Godown">
    <div class="zb-combo-panel" x-show="open" x-ref="panel" x-cloak>
        <template x-for="(item, i) in filtered" :key="item.id">
            <div class="zb-combo-item" :class="{ 'is-active': i === active }"
                 @mousedown.prevent="pick(item)" @mousemove="active = i">
                <span class="zb-combo-item-name" x-text="item.name"></span>
                <span class="zb-combo-item-sub" x-text="item.path || ''"></span>
            </div>
        </template>
        <div class="zb-combo-empty" x-show="filtered.length === 0">No match</div>
    </div>
</div>
