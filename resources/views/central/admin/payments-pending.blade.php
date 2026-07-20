<x-layouts.plain title="Pending payments — ZeroBook platform">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
        <div style="display:flex;gap:1rem;align-items:center">
            <a href="{{ route('platform.dashboard') }}" class="muted" style="font-size:.85rem">Tenants</a>
            <strong style="font-size:.85rem">Pending payments</strong>
            <a href="{{ route('platform.payments.record') }}" class="muted" style="font-size:.85rem">Record a payment</a>
            <a href="{{ route('platform.plans') }}" class="muted" style="font-size:.85rem">Plans</a>
        </div>
        <form method="POST" action="{{ route('platform.logout') }}">@csrf<button class="btn" style="background:#5c6b63">Sign out</button></form>
    </div>

    @if (session('flash'))<div class="flash">{{ session('flash') }}</div>@endif
    @if ($errors->any())<div class="err">{{ $errors->first() }}</div>@endif

    <div class="zb-card">
        <h1 style="font-size:1.15rem">Pending payment claims ({{ $payments->count() }})</h1>
        <p class="sub">Confirm once you've verified the money in your bank statement. Rejecting notifies the customer with your reason.</p>

        <div style="overflow-x:auto">
            <table>
                <thead><tr>
                    <th>Tenant</th><th>Amount</th><th>Mode / Ref</th><th>Received</th><th>Plan</th><th>By</th><th>Proof</th><th>Actions</th>
                </tr></thead>
                <tbody>
                    @forelse ($payments as $p)
                        <tr>
                            <td><a href="{{ route('platform.tenant', $p->tenant_id) }}"><strong>{{ $p->tenant?->name }}</strong></a><div class="muted" style="font-size:.72rem">{{ $p->tenant_id }}</div></td>
                            <td><strong>{{ $p->formattedAmount() }}</strong></td>
                            <td class="muted">{{ str_replace('_', ' ', $p->payment_mode) }}<br>{{ $p->reference_number ?: '—' }}</td>
                            <td class="muted">{{ $p->received_at?->format('d-M-Y') }}</td>
                            <td class="muted">{{ $p->plan?->name ?? '—' }}</td>
                            <td class="muted" style="font-size:.72rem">{{ $p->notifiedByUser?->email ?? 'admin' }}</td>
                            <td>@if ($p->proof_file_path)<a href="{{ route('platform.payments.proof', $p->id) }}" target="_blank">view</a>@else —@endif</td>
                            <td>
                                <div style="display:flex;flex-direction:column;gap:.35rem;min-width:210px">
                                    <form method="POST" action="{{ route('platform.payments.confirm', $p->id) }}">@csrf
                                        <button class="btn" style="width:100%;padding:.35rem">Confirm</button>
                                    </form>
                                    <form method="POST" action="{{ route('platform.payments.reject', $p->id) }}" style="display:flex;gap:.3rem">@csrf
                                        <input type="text" name="reason" placeholder="Reject reason" required style="flex:1;padding:.35rem">
                                        <button class="btn" style="background:#b23b32;padding:.35rem .6rem">Reject</button>
                                    </form>
                                    @if ($p->notes)<div class="muted" style="font-size:.72rem">“{{ $p->notes }}”</div>@endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="muted">No pending payment claims. 🎉</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-layouts.plain>
