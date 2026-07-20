@extends('layouts.app')

@section('title', 'Chart of Accounts — ZeroBook')
@section('region', 'Masters · Chart of Accounts')

@section('content')
    <div class="zb-gateway" x-data="zbGateway({
            items: @js($items),
            name: 'masters.hub',
            label: 'Chart of Accounts',
            focusEl: '#zb-coa',
            hubUrl: @js(route('gateway'))
         })" tabindex="-1" id="zb-coa">
        <h1 class="zb-gateway-heading">Chart of Accounts</h1>
        <p class="zb-gateway-sub">
            Use <span class="zb-kbd">↑</span> <span class="zb-kbd">↓</span> and <span class="zb-kbd">Enter</span>,
            or press the highlighted letter. <span class="zb-kbd">Esc</span> returns to the Gateway.
        </p>

        <ul class="zb-menu" role="menu">
            <template x-for="(it, i) in items" :key="it.label">
                <li class="zb-menu-item" role="menuitem"
                    :class="{ 'is-active': i === active }"
                    @click="active = i; choose(i)" @mousemove="active = i">
                    <span>
                        <span x-text="hotLabel(it).pre"></span><span class="zb-hot" x-text="hotLabel(it).hot"></span><span x-text="hotLabel(it).post"></span>
                    </span>
                    <span class="zb-menu-desc" x-text="it.desc"></span>
                </li>
            </template>
        </ul>
    </div>
@endsection
