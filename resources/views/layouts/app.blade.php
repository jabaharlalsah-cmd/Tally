<!doctype html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'ZeroBook')</title>

    {{-- Installable app. Standalone display is what reclaims the Tally keys a
         browser tab keeps for itself (F11, F12, Ctrl+T, Alt+D) — shortcut doc §F.1.

         Linked from the TENANT layout only, and with root-relative URLs. ZeroBook
         is multi-tenant by subdomain, and a manifest's scope and start_url are
         origin-bound: relative URLs make each tenant install as its own app on its
         own subdomain. Linking it on the central marketing/signup pages would let
         someone install the landing site instead of their books. --}}
    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="theme-color" content="#0B6E4F">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="ZeroBook">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="/icons/favicon-32.png">
    <link rel="apple-touch-icon" href="/icons/favicon-180.png">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles

    <script>
        window.ZB_CONFIG = @json($zbConfig ?? []);
        window.ZB_NAV = @json($zbNav ?? []);
        window.ZB_FEATURES = @json($zbConfig['features'] ?? (object) []);
        window.ZB_GST = @json($zbConfig['gst'] ?? (object) []);
        window.ZB_VAT = @json($zbConfig['vat'] ?? (object) []);
    </script>
</head>
<body>
    {{-- Phase 14A — persistent impersonation banner on EVERY tenant screen. --}}
    @php $zbImp = \App\Support\TenantGate::impersonationContext(); @endphp
    @if ($zbImp)
        <div style="position:sticky;top:0;z-index:9999;background:{{ $zbImp['write_enabled'] ? '#8a2b22' : '#5b4fa3' }};color:#fff;padding:.5rem .9rem;display:flex;align-items:center;justify-content:space-between;gap:.8rem;flex-wrap:wrap;font-family:Inter,-apple-system,'Segoe UI',sans-serif;font-size:.82rem">
            <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap">
                <strong style="letter-spacing:.02em">⚠ PLATFORM ADMIN IMPERSONATING {{ $zbImp['tenant'] }} — {{ $zbImp['admin_email'] }}</strong>
                <span style="background:rgba(255,255,255,.22);border-radius:20px;padding:.08rem .55rem;font-weight:600">
                    {{ $zbImp['write_enabled'] ? 'WRITE ENABLED' : 'VIEW-ONLY' }}
                </span>
            </div>
            <div style="display:flex;align-items:center;gap:.5rem">
                <form method="POST" action="{{ route('impersonate.write') }}" style="margin:0">
                    @csrf
                    <button type="submit" style="cursor:pointer;background:rgba(255,255,255,.18);color:#fff;border:1px solid rgba(255,255,255,.4);border-radius:6px;padding:.28rem .6rem;font-size:.78rem;font-family:inherit">
                        {{ $zbImp['write_enabled'] ? 'Switch to view-only' : 'Enable writes' }}
                    </button>
                </form>
                <form method="POST" action="{{ route('impersonate.exit') }}" style="margin:0">
                    @csrf
                    <button type="submit" style="cursor:pointer;background:#fff;color:#16211d;border:0;border-radius:6px;padding:.28rem .7rem;font-size:.78rem;font-weight:600;font-family:inherit">
                        Exit impersonation
                    </button>
                </form>
            </div>
        </div>
    @endif

    {{-- Phase 14B — subscription renewal banner on every tenant screen. --}}
    @php $zbSub = \App\Support\SubscriptionBanner::forCurrentTenant(); @endphp
    @if ($zbSub)
        <div style="position:sticky;top:0;z-index:9998;background:{{ $zbSub['bg'] }};color:{{ $zbSub['fg'] }};padding:.45rem .9rem;display:flex;align-items:center;justify-content:space-between;gap:.8rem;flex-wrap:wrap;font-family:Inter,-apple-system,'Segoe UI',sans-serif;font-size:.82rem;border-bottom:1px solid rgba(0,0,0,.08)">
            <span><strong>{{ $zbSub['text'] }}</strong></span>
            <a href="{{ route('subscription') }}" style="background:{{ $zbSub['fg'] }};color:{{ $zbSub['bg'] }};border-radius:6px;padding:.24rem .7rem;font-size:.78rem;font-weight:600;text-decoration:none;white-space:nowrap">Renew / view</a>
        </div>
    @endif

    <div class="zb-app" x-data>

        {{-- ================= TOP: identity / date / period ================= --}}
        <header class="zb-top">
            <div class="zb-top-cell">
                <span class="zb-top-product" x-text="$store.zb.product">ZeroBook</span>
                <span class="zb-top-sub">@yield('region', 'Gateway')</span>
            </div>
            <div class="zb-top-cell zb-top-clickable" @click="$store.zb.emit('zb:open-company')" title="Select company (F3)">
                <span class="zb-top-sub">Company</span>
                <span class="zb-top-value" x-text="$store.zb.company"></span>
                {{-- Phase 12B — the group, when the active company is in one --}}
                <span class="zb-top-sub" x-show="$store.zb.companyGroup" x-cloak x-text="'Group: ' + $store.zb.companyGroup"></span>
            </div>
            <div class="zb-top-spacer"></div>
            <div class="zb-top-cell zb-top-clickable" @click="$store.zb.emit('zb:open-period')" title="Change date/period (F2)">
                <span class="zb-top-sub">Current Date</span>
                <span class="zb-top-value" x-text="$store.zb.dateLabel"></span>
            </div>
            <div class="zb-top-cell zb-top-clickable" @click="$store.zb.emit('zb:open-period')" title="Change date/period (F2)">
                <span class="zb-top-sub">Current Period</span>
                <span class="zb-top-value" x-text="$store.zb.periodLabel"></span>
            </div>
        </header>

        {{-- ================= MAIN: work area + right button bar ============ --}}
        <div class="zb-main">
            <main class="zb-centre" id="zb-centre">
                {{-- Phase 15C — scenario picker + inclusion banner on every report (not on the
                     scenario-management screens themselves), gated by the F11 scenarios flag. --}}
                @if ((request()->routeIs('reports.*') || request()->routeIs('daybook'))
                    && ! request()->routeIs('reports.scenario-*')
                    && app(\App\Services\ScenarioService::class)->enabled())
                    <livewire:scenario-picker />
                @endif
                @yield('content')
            </main>

            {{-- RIGHT context-aware button bar --}}
            <aside class="zb-buttonbar" aria-label="Available actions">
                <template x-for="group in $store.zb.barGroups()" :key="group.label">
                    <div>
                        <div class="zb-buttonbar-group-label" x-text="group.label"></div>
                        <template x-for="item in group.items" :key="item.key">
                            <button type="button" class="zb-btnbar-item"
                                    :aria-disabled="item.disabled && item.disabled()"
                                    @click="item.run($event)">
                                <span class="zb-btnbar-label" x-text="item.label"></span>
                                <span class="zb-kbd" x-text="item.hint"></span>
                            </button>
                        </template>
                    </div>
                </template>
            </aside>
        </div>

        {{-- ================= BOTTOM: status bar ============================ --}}
        <footer class="zb-bottom">
            <span class="zb-status-quit" @click="$store.zb.escape()">
                <span class="zb-kbd">Esc</span> <span x-text="$store.zb.quitLabel()"></span>
            </span>
            <span class="zb-status-accept" @click="$store.zb.commitNearestForm($event)">
                <span class="zb-kbd">Ctrl+A</span> Accept
            </span>
            <span class="text-muted"><span class="zb-kbd">Enter</span> next field</span>
            <span class="text-muted"><span class="zb-kbd">Backspace</span> previous field</span>

            <span class="zb-bottom-spacer"></span>

            <span class="zb-status-flag" :class="{ 'is-on': $store.zb.calc.open }">
                <i class="ti ti-calculator"></i> Calculator
                <strong x-text="$store.zb.calc.open ? 'ON' : 'off'"></strong>
            </span>
            <span class="zb-status-flag" :class="{ 'is-on': $store.zb.quitFlash }" x-show="$store.zb.quitFlash" x-cloak>
                Quit ZeroBook? press Esc again
            </span>
            <span class="zb-status-flag">
                Context <strong x-text="$store.zb.activeLabel()"></strong>
                &middot; depth <strong x-text="$store.zb.depth()"></strong>
            </span>
        </footer>

        {{-- ================= Engine overlays ============================== --}}
        @include('partials.calculator')
        @include('partials.goto')
        @include('partials.company-picker') {{-- Phase 12A — F3 --}}
        @include('partials.period')
        @include('partials.config')
        @include('partials.help') {{-- F1 — shortcut reference --}}
        @include('partials.accept')
    </div>

    @livewireScripts
</body>
</html>
