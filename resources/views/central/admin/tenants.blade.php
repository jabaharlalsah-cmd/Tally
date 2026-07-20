@php
    // status → [background, text] pill colours
    $pill = function (string $s) {
        return match ($s) {
            'active' => ['#e8f6ef', '#084f39'],
            'provisioning' => ['#fff4e0', '#97590a'],
            'pending_verification' => ['#fef6e0', '#8a6d1a'],
            'suspended' => ['#fdeceb', '#b23b32'],
            'expired_trial' => ['#f0edfa', '#5b4fa3'],
            'cancelled' => ['#eceff0', '#5c6b63'],
            default => ['#eceff0', '#5c6b63'],
        };
    };
@endphp

<x-layouts.plain title="Tenants — ZeroBook platform">

    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
        <div style="display:flex;gap:1rem;align-items:center">
            <strong style="font-size:.85rem">Tenants</strong>
            <a href="{{ route('platform.payments') }}" class="muted" style="font-size:.85rem">Pending payments</a>
            <a href="{{ route('platform.plans') }}" class="muted" style="font-size:.85rem">Plans</a>
            <a href="{{ route('platform.lifecycle') }}" class="muted" style="font-size:.85rem">Lifecycle queue</a>
            <a href="{{ route('platform.password.change') }}" class="muted" style="font-size:.85rem">Change password</a>
            <span class="muted" style="font-size:.8rem">· {{ auth('platform')->user()?->email }}</span>
        </div>
        <form method="POST" action="{{ route('platform.logout') }}">
            @csrf
            <button class="btn" style="background:#5c6b63">Sign out</button>
        </form>
    </div>

    @if (session('flash'))
        <div class="flash">{{ session('flash') }}</div>
    @endif
    @if ($errors->any())
        <div class="err">{{ $errors->first() }}</div>
    @endif

    <div class="zb-card">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.6rem">
            <h1 style="font-size:1.15rem;margin:0">Tenants ({{ $rows->count() }})</h1>

            <div style="display:flex;align-items:center;gap:.9rem;flex-wrap:wrap">
                {{-- Jumps to the provision form below and focuses its first field. --}}
                <a href="#provision" class="btn"
                   style="padding:.42rem .85rem;font-size:.82rem;white-space:nowrap;text-decoration:none"
                   onclick="setTimeout(function(){ var f = document.getElementById('subdomain'); if (f) f.focus(); }, 250)">+ Add tenant</a>

                <form method="GET" action="{{ route('platform.dashboard') }}" style="margin:0">
                    <label style="display:inline;margin:0;font-size:.78rem">Filter</label>
                    <select name="status" onchange="this.form.submit()" style="width:auto;display:inline-block;padding:.35rem .5rem">
                        <option value="all" {{ $statusFilter === '' || $statusFilter === 'all' ? 'selected' : '' }}>All statuses</option>
                        @foreach ($statuses as $s)
                            <option value="{{ $s }}" {{ $statusFilter === $s ? 'selected' : '' }}>{{ $s }}</option>
                        @endforeach
                    </select>
                </form>
            </div>
        </div>

        <div style="overflow-x:auto;margin-top:.8rem">
            <table>
                <thead><tr>
                    <th>Subdomain</th><th>Company</th><th>Plan</th><th>Status</th>
                    <th>Admin email</th><th>Companies</th><th>Provisioned</th><th>Last active</th>
                </tr></thead>
                <tbody>
                    @forelse ($rows as $row)
                        @php [$bg, $fg] = $pill($row['tenant']->status); @endphp
                        <tr style="cursor:pointer" onclick="window.location='{{ route('platform.tenant', $row['tenant']->id) }}'">
                            <td><strong>{{ $row['tenant']->id }}</strong></td>
                            <td>{{ $row['tenant']->name }}</td>
                            <td>{{ $row['tenant']->plan?->tier ?? '—' }}</td>
                            <td><span class="pill" style="background:{{ $bg }};color:{{ $fg }}">{{ $row['tenant']->status }}</span></td>
                            <td class="muted">{{ $row['owner_email'] }}</td>
                            <td class="muted">{{ $row['companies'] ?? '—' }}</td>
                            <td class="muted">{{ $row['tenant']->provisioned_at?->format('d-M-Y') ?? '—' }}</td>
                            <td class="muted">{{ $row['tenant']->last_active_at?->diffForHumans() ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="muted">No tenants match this filter.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="zb-card" id="provision" style="margin-top:1.4rem;scroll-margin-top:1rem">
        <h1 style="font-size:1.05rem">Provision a tenant manually</h1>
        <p class="sub">
            For onboarding a pilot yourself. Customers self-serve at the public signup page.
            Creates the subdomain + database, the owner login, and emails them a welcome with their credentials.
        </p>
        <form method="POST" action="{{ route('platform.provision') }}">
            @csrf

            <div class="grid" style="grid-template-columns:1fr 1fr 1fr 1fr;align-items:end">
                <div>
                    <label for="subdomain">Subdomain</label>
                    <input id="subdomain" type="text" name="subdomain" value="{{ old('subdomain') }}" placeholder="acme" onclick="this.select()" required>
                </div>
                <div>
                    <label for="name">Company name</label>
                    <input id="name" type="text" name="name" value="{{ old('name') }}" placeholder="Acme Traders" onclick="this.select()" required>
                </div>
                <div>
                    <label for="country">Country</label>
                    <select id="country" name="country">
                        @foreach ($countries as $key => $c)
                            <option value="{{ $key }}" @selected(old('country') === $key)>{{ $c['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="plan">Plan</label>
                    <select id="plan" name="plan">
                        @foreach ($plans as $plan)
                            <option value="{{ $plan->tier }}" @selected(old('plan') === $plan->tier)>{{ $plan->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <p class="muted" style="font-size:.78rem;margin:1rem 0 .2rem">Owner login — emailed to them on creation</p>
            <div class="grid" style="grid-template-columns:1fr 1fr 1fr 1fr;align-items:end">
                <div>
                    <label for="owner_name">Owner name</label>
                    <input id="owner_name" type="text" name="owner_name" value="{{ old('owner_name') }}" placeholder="Ramesh Kumar" onclick="this.select()" required>
                </div>
                <div>
                    <label for="mobile">Mobile</label>
                    <input id="mobile" type="text" name="mobile" value="{{ old('mobile') }}" placeholder="9876543210" onclick="this.select()">
                </div>
                <div>
                    <label for="email">Email</label>
                    <input id="email" type="email" name="email" value="{{ old('email') }}" placeholder="owner@acme.com" onclick="this.select()" required>
                </div>
                <div>
                    <label for="password">Password <span class="muted">(blank = auto)</span></label>
                    <input id="password" type="password" name="password" placeholder="auto-generate" autocomplete="new-password">
                </div>
            </div>

            <button type="submit" class="btn" style="margin-top:1rem">Provision</button>
        </form>
    </div>
</x-layouts.plain>
