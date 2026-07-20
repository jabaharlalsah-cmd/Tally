@php
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
    [$bg, $fg] = $pill($tenant->status);
@endphp

<x-layouts.plain :title="$tenant->id.' — ZeroBook platform'">

    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
        <a href="{{ route('platform.dashboard') }}" class="muted" style="font-size:.85rem">← All tenants</a>
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

    {{-- ── Identity ─────────────────────────────────────────────────────── --}}
    <div class="zb-card">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:.5rem">
            <div>
                <h1 style="margin:0">{{ $tenant->name }}</h1>
                <p class="muted" style="margin:.2rem 0 0">{{ $tenant->id }}.{{ \App\Support\TenantUrl::baseDomain() }} · database <code>{{ $tenant->database()->getName() }}</code></p>
            </div>
            <span class="pill" style="background:{{ $bg }};color:{{ $fg }};font-size:.8rem">{{ $tenant->status }}</span>
        </div>

        <div class="grid" style="margin-top:1rem;grid-template-columns:repeat(auto-fit,minmax(150px,1fr))">
            <div><label style="margin:0">Plan</label><div>{{ $tenant->plan?->name ?? '—' }} <span class="muted">({{ $tenant->plan?->tier ?? '—' }})</span></div></div>
            <div><label style="margin:0">Admin</label><div>{{ $owner?->email ?? '—' }}</div></div>
            <div><label style="margin:0">Provisioned</label><div>{{ $tenant->provisioned_at?->format('d-M-Y H:i') ?? '—' }}</div></div>
            <div><label style="margin:0">Verified</label><div>{{ $tenant->verified_at?->format('d-M-Y') ?? 'not yet' }}</div></div>
            <div><label style="margin:0">Trial ends</label><div>{{ $tenant->trial_ends_at?->format('d-M-Y') ?? '—' }} @if($tenant->onTrial())<span class="muted">({{ $tenant->trialDaysLeft() }}d left)</span>@endif</div></div>
            <div><label style="margin:0">Paid until</label><div>{{ $tenant->plan_ends_at?->format('d-M-Y') ?? '—' }} @if($tenant->subscriptionDaysLeft() !== null)<span class="muted">({{ $tenant->subscriptionDaysLeft() }}d)</span>@endif</div></div>
        </div>
    </div>

    {{-- ── Activity (counts only — never accounting values) ─────────────── --}}
    <div class="zb-card" style="margin-top:1.2rem">
        <h1 style="font-size:1.05rem">Activity summary</h1>
        <p class="sub">Usage signals only — no voucher amounts or ledger names are shown here.</p>
        <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr))">
            <div class="plan"><div class="price">{{ $activity['vouchers_this_month'] ?? '—' }}</div><div class="muted" style="font-size:.78rem">vouchers this month</div></div>
            <div class="plan"><div class="price">{{ $activity['companies'] ?? '—' }}</div><div class="muted" style="font-size:.78rem">companies</div></div>
            <div class="plan"><div class="price">{{ count($users) }}</div><div class="muted" style="font-size:.78rem">users</div></div>
            <div class="plan"><div class="price">{{ $activity['storage_mb'] !== null ? $activity['storage_mb'].' MB' : '—' }}</div><div class="muted" style="font-size:.78rem">database size</div></div>
            <div class="plan"><div class="price" style="font-size:1rem">{{ $activity['last_active_at']?->diffForHumans() ?? 'never' }}</div><div class="muted" style="font-size:.78rem">last active</div></div>
        </div>
    </div>

    {{-- ── Infrastructure (subdomain + database) ─────────────────────────── --}}
    @php
        $linkedDb   = $tenant->getInternal('db_name');
        $dbUser     = $tenant->getInternal('db_username');
        $dbPass     = $tenant->getInternal('db_password');
        $baseDomain = config('hostinger.website_domain', 'zerobook.in');
        $subUrl     = 'https://'.$tenant->id.'.'.$baseDomain;
        $notActive  = ! in_array($tenant->status, ['active'], true) && ! $tenant->isOffboarding() && ! $tenant->isClosed();
    @endphp

    @if ($linkedDb)
        <div class="zb-card" style="margin-top:1.2rem">
            <h1 style="font-size:1.05rem">Infrastructure</h1>
            <div class="grid" style="grid-template-columns:1fr 1fr;gap:1rem;margin-top:.5rem">
                <div>
                    <label>Subdomain</label>
                    <input type="text" value="{{ $subUrl }}" onclick="this.select()" readonly>
                    <p class="muted" style="font-size:.75rem;margin:.25rem 0 0"><a href="{{ $subUrl }}/app" target="_blank" rel="noopener">Open tenant app ↗</a></p>
                </div>
                <div>
                    <label>Database name</label>
                    <input type="text" value="{{ $linkedDb }}" onclick="this.select()" readonly>
                </div>
                <div>
                    <label>Database user</label>
                    <input type="text" value="{{ $dbUser ?: 'main login' }}" onclick="this.select()" readonly>
                </div>
                <div>
                    <label>Database password <span class="muted">(click the eye to reveal)</span></label>
                    <input type="password" value="{{ $dbPass ?: '' }}" placeholder="{{ $dbPass ? '' : 'main login' }}" onclick="this.select()" readonly>
                </div>
            </div>

            @if ($dbPass)
                <details style="margin-top:1rem">
                    <summary class="muted" style="font-size:.82rem;cursor:pointer">Change database password (syncs to Hostinger)</summary>
                    <form method="POST" action="{{ route('platform.db-password', $tenant->id) }}" style="margin-top:.6rem">
                        @csrf
                        <div class="grid" style="grid-template-columns:2fr 1fr;gap:1rem;align-items:end">
                            <div>
                                <label for="new_db_password">New password <span class="muted">(leave blank to auto-generate a strong one)</span></label>
                                <input id="new_db_password" type="password" name="db_password" placeholder="auto-generate" autocomplete="new-password">
                            </div>
                            <div>
                                <button type="submit" class="btn btn-block">Change &amp; sync</button>
                            </div>
                        </div>
                        <p class="muted" style="font-size:.75rem;margin:.5rem 0 0">
                            Changes the real MySQL password on Hostinger <strong>and</strong> updates the copy the app connects with — in one step, so they can’t drift out of sync.
                        </p>
                    </form>
                </details>
            @endif
        </div>
    @endif

    {{-- ── Auto-provision infrastructure (Hostinger API) ─────────────────── --}}
    @if ($notActive)
        <div class="zb-card" style="margin-top:1.2rem;border-color:#b8e2cf">
            <h1 style="font-size:1.05rem">Create infrastructure &amp; activate</h1>
            <p class="muted" style="font-size:.85rem;margin-top:.2rem">
                Automatically create this tenant’s <strong>subdomain</strong> (<code>{{ $tenant->id }}.{{ $baseDomain }}</code>)
                and its own <strong>database</strong> via the Hostinger API, then migrate, seed and activate. No manual hPanel steps.
            </p>
            <form method="POST" action="{{ route('platform.auto-provision', $tenant->id) }}" style="margin-top:.6rem">
                @csrf
                <div class="grid" style="grid-template-columns:1fr 1fr;gap:1rem;align-items:end">
                    <div>
                        <label for="country">Country (tax regime)</label>
                        <select id="country" name="country">
                            @foreach (array_keys((array) config('zerobook.countries', [])) as $c)
                                <option value="{{ $c }}" @selected(old('country', 'india') === $c)>{{ ucfirst($c) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <button type="submit" class="btn btn-block">Create database + subdomain &amp; activate</button>
                    </div>
                </div>
            </form>

            <details style="margin-top:1rem">
                <summary class="muted" style="font-size:.8rem;cursor:pointer">Advanced — link an existing database manually</summary>
                <form method="POST" action="{{ route('platform.link-database', $tenant->id) }}" style="margin-top:.6rem">
                    @csrf
                    <div class="grid" style="grid-template-columns:1fr 1fr;gap:1rem">
                        <div>
                            <label for="db_name">Database name</label>
                            <input id="db_name" type="text" name="db_name" value="{{ old('db_name', $linkedDb) }}" placeholder="u958726172_{{ $tenant->id }}" onclick="this.select()">
                        </div>
                        <div>
                            <label for="country_manual">Country (tax regime)</label>
                            <select id="country_manual" name="country">
                                @foreach (array_keys((array) config('zerobook.countries', [])) as $c)
                                    <option value="{{ $c }}" @selected(old('country', 'india') === $c)>{{ ucfirst($c) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="db_username">Database user <span class="muted">(optional)</span></label>
                            <input id="db_username" type="text" name="db_username" value="{{ old('db_username') }}" placeholder="reuse main login" autocomplete="off">
                        </div>
                        <div>
                            <label for="db_password">Database password <span class="muted">(optional)</span></label>
                            <input id="db_password" type="password" name="db_password" placeholder="reuse main login" autocomplete="new-password">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-block" style="margin-top:1rem;background:#5c6b63">Link existing database &amp; activate</button>
                </form>
            </details>
        </div>
    @endif

    {{-- ── Edit details (company + owner) ────────────────────────────────── --}}
    <div class="zb-card" style="margin-top:1.2rem">
        <h1 style="font-size:1.05rem">Edit details</h1>
        <p class="muted" style="font-size:.8rem;margin-top:.2rem">
            The subdomain (<code>{{ $tenant->id }}</code>) is permanent — it names the database and the tenant’s URL.
            Password is changed below; plan and status have their own controls.
        </p>

        <form method="POST" action="{{ route('platform.update-details', $tenant->id) }}" style="margin-top:.6rem">
            @csrf
            @if ($owner)
                <input type="hidden" name="owner_id" value="{{ $owner->id }}">
            @endif
            <div class="grid" style="grid-template-columns:1fr 1fr 1fr 1fr;align-items:end">
                <div>
                    <label for="ed_company">Company name</label>
                    <input id="ed_company" type="text" name="company_name" value="{{ old('company_name', $tenant->name) }}" onclick="this.select()" required>
                </div>
                <div>
                    <label for="ed_owner_name">Owner name</label>
                    <input id="ed_owner_name" type="text" name="owner_name" value="{{ old('owner_name', $owner?->name) }}" onclick="this.select()" @disabled(! $owner)>
                </div>
                <div>
                    <label for="ed_owner_mobile">Owner mobile</label>
                    <input id="ed_owner_mobile" type="text" name="owner_mobile" value="{{ old('owner_mobile', $owner?->mobile) }}" placeholder="9876543210" onclick="this.select()" @disabled(! $owner)>
                </div>
                <div>
                    <label for="ed_owner_email">Owner email</label>
                    <input id="ed_owner_email" type="email" name="owner_email" value="{{ old('owner_email', $owner?->email) }}" onclick="this.select()" @disabled(! $owner)>
                </div>
            </div>
            @unless ($owner)
                <p class="muted" style="font-size:.75rem;margin:.5rem 0 0">Owner fields unlock once a login exists (create one below).</p>
            @endunless
            <button type="submit" class="btn btn-block" style="margin-top:1rem">Save details</button>
        </form>
    </div>

    {{-- ── Tenant login (create owner / reset password) ──────────────────── --}}
    <div class="zb-card" style="margin-top:1.2rem">
        <h1 style="font-size:1.05rem">Tenant login</h1>

        @if (count($users))
            <table style="margin-top:.5rem">
                <thead>
                    <tr><th>Email</th><th>Name</th><th>Mobile</th><th>Role</th><th>Verified</th><th style="width:40%">Reset password</th></tr>
                </thead>
                <tbody>
                @foreach ($users as $u)
                    <tr>
                        <td>{{ $u->email }}</td>
                        <td>{{ $u->name }}</td>
                        <td>{{ $u->mobile ?: '—' }}</td>
                        <td>{{ $u->role }}</td>
                        <td>{{ $u->verified_at ? 'yes' : 'no' }}</td>
                        <td>
                            <form method="POST" action="{{ route('platform.tenant-user.password', [$tenant->id, $u->id]) }}"
                                  style="display:flex;gap:.4rem;align-items:center;margin:0">
                                @csrf
                                <input type="password" name="password" placeholder="blank = auto-generate" autocomplete="new-password">
                                <button class="btn" style="padding:.5rem .8rem;font-size:.8rem;white-space:nowrap">Reset</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <p class="muted" style="font-size:.75rem;margin-top:.5rem">
                The new password is shown once, at the top of this page — copy it before navigating away.
            </p>
        @else
            <p class="muted" style="font-size:.85rem;margin-top:.2rem">
                This tenant has <strong>no login yet</strong> — manually provisioned tenants don’t get one automatically.
                Create the owner login below; it can sign in immediately at
                <a href="https://{{ $tenant->id }}.{{ config('hostinger.website_domain', 'zerobook.in') }}/login" target="_blank" rel="noopener">{{ $tenant->id }}.{{ config('hostinger.website_domain', 'zerobook.in') }}/login</a>.
            </p>

            <form method="POST" action="{{ route('platform.tenant-user.create', $tenant->id) }}" style="margin-top:.6rem">
                @csrf
                <div class="grid" style="grid-template-columns:1fr 1fr;gap:1rem">
                    <div>
                        <label for="tu_name">Owner name</label>
                        <input id="tu_name" type="text" name="name" value="{{ old('name') }}" required>
                    </div>
                    <div>
                        <label for="tu_email">Owner email</label>
                        <input id="tu_email" type="email" name="email" value="{{ old('email') }}" required>
                    </div>
                    <div>
                        <label for="tu_mobile">Mobile</label>
                        <input id="tu_mobile" type="text" name="mobile" value="{{ old('mobile') }}" placeholder="9876543210" onclick="this.select()">
                    </div>
                    <div>
                        <label for="tu_password">Password <span class="muted">(blank = auto-generate)</span></label>
                        <input id="tu_password" type="password" name="password" autocomplete="new-password">
                    </div>
                    <div>
                        <label for="tu_role">Role</label>
                        <select id="tu_role" name="role">
                            <option value="owner">Owner</option>
                            <option value="member">Member</option>
                        </select>
                    </div>
                </div>
                <label style="display:flex;align-items:center;gap:.45rem;margin-top:.8rem;font-weight:400">
                    <input type="checkbox" name="send_welcome" value="1" checked style="width:auto">
                    <span>Email a welcome message with these sign-in credentials</span>
                </label>
                <button type="submit" class="btn btn-block" style="margin-top:.8rem">Create owner login</button>
            </form>
        @endif
    </div>

    {{-- ── Actions ──────────────────────────────────────────────────────── --}}
    <div class="zb-card" style="margin-top:1.2rem">
        <h1 style="font-size:1.05rem">Actions</h1>

        {{-- ═══ Status & access ═══ --}}
        <p class="zb-agroup">Status &amp; access</p>
        <div class="grid" style="grid-template-columns:1fr 1fr;gap:1.2rem">

            {{-- Suspend / reactivate — the contextual action for the current status --}}
            <div class="zb-action">
                @if ($tenant->isOffboarding() || $tenant->isClosed())
                    <label>Suspend / reactivate</label>
                    <p class="zb-hint">This account is being <strong>offboarded</strong> ({{ $tenant->lifecycleLabel() }}). Manage it from the <em>Offboarding lifecycle</em> card below — reactivating here would leave the closure schedule dangling.</p>
                @elseif ($tenant->status === 'active')
                    <form method="POST" action="{{ route('platform.suspend', $tenant->id) }}" class="zb-action">
                        @csrf
                        <label>Suspend</label>
                        <p class="zb-hint">The tenant keeps read access to their books, but cannot post or edit.</p>
                        <input type="text" name="reason" placeholder="Reason (optional)" onclick="this.select()">
                        <button class="btn btn-block" style="background:#b23b32;margin-top:.5rem">Suspend tenant</button>
                    </form>
                @elseif (in_array($tenant->status, ['suspended', 'expired_trial'], true))
                    <form method="POST" action="{{ route('platform.reactivate', $tenant->id) }}" class="zb-action">
                        @csrf
                        <label>Reactivate</label>
                        <p class="zb-hint">Restores full write access for the tenant.</p>
                        <input type="text" name="reason" placeholder="Reason (optional)" onclick="this.select()">
                        <button class="btn btn-block" style="margin-top:.5rem">Reactivate tenant</button>
                    </form>
                @else
                    <label>Suspend / reactivate</label>
                    <p class="zb-hint">Not available while the tenant is “{{ $tenant->status }}” — finish provisioning or set it active first.</p>
                @endif
            </div>

            {{-- Change status (manual override) --}}
            <div class="zb-action">
                <form method="POST" action="{{ route('platform.set-status', $tenant->id) }}" class="zb-action">
                    @csrf
                    <label for="status">Change status <span class="muted">(manual override)</span></label>
                    <p class="zb-hint">Flips the status flag only — it does not create or remove the database/subdomain.</p>
                    <select id="status" name="status">
                        @foreach (['provisioning','pending_verification','active','suspended','expired_trial','expired_subscription','cancelled'] as $s)
                            <option value="{{ $s }}" @selected($tenant->status === $s)>{{ $s }}</option>
                        @endforeach
                    </select>
                    <input type="text" name="reason" placeholder="Reason (optional)" style="margin-top:.5rem" onclick="this.select()">
                    <button class="btn btn-block" style="margin-top:.5rem;background:#97590a">Set status</button>
                </form>
            </div>
        </div>

        {{-- ═══ Subscription ═══ --}}
        <p class="zb-agroup">Subscription</p>
        <div class="grid" style="grid-template-columns:1fr 1fr;gap:1.2rem">

            {{-- Extend trial --}}
            <div class="zb-action">
                <form method="POST" action="{{ route('platform.extend-trial', $tenant->id) }}" class="zb-action">
                    @csrf
                    <label for="days">Extend trial</label>
                    <p class="zb-hint">Adds days to the current trial window.</p>
                    <div style="display:flex;gap:.5rem;align-items:center">
                        <input id="days" type="number" name="days" value="30" min="1" max="365" style="width:90px" onclick="this.select()" required>
                        <input type="text" name="reason" placeholder="Reason (optional)" style="flex:1" onclick="this.select()">
                    </div>
                    <button class="btn btn-block" style="margin-top:.5rem">Add days to trial</button>
                </form>
            </div>

            {{-- Change plan — master list managed on the Plans page (gear) --}}
            <div class="zb-action">
                <form method="POST" action="{{ route('platform.plan', $tenant->id) }}" class="zb-action">
                    @csrf
                    <div class="d-flex" style="display:flex;align-items:center;justify-content:space-between;gap:.5rem">
                        <label for="tier" style="margin-bottom:0">Change plan <span class="muted">(manual — before billing)</span></label>
                        <a href="{{ route('platform.plans') }}" class="muted" title="Manage plans" style="line-height:0;flex:none">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                        </a>
                    </div>
                    <p class="zb-hint">Current: <strong>{{ $tenant->plan?->name ?? '—' }}</strong></p>
                    <select id="tier" name="tier" required>
                        @foreach ($plans as $plan)
                            <option value="{{ $plan->tier }}" {{ $tenant->plan?->tier === $plan->tier ? 'selected' : '' }}>{{ $plan->name }} ({{ $plan->tier }})</option>
                        @endforeach
                    </select>
                    <input type="text" name="reason" placeholder="Reason (optional)" style="margin-top:.5rem" onclick="this.select()">
                    <button class="btn btn-block" style="margin-top:.5rem">Change plan</button>
                </form>
            </div>
        </div>

        {{-- ═══ Support ═══ --}}
        <p class="zb-agroup">Support</p>
        <div class="grid" style="grid-template-columns:1fr 1fr;gap:1.2rem">

            {{-- Impersonate --}}
            <div class="zb-action">
                <form method="POST" action="{{ route('platform.impersonate', $tenant->id) }}" class="zb-action">
                    @csrf
                    <label>Impersonate for support</label>
                    <p class="zb-hint">Signs you into the tenant’s books as their user. Always logged.</p>
                    <input type="text" name="reason" placeholder="Reason (required — logged)" onclick="this.select()" required>
                    <label style="font-weight:400;margin-top:.5rem;display:flex;align-items:center;gap:.4rem">
                        <input type="checkbox" name="write" value="1" style="width:auto"> Enable write actions
                    </label>
                    <button class="btn btn-block" style="background:#5b4fa3;margin-top:.5rem">Start impersonation →</button>
                </form>
            </div>
            <div></div>
        </div>
    </div>

    {{-- ── Subscription & payments (Phase 14B) ──────────────────────────── --}}
    <div class="zb-card" style="margin-top:1.2rem">
        <div style="display:flex;justify-content:space-between;align-items:center">
            <h1 style="font-size:1.05rem">Subscription & payments</h1>
            <a href="{{ route('platform.payments.record', ['tenant' => $tenant->id]) }}" class="btn" style="padding:.35rem .8rem;text-decoration:none">Record a payment</a>
        </div>
        <p class="sub">Paid until <strong>{{ $tenant->plan_ends_at?->format('d-M-Y') ?? '—' }}</strong>. Confirming a payment extends this; reversing a confirmed payment recomputes it chronologically.</p>
        <div style="overflow-x:auto">
            <table>
                <thead><tr><th>Received</th><th>Amount</th><th>Mode / Ref</th><th>Plan</th><th>Period</th><th>Status</th><th>Files</th><th></th></tr></thead>
                <tbody>
                    @forelse ($payments as $pmt)
                        @php $ps = match ($pmt->status) {
                            'confirmed' => ['#e8f6ef', '#084f39'], 'pending' => ['#fff4e0', '#97590a'],
                            default => ['#fdeceb', '#b23b32'],
                        }; @endphp
                        <tr>
                            <td class="muted">{{ $pmt->received_at?->format('d-M-Y') }}</td>
                            <td><strong>{{ $pmt->formattedAmount() }}</strong></td>
                            <td class="muted">{{ str_replace('_', ' ', $pmt->payment_mode) }}<br>{{ $pmt->reference_number ?: '—' }}</td>
                            <td class="muted">{{ $pmt->plan?->name ?? '—' }}</td>
                            <td class="muted" style="font-size:.72rem">{{ $pmt->subscription_period_start?->format('d-M-y') }} → {{ $pmt->subscription_period_end?->format('d-M-y') }}</td>
                            <td>
                                <span class="pill" style="background:{{ $ps[0] }};color:{{ $ps[1] }}">{{ $pmt->status }}</span>
                                @if ($pmt->status === 'reversed' && $pmt->reversal_reason)<div class="muted" style="font-size:.7rem">↩ {{ $pmt->reversal_reason }}</div>@endif
                                @if ($pmt->status === 'rejected' && $pmt->rejection_reason)<div class="muted" style="font-size:.7rem">✕ {{ $pmt->rejection_reason }}</div>@endif
                            </td>
                            <td style="font-size:.75rem">
                                @if ($pmt->proof_file_path)<a href="{{ route('platform.payments.proof', $pmt->id) }}" target="_blank">proof</a>@endif
                                @if ($pmt->invoice_file_path) · <a href="{{ route('platform.payments.invoice', $pmt->id) }}" target="_blank">invoice</a>@endif
                            </td>
                            <td>
                                @if ($pmt->status === 'confirmed')
                                    <form method="POST" action="{{ route('platform.payments.reverse', $pmt->id) }}" style="display:flex;gap:.25rem" onsubmit="return confirm('Reverse this payment? The plan end date will be recomputed.')">
                                        @csrf
                                        <input type="text" name="reason" placeholder="Reverse reason" required style="width:120px;padding:.28rem;font-size:.75rem">
                                        <button class="btn" style="background:#b23b32;padding:.28rem .5rem;font-size:.75rem">Reverse</button>
                                    </form>
                                @elseif ($pmt->status === 'pending')
                                    <form method="POST" action="{{ route('platform.payments.confirm', $pmt->id) }}">@csrf<button class="btn" style="padding:.28rem .6rem;font-size:.75rem">Confirm</button></form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="muted">No payments recorded yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- ── Backups & data export (Phase 14C) ────────────────────────────── --}}
    <div class="zb-card" style="margin-top:1.2rem">
        <div style="display:flex;justify-content:space-between;align-items:center">
            <h1 style="font-size:1.05rem">Backups &amp; data</h1>
            @unless (in_array($tenant->status, ['purged']))
                <form method="POST" action="{{ route('platform.backup', $tenant->id) }}">@csrf<button class="btn" style="padding:.35rem .8rem">Take backup now</button></form>
            @endunless
        </div>
        <p class="sub">Nightly verified backups (7 daily / 4 weekly / 12 monthly, longer on enterprise). Restore always lands in a fresh review DB.</p>
        <div style="overflow-x:auto">
            <table>
                <thead><tr><th>Taken</th><th>Type</th><th>Size</th><th>Verified</th><th>Expires</th><th>Vouchers</th><th></th></tr></thead>
                <tbody>
                    @forelse ($backups as $bk)
                        <tr>
                            <td class="muted">{{ $bk->taken_at?->format('d-M-Y H:i') }}</td>
                            <td class="muted">{{ str_replace('_', ' ', $bk->type) }}</td>
                            <td class="muted">{{ $bk->humanSize() }}</td>
                            <td>@if($bk->verified_at)<span class="pill" style="background:#e8f6ef;color:#084f39">✓</span>@else<span class="muted">—</span>@endif</td>
                            <td class="muted">{{ $bk->expires_at?->format('d-M-Y') }}</td>
                            <td class="muted">{{ $bk->meta['vouchers'] ?? '—' }}</td>
                            <td>
                                <form method="POST" action="{{ route('platform.restore', [$tenant->id, $bk->id]) }}" onsubmit="return confirm('Restore this backup into a NEW review database? The original is untouched.')">
                                    @csrf<button class="btn" style="padding:.28rem .6rem;font-size:.75rem;background:#5b4fa3">Restore</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="muted">No backups yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($exports->isNotEmpty())
            <p class="muted" style="font-size:.78rem;margin:.8rem 0 .3rem">Customer data exports</p>
            <div style="overflow-x:auto">
                <table>
                    <thead><tr><th>Requested</th><th>Status</th><th>Size</th><th>By</th><th>Downloaded</th></tr></thead>
                    <tbody>
                        @foreach ($exports as $ex)
                            <tr>
                                <td class="muted">{{ $ex->created_at?->format('d-M-Y H:i') }}</td>
                                <td class="muted">{{ $ex->status }}</td>
                                <td class="muted">{{ $ex->file_size_bytes ? $ex->humanSize() : '—' }}</td>
                                <td class="muted" style="font-size:.72rem">{{ $ex->initiated_by_admin_id ? 'admin' : ($ex->initiated_by_user_id ? 'customer' : 'system') }}</td>
                                <td class="muted">{{ $ex->downloaded_at?->format('d-M-Y') ?? 'no' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- ── Offboarding lifecycle (Phase 14C) ─────────────────────────────── --}}
    <div class="zb-card" style="margin-top:1.2rem">
        <h1 style="font-size:1.05rem">Offboarding lifecycle</h1>
        <p class="sub">{{ $tenant->lifecycleLabel() }}
            @if($tenant->archive_scheduled_for) · archives {{ $tenant->archive_scheduled_for->format('d-M-Y') }}@endif
            @if($tenant->purge_scheduled_for) · purge date {{ $tenant->purge_scheduled_for->format('d-M-Y') }}@endif
        </p>

        <div style="display:flex;gap:.6rem;flex-wrap:wrap;margin-bottom:1rem">
            @if (! $tenant->isClosed() && ! $tenant->isOffboarding())
                <form method="POST" action="{{ route('platform.offboarding.initiate', $tenant->id) }}" onsubmit="return confirm('Initiate account closure? The tenant goes read-only and a final backup + export are made.')">
                    @csrf<input type="hidden" name="reason" value="admin-initiated"><button class="btn" style="background:#97590a">Initiate offboarding</button>
                </form>
            @endif
            @if ($tenant->isOffboarding() || in_array($tenant->status, ['archived', 'purge_scheduled']))
                <form method="POST" action="{{ route('platform.offboarding.reactivate', $tenant->id) }}" onsubmit="return confirm('Reactivate this account?')">
                    @csrf<button class="btn">Reactivate</button>
                </form>
            @endif
            @if (in_array($tenant->status, ['archived', 'purge_scheduled']))
                <form method="POST" action="{{ route('platform.offboarding.purge', $tenant->id) }}" style="display:flex;gap:.3rem" onsubmit="return confirm('PERMANENTLY delete all data for this tenant? This cannot be undone.')">
                    @csrf
                    <input type="text" name="confirm" placeholder="type {{ $tenant->id }}" required style="width:130px;padding:.3rem;font-size:.78rem">
                    <button class="btn" style="background:#b23b32">Purge now</button>
                </form>
            @endif
        </div>

        <div style="overflow-x:auto">
            <table>
                <thead><tr><th>When</th><th>Event</th><th>By</th><th>Notes</th></tr></thead>
                <tbody>
                    @forelse ($lifecycle as $ev)
                        <tr>
                            <td class="muted">{{ $ev->created_at?->format('d-M-Y H:i') }}</td>
                            <td><strong>{{ str_replace('_', ' ', $ev->event) }}</strong></td>
                            <td class="muted" style="font-size:.72rem">{{ $ev->admin?->email ?? ($ev->triggered_by_user_id ? 'customer' : 'system') }}</td>
                            <td class="muted" style="font-size:.78rem">{{ $ev->notes }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="muted">No lifecycle events.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- ── Audit log ────────────────────────────────────────────────────── --}}
    <div class="zb-card" style="margin-top:1.2rem">
        <h1 style="font-size:1.05rem">Audit log</h1>
        <div style="overflow-x:auto">
            <table>
                <thead><tr><th>When</th><th>Action</th><th>Admin</th><th>Reason</th><th>Detail</th></tr></thead>
                <tbody>
                    @forelse ($log as $entry)
                        <tr>
                            <td class="muted">{{ $entry->created_at?->format('d-M-Y H:i') }}</td>
                            <td><strong>{{ $entry->action }}</strong></td>
                            <td class="muted">{{ $entry->admin?->email ?? '—' }}</td>
                            <td class="muted">{{ $entry->reason ?? '—' }}</td>
                            <td class="muted" style="font-size:.78rem">{{ $entry->meta ? json_encode($entry->meta) : '' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="muted">No actions recorded yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-layouts.plain>
