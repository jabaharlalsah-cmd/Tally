@props([
    'id',
    'source' => 'groups',
    'model' => null,
    'modelLabel' => null,
    'sink' => 'wire',
    'createType' => null,
    'allowPrimary' => false,
    'excludeId' => null,
    'excludeModel' => null,
    'initialId' => null,
    'initialLabel' => '',
    'label' => 'Select',
    'placeholder' => 'Type to search…',
])
{{-- Reusable Tally-style searchable picker. Must live inside a [data-zb-form].
     Filters the client-side masters cache — zero network while typing. --}}
<div class="zb-combo"
     x-data="zbSelect({
        id: @js($id),
        source: @js($source),
        model: @js($model),
        modelLabel: @js($modelLabel),
        sink: @js($sink),
        createType: @js($createType),
        allowPrimary: {{ $allowPrimary ? 'true' : 'false' }},
        excludeId: @js($excludeId),
        excludeModel: @js($excludeModel),
        initialId: @js($initialId),
        initialLabel: @js($initialLabel),
        label: @js($label),
        placeholder: @js($placeholder)
     })"
     x-effect="cfg.model && syncFromWire()"
     @click.outside="close()">
    <input type="text" class="form-control zb-field zb-combo-input" x-ref="input"
           data-zb-field data-zb-combo-input data-zb-label="{{ $label }}"
           :value="open ? query : selLabel"
           @focus="onFocus()"
           x-on:zb-open="openList()"
           @input="query = $event.target.value; onInput()"
           placeholder="{{ $placeholder }}" autocomplete="off" spellcheck="false">

    <div class="zb-combo-panel" x-show="open" x-ref="panel" x-cloak>
        <template x-for="(item, i) in filtered" :key="(item.id === null ? 'primary' : item.id)">
            <div class="zb-combo-item" :class="{ 'is-active': i === active }"
                 @mousedown.prevent="pick(item)" @mousemove="active = i">
                <span class="zb-combo-item-name" x-text="item.name"></span>
                <span class="zb-combo-item-sub" x-text="item.path || item.group || (item.nature || '')"></span>
            </div>
        </template>
        <div class="zb-combo-empty" x-show="filtered.length === 0">No match</div>
        <template x-if="cfg.createType">
            <div class="zb-combo-create" @mousedown.prevent="create()">
                <span class="zb-kbd">Alt+C</span>
                <span>Create <span x-text="cfg.createType"></span></span>
                <span x-show="query.trim()" class="zb-combo-create-q">“<span x-text="query"></span>”</span>
            </div>
        </template>
    </div>
</div>
