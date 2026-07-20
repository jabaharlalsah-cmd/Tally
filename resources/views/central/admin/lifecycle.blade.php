@php
    $pill = fn (string $s) => match ($s) {
        'active' => ['#e8f6ef', '#084f39'],
        'suspended' => ['#fff4e0', '#97590a'],
        'expired_trial', 'expired_subscription' => ['#f0edfa', '#5b4fa3'],
        'archived' => ['#eceff0', '#5c6b63'],
        'purge_scheduled' => ['#fdeceb', '#b23b32'],
        'purged' => ['#2b2b2b', '#ffffff'],
        'restored' => ['#e8f6ef', '#084f39'],
        default => ['#eceff0', '#5c6b63'],
    };
@endphp

<x-layouts.plain title="Lifecycle queue — ZeroBook platform">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
        <div style="display:flex;gap:1rem;align-items:center">
            <a href="{{ route('platform.dashboard') }}" class="muted" style="font-size:.85rem">Tenants</a>
            <a href="{{ route('platform.payments') }}" class="muted" style="font-size:.85rem">Pending payments</a>
            <strong style="font-size:.85rem">Lifecycle queue</strong>
        </div>
        <form method="POST" action="{{ route('platform.logout') }}">@csrf<button class="btn" style="background:#5c6b63">Sign out</button></form>
    </div>

    @if (session('flash'))<div class="flash">{{ session('flash') }}</div>@endif

    <div class="zb-card">
        <h1 style="font-size:1.15rem">Lifecycle queue ({{ $tenants->count() }})</h1>
        <p class="sub">Every non-active tenant, ordered by its next transition date. Suspended/expired can renew or reactivate; archived can be reactivated or purged; purged is final.</p>

        <div style="overflow-x:auto">
            <table>
                <thead><tr><th>Subdomain</th><th>Company</th><th>Status</th><th>Next transition</th><th>Offboarding since</th></tr></thead>
                <tbody>
                    @forelse ($tenants as $t)
                        @php
                            [$bg, $fg] = $pill($t->status);
                            $next = $t->archive_scheduled_for ?? $t->purge_scheduled_for ?? $t->plan_ends_at ?? $t->trial_ends_at;
                        @endphp
                        <tr style="cursor:pointer" onclick="window.location='{{ route('platform.tenant', $t->id) }}'">
                            <td><strong>{{ $t->id }}</strong></td>
                            <td>{{ $t->name }}</td>
                            <td><span class="pill" style="background:{{ $bg }};color:{{ $fg }}">{{ $t->status }}</span></td>
                            <td class="muted">{{ $next?->format('d-M-Y') ?? '—' }}</td>
                            <td class="muted">{{ $t->offboarding_initiated_at?->format('d-M-Y') ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="muted">Nothing in the lifecycle queue — every tenant is active. 🎉</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-layouts.plain>
