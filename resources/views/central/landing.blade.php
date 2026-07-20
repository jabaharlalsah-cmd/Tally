<x-layouts.plain title="ZeroBook — keyboard-first accounting" :topRight="'<a href=\''.route('signup').'\'>Start free trial →</a>'">
    <div class="zb-card">
        <h1>Keyboard-first accounting, per company.</h1>
        <p class="sub">ZeroBook gives every business its own fully-isolated set of books — Tally-fast keyboard entry, GST &amp; VAT, inventory with weighted-average costing, and one-click migration from your existing data.</p>
        <p class="muted" style="font-size:.85rem">Each customer runs on their own database. Sign in at <strong>your-company.{{ \App\Support\TenantUrl::baseDomain() }}</strong>.</p>
        <div style="margin-top:1.2rem">
            <a href="{{ route('signup') }}" class="btn" style="text-decoration:none">Start your free {{ $trialDays }}-day trial</a>
        </div>
    </div>

    <h1 style="font-size:1.2rem;margin:1.8rem 0 .8rem">Plans</h1>
    <div class="grid">
        @foreach ($plans as $plan)
            <div class="plan">
                <h3>{{ $plan->name }}</h3>
                <div class="price">₹{{ number_format($plan->price_inr) }}<span class="muted" style="font-size:.7rem;font-weight:400">/mo</span></div>
                <ul>
                    @foreach ($plan->unlockedFeatures() as $f)
                        <li>{{ \App\Support\PlanGate::label($f) }}</li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </div>
    <p class="muted" style="font-size:.8rem;margin-top:1rem">Every new account starts on a {{ $trialDays }}-day free trial with every feature unlocked — pick a plan later.</p>
</x-layouts.plain>
