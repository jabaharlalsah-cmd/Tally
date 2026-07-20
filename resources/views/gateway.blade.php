@extends('layouts.app')

@section('title', 'Gateway of ZeroBook')
@section('region', 'Gateway')

@section('content')
    <div class="zb-gateway" x-data="zbGateway({ items: @js($items) })" tabindex="-1" id="zb-gateway">
        <h1 class="zb-gateway-heading">Gateway of ZeroBook</h1>
        <p class="zb-gateway-sub">
            Keyboard-first accounting. Use <span class="zb-kbd">↑</span> <span class="zb-kbd">↓</span> and
            <span class="zb-kbd">Enter</span>, or press an item’s highlighted letter.
        </p>

        <ul class="zb-menu" role="menu" x-ref="list">
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

        <p class="text-muted" style="text-align:center;margin-top:1rem;font-size:.78rem">
            Masters &middot; Vouchers &middot; Reports arrive in later phases. This is the Phase&nbsp;1 interaction foundation.
        </p>

        {{-- Account actions. `muted` is not a class in the tenant app CSS — use text-muted.
             Logout is a POST route, so it needs a CSRF form rather than a link. --}}
        <div style="display:flex;justify-content:center;align-items:center;gap:.55rem;margin-top:.5rem;font-size:.78rem">
            <a href="{{ route('tenant.password.change') }}" class="text-muted">Change password</a>
            <span class="text-muted" aria-hidden="true">&middot;</span>
            <form method="POST" action="{{ route('tenant.logout') }}" style="margin:0">
                @csrf
                <button type="submit" class="text-muted"
                        style="background:none;border:0;padding:0;font:inherit;cursor:pointer">Log out</button>
            </form>
        </div>
    </div>
@endsection
