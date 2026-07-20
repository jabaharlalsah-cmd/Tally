@extends('layouts.app')

@section('title', 'Inventory Info — ZeroBook')
@section('region', 'Inventory Info')

@section('content')
    <div class="zb-gateway" x-data="zbGateway({
            items: @js($items),
            name: 'inventory.hub',
            label: 'Inventory Info',
            focusEl: '#zb-inv',
            hubUrl: @js(route('gateway'))
         })" tabindex="-1" id="zb-inv">
        <h1 class="zb-gateway-heading">Inventory Info</h1>
        <p class="zb-gateway-sub">
            Stock masters. Use <span class="zb-kbd">↑</span> <span class="zb-kbd">↓</span> and <span class="zb-kbd">Enter</span>,
            or press the highlighted letter. <span class="zb-kbd">Esc</span> returns to the Gateway.
        </p>
        <ul class="zb-menu" role="menu">
            <template x-for="(it, i) in items" :key="it.label">
                <li class="zb-menu-item" role="menuitem" :class="{ 'is-active': i === active }" @click="active = i; choose(i)" @mousemove="active = i">
                    <span><span x-text="hotLabel(it).pre"></span><span class="zb-hot" x-text="hotLabel(it).hot"></span><span x-text="hotLabel(it).post"></span></span>
                    <span class="zb-menu-desc" x-text="it.desc"></span>
                </li>
            </template>
        </ul>
        <p class="text-muted" style="text-align:center;margin-top:1rem;font-size:.78rem">
            Masters only in Phase 6A. Item-invoice mode &amp; Stock Summary arrive in 6B/6C.
        </p>
    </div>
@endsection
