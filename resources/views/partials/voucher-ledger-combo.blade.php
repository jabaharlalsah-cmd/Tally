{{-- Reusable inline ledger picker for voucher lines (zbSelect event-sink).
     Vars: $comboId, $initId, $initLabel (JS expressions), $col, $dataLine (raw attr or ''). --}}
<div class="zb-combo"
     x-data="zbSelect({ id: {{ $comboId }}, source: 'ledgers', sink: 'event', createType: 'ledger', initialId: {{ $initId }}, initialLabel: {{ $initLabel }}, label: 'Ledger', placeholder: 'Ledger name' })"
     @click.outside="close()">
    <input type="text" class="form-control zb-field zb-combo-input" x-ref="input"
           data-zb-field data-zb-combo-input {!! $dataLine ?? '' !!} data-col="{{ $col }}" data-zb-label="Ledger"
           :value="open ? query : selLabel"
           @focus="onFocus()" x-on:zb-open="openList()"
           @input="query = $event.target.value; onInput()"
           autocomplete="off" spellcheck="false" placeholder="Ledger name">
    <div class="zb-combo-panel" x-show="open" x-ref="panel" x-cloak>
        <template x-for="(item, i) in filtered" :key="item.id">
            <div class="zb-combo-item" :class="{ 'is-active': i === active }"
                 @mousedown.prevent="pick(item)" @mousemove="active = i">
                <span class="zb-combo-item-name" x-text="item.name"></span>
                <span class="zb-combo-item-sub" x-text="item.group || ''"></span>
            </div>
        </template>
        <div class="zb-combo-empty" x-show="filtered.length === 0">No match</div>
        <div class="zb-combo-create" @mousedown.prevent="create()">
            <span class="zb-kbd">Alt+C</span> <span>Create ledger</span>
            <span x-show="query.trim()" class="zb-combo-create-q">“<span x-text="query"></span>”</span>
        </div>
    </div>
</div>
