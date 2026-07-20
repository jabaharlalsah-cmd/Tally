<div>
    @php
        $banner = $tenant?->subscriptionBanner();
        $daysLeft = $tenant?->subscriptionDaysLeft();
        $statusPill = match ($tenant?->status) {
            'active' => ['#e8f6ef', '#084f39'],
            'expired_subscription', 'expired_trial' => ['#fdeceb', '#b23b32'],
            'suspended' => ['#fdeceb', '#b23b32'],
            default => ['#eceff0', '#5c6b63'],
        };
    @endphp

    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
        <div style="display:flex;gap:1rem;align-items:center">
            <a href="{{ route('gateway') }}" class="muted" style="font-size:.85rem">← Back to Gateway</a>
            <a href="{{ route('account.data') }}" class="muted" style="font-size:.85rem">Data &amp; Privacy</a>
        </div>
        <form method="POST" action="{{ route('tenant.logout') }}">@csrf<button class="btn" style="background:#5c6b63">Sign out</button></form>
    </div>

    @if (session('flash'))
        <div class="flash">{{ session('flash') }}</div>
    @endif
    @if ($errors->any())
        <div class="err">{{ $errors->first() }}</div>
    @endif

    {{-- Renewal banner --}}
    @if ($banner === 'expired')
        <div class="err" style="font-size:.95rem"><strong>Your subscription has expired.</strong> You can still read your books, but posting is blocked. Record a payment below to renew.</div>
    @elseif ($banner === 'grace')
        <div style="background:#fff4e0;color:#97590a;border:1px solid #f3d9a8;border-radius:8px;padding:.7rem .9rem;margin-bottom:1rem;font-size:.92rem">
            <strong>Your subscription has expired — please renew.</strong> You're in a grace period until {{ $tenant->accessEndsAt()?->format('d-M-Y') }}; posting still works for now.
        </div>
    @elseif ($banner === 'expiring_soon')
        <div style="background:#fff4e0;color:#97590a;border:1px solid #f3d9a8;border-radius:8px;padding:.7rem .9rem;margin-bottom:1rem;font-size:.92rem">
            Your subscription expires in {{ $daysLeft }} day(s) ({{ $tenant->plan_ends_at?->format('d-M-Y') }}). Record your renewal to avoid interruption.
        </div>
    @endif

    {{-- Current plan --}}
    <div class="zb-card">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:.5rem">
            <div>
                <h1 style="margin:0">Subscription</h1>
                <p class="muted" style="margin:.2rem 0 0">{{ $tenant?->name }} · {{ $tenant?->id }}</p>
            </div>
            <span class="pill" style="background:{{ $statusPill[0] }};color:{{ $statusPill[1] }}">{{ $tenant?->status }}</span>
        </div>
        <div class="grid" style="margin-top:1rem;grid-template-columns:repeat(auto-fit,minmax(150px,1fr))">
            <div><label style="margin:0">Plan</label><div>{{ $plan?->name ?? '—' }}</div></div>
            <div><label style="margin:0">Paid until</label><div>{{ $tenant?->plan_ends_at?->format('d-M-Y') ?? '—' }}</div></div>
            <div><label style="margin:0">Days remaining</label><div>{{ $daysLeft !== null ? $daysLeft.' day(s)' : '—' }}</div></div>
            @if ($tenant?->trial_ends_at)
                <div><label style="margin:0">Trial ends</label><div>{{ $tenant->trial_ends_at->format('d-M-Y') }} @if($tenant->onTrial())<span class="muted">({{ $tenant->trialDaysLeft() }}d)</span>@endif</div></div>
            @endif
        </div>
    </div>

    {{-- Record a payment --}}
    <div class="zb-card" style="margin-top:1.2rem">
        <h1 style="font-size:1.1rem">Record a payment</h1>
        <p class="sub">Transferred by bank / UPI / cheque? Record it here with a screenshot. We'll confirm once we see the money, and your subscription extends automatically.</p>

        @if ($plans->isEmpty())
            <p class="muted">No purchasable plans are configured for {{ $currency }}. Please contact support to renew.</p>
        @else
            <form method="POST" action="{{ route('subscription.claim') }}" enctype="multipart/form-data" id="claim-form">
                @csrf
                <div class="grid" style="grid-template-columns:1fr 1fr;gap:.9rem">
                    <div>
                        <label for="plan_id">Plan</label>
                        <select id="plan_id" name="plan_id" required onchange="zbFillAmount()">
                            @foreach ($plans as $p)
                                <option value="{{ $p->id }}" data-price="{{ $p->priceFor($currency) }}">
                                    {{ $p->name }} — {{ $symbol }}{{ number_format((float) $p->priceFor($currency), 2) }} / {{ $p->periodLabel() }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="amount">Amount ({{ $currency }})</label>
                        <input id="amount" type="number" step="0.01" min="0" name="amount"
                               value="{{ old('amount', $plans->first()?->priceFor($currency)) }}" required>
                    </div>
                    <div>
                        <label for="payment_mode">Payment mode</label>
                        <select id="payment_mode" name="payment_mode" required>
                            @foreach ($modes as $m)
                                <option value="{{ $m }}" {{ old('payment_mode') === $m ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $m)) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="reference_number">Reference number</label>
                        <input id="reference_number" type="text" name="reference_number" value="{{ old('reference_number') }}" placeholder="UPI ref / cheque no / txn id">
                    </div>
                    <div>
                        <label for="received_at">Date received</label>
                        <input id="received_at" type="date" name="received_at" value="{{ old('received_at', now()->toDateString()) }}" max="{{ now()->toDateString() }}" required>
                    </div>
                    <div>
                        <label for="proof">Proof snapshot (jpg / png / pdf, ≤ 5 MB)</label>
                        <input id="proof" type="file" name="proof" accept=".jpg,.jpeg,.png,.pdf" required style="padding:.4rem">
                    </div>
                </div>
                <label for="notes" style="margin-top:.6rem">Notes (optional)</label>
                <textarea id="notes" name="notes" rows="2" style="width:100%;padding:.6rem .7rem;border:1px solid var(--line);border-radius:8px;font-family:inherit">{{ old('notes') }}</textarea>
                <button type="submit" class="btn" style="margin-top:1rem">Submit for confirmation</button>
            </form>
            <script>
                function zbFillAmount() {
                    var sel = document.getElementById('plan_id');
                    var price = sel.options[sel.selectedIndex].getAttribute('data-price');
                    if (price) document.getElementById('amount').value = price;
                }
            </script>
        @endif
    </div>

    {{-- History --}}
    <div class="zb-card" style="margin-top:1.2rem">
        <h1 style="font-size:1.1rem">Payment history</h1>
        <div style="overflow-x:auto">
            <table>
                <thead><tr><th>Received</th><th>Plan</th><th>Amount</th><th>Mode</th><th>Reference</th><th>Status</th><th>Proof</th><th>Invoice</th></tr></thead>
                <tbody>
                    @forelse ($payments as $pmt)
                        @php $ps = match ($pmt->status) {
                            'confirmed' => ['#e8f6ef', '#084f39'],
                            'pending' => ['#fff4e0', '#97590a'],
                            'rejected', 'reversed' => ['#fdeceb', '#b23b32'],
                            default => ['#eceff0', '#5c6b63'],
                        }; @endphp
                        <tr>
                            <td class="muted">{{ $pmt->received_at?->format('d-M-Y') }}</td>
                            <td>{{ $pmt->plan?->name ?? '—' }}</td>
                            <td>{{ $pmt->formattedAmount() }}</td>
                            <td class="muted">{{ str_replace('_', ' ', $pmt->payment_mode) }}</td>
                            <td class="muted">{{ $pmt->reference_number ?: '—' }}</td>
                            <td><span class="pill" style="background:{{ $ps[0] }};color:{{ $ps[1] }}">{{ $pmt->status }}</span>
                                @if ($pmt->status === 'rejected' && $pmt->rejection_reason)<div class="muted" style="font-size:.72rem">{{ $pmt->rejection_reason }}</div>@endif
                            </td>
                            <td>@if ($pmt->proof_file_path)<a href="{{ route('subscription.proof', $pmt->id) }}" target="_blank">view</a>@else<span class="muted">—</span>@endif</td>
                            <td>@if ($pmt->status === 'confirmed' && $pmt->invoice_file_path)<a href="{{ route('subscription.invoice', $pmt->id) }}" target="_blank">PDF</a>@else<span class="muted">—</span>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="muted">No payments recorded yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
