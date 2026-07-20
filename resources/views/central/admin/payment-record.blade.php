<x-layouts.plain title="Record a payment — ZeroBook platform">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
        <a href="{{ route('platform.payments') }}" class="muted" style="font-size:.85rem">← Pending payments</a>
        <form method="POST" action="{{ route('platform.logout') }}">@csrf<button class="btn" style="background:#5c6b63">Sign out</button></form>
    </div>

    @if ($errors->any())<div class="err">{{ $errors->first() }}</div>@endif

    <div class="zb-card zb-auth" style="max-width:560px">
        <h1>Record a payment</h1>
        <p class="sub">For money you saw in the bank statement without a customer claim. This is recorded as <strong>confirmed</strong> immediately and extends the tenant's subscription.</p>

        <form method="POST" action="{{ route('platform.payments.record.store') }}" enctype="multipart/form-data">
            @csrf
            <label for="tenant_id">Tenant</label>
            <select id="tenant_id" name="tenant_id" required>
                <option value="">— select tenant —</option>
                @foreach ($tenants as $t)
                    <option value="{{ $t->id }}" {{ old('tenant_id', $selectedTenant) === $t->id ? 'selected' : '' }}>{{ $t->name }} ({{ $t->id }})</option>
                @endforeach
            </select>

            <div class="grid" style="grid-template-columns:1fr 1fr;gap:.8rem;margin-top:.2rem">
                <div>
                    <label for="plan_id">Plan</label>
                    <select id="plan_id" name="plan_id" required>
                        @foreach ($plans as $p)
                            <option value="{{ $p->id }}" {{ old('plan_id') == $p->id ? 'selected' : '' }}>{{ $p->name }} ({{ $p->billing_period_months }}mo)</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="currency">Currency</label>
                    <select id="currency" name="currency" required>
                        <option value="INR" {{ old('currency') === 'INR' ? 'selected' : '' }}>INR ₹</option>
                        <option value="NPR" {{ old('currency') === 'NPR' ? 'selected' : '' }}>NPR रू</option>
                    </select>
                </div>
                <div>
                    <label for="amount">Amount</label>
                    <input id="amount" type="number" step="0.01" min="0" name="amount" value="{{ old('amount') }}" required>
                </div>
                <div>
                    <label for="payment_mode">Payment mode</label>
                    <select id="payment_mode" name="payment_mode" required>
                        @foreach ($modes as $m)<option value="{{ $m }}">{{ ucfirst(str_replace('_', ' ', $m)) }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label for="reference_number">Reference</label>
                    <input id="reference_number" type="text" name="reference_number" value="{{ old('reference_number') }}">
                </div>
                <div>
                    <label for="received_at">Date received</label>
                    <input id="received_at" type="date" name="received_at" value="{{ old('received_at', now()->toDateString()) }}" max="{{ now()->toDateString() }}" required>
                </div>
            </div>

            <label for="proof" style="margin-top:.6rem">Proof snapshot (optional — jpg / png / pdf ≤ 5 MB)</label>
            <input id="proof" type="file" name="proof" accept=".jpg,.jpeg,.png,.pdf" style="padding:.4rem">

            <label for="notes">Notes (optional)</label>
            <textarea id="notes" name="notes" rows="2" style="width:100%;padding:.6rem .7rem;border:1px solid var(--line);border-radius:8px;font-family:inherit">{{ old('notes') }}</textarea>

            <button type="submit" class="btn btn-block">Record & confirm</button>
        </form>
    </div>
</x-layouts.plain>
