<x-layouts.plain title="Plans — ZeroBook platform">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
        <div style="display:flex;gap:1rem;align-items:center">
            <a href="{{ route('platform.dashboard') }}" class="muted" style="font-size:.85rem">Tenants</a>
            <a href="{{ route('platform.payments') }}" class="muted" style="font-size:.85rem">Pending payments</a>
            <strong style="font-size:.85rem">Plans</strong>
        </div>
        <form method="POST" action="{{ route('platform.logout') }}">@csrf<button class="btn" style="background:#5c6b63">Sign out</button></form>
    </div>

    @if (session('flash'))<div class="flash">{{ session('flash') }}</div>@endif
    @if ($errors->any())<div class="err">{{ $errors->first() }}</div>@endif

    {{-- One empty form per plan; the row inputs reference it via the HTML5 `form` attribute
         (a <form> cannot be a valid child of <tr>). --}}
    @foreach ($plans as $plan)
        <form id="pf{{ $plan->id }}" method="POST" action="{{ route('platform.plans.update', $plan->id) }}">@csrf</form>
    @endforeach

    <div class="zb-card">
        <h1 style="font-size:1.15rem">Plans</h1>
        <p class="sub">Prices are editable and never retroactive — a change only affects payments recorded afterwards. Public plans appear on the customer's renew screen.</p>

        <div style="overflow-x:auto">
            <table>
                <thead><tr><th>Tier</th><th>Name</th><th>Price INR</th><th>Price NPR</th><th>Months</th><th>Public</th><th></th></tr></thead>
                <tbody>
                    @foreach ($plans as $plan)
                        <tr>
                            <td class="muted"><code>{{ $plan->tier }}</code></td>
                            <td><input form="pf{{ $plan->id }}" type="text" name="name" value="{{ $plan->name }}" required style="min-width:150px;padding:.3rem"></td>
                            <td><input form="pf{{ $plan->id }}" type="number" step="0.01" min="0" name="price_inr" value="{{ $plan->price_inr }}" style="width:100px;padding:.3rem"></td>
                            <td><input form="pf{{ $plan->id }}" type="number" step="0.01" min="0" name="price_npr" value="{{ $plan->price_npr }}" style="width:100px;padding:.3rem"></td>
                            <td><input form="pf{{ $plan->id }}" type="number" min="1" max="120" name="billing_period_months" value="{{ $plan->billing_period_months }}" required style="width:60px;padding:.3rem"></td>
                            <td style="text-align:center"><input form="pf{{ $plan->id }}" type="checkbox" name="is_public" value="1" {{ $plan->is_public ? 'checked' : '' }} style="width:auto"></td>
                            <td><button form="pf{{ $plan->id }}" class="btn" style="padding:.3rem .7rem">Save</button></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-layouts.plain>
