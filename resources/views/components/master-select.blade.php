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
    // Set manage="false" where leaving the screen would be destructive or
    // nonsensical (inside a quick-create modal, for instance).
    'manage' => true,
])
@php
    // Where each master is managed. The gear opens that screen in ALTER mode,
    // which is the manage surface: list, pick, edit, retire, delete.
    $manageRoutes = [
        'groups'      => 'masters.groups',
        'ledgers'     => 'masters.ledgers',
        'costCentres' => 'masters.cost-centres',
        'tdsSections' => 'masters.tds-sections',
        'currencies'  => 'masters.currencies',
        'units'       => 'inventory.units',
        'godowns'     => 'inventory.godowns',
        'stockGroups' => 'inventory.stock-groups',
        'stockItems'  => 'inventory.stock-items',
    ];
    $manageRoute = $manage && isset($manageRoutes[$source])
        ? route($manageRoutes[$source], ['mode' => 'alter'])
        : null;
@endphp
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

@if ($manageRoute)
    {{-- Manage this master list. Sits in the form grid beside the field rather
         than floating, so it lines up down the column.

         It routes through $store.zb.leaveTo(), which warns before discarding a
         half-filled form — the gear is the one control on these screens that
         can lose unsaved work in a single click. The guard lives on the store,
         not on one screen's controller, because this component renders on nine
         different workspaces.

         tabindex=-1 keeps it out of the Enter chain: this is a mouse
         affordance, and the keyboard path to the same place is the Gateway
         (A → the master). --}}
    <button type="button" class="zb-manage-gear" tabindex="-1"
            title="Manage {{ $label }} list"
            aria-label="Manage {{ $label }} list"
            @click="$store.zb.leaveTo(@js($manageRoute))">
        <i class="ti ti-settings"></i>
    </button>
@endif
