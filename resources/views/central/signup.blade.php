<x-layouts.plain title="Start your free trial — ZeroBook" :topRight="'<a href=\''.route('signup').'\'>Sign up</a>'">
    <div class="zb-card zb-auth" style="max-width:520px">
        <h1>Start your free trial</h1>
        <p class="sub">{{ $trialDays }} days, every feature unlocked. No card required. Your books live in their own private database.</p>

        @if ($errors->any())
            <div class="err">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('signup.store') }}" id="zb-signup">
            @csrf

            <label for="company_name">Company name</label>
            <input id="company_name" type="text" name="company_name" value="{{ old('company_name') }}" autofocus required>

            <label for="subdomain">Choose your address</label>
            <div style="display:flex;align-items:center;gap:.4rem">
                <input id="subdomain" type="text" name="subdomain" value="{{ old('subdomain') }}"
                       autocomplete="off" autocapitalize="none" spellcheck="false"
                       placeholder="your-company" required style="flex:1">
                <span class="muted" style="font-size:.9rem;white-space:nowrap">.{{ \App\Support\TenantUrl::baseDomain() }}</span>
            </div>
            <div id="sub-hint" class="muted" style="font-size:.8rem;margin-top:.3rem;min-height:1.1em"></div>

            <label>Country / tax regime</label>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.6rem;margin-top:.2rem">
                @foreach ($countries as $key => $c)
                    <label style="border:1px solid var(--line);border-radius:8px;padding:.6rem .7rem;margin:0;cursor:pointer;display:block;font-weight:400">
                        <span style="display:flex;align-items:center;gap:.4rem">
                            <input type="radio" name="country" value="{{ $key }}" style="width:auto"
                                   {{ old('country', 'india') === $key ? 'checked' : '' }} required>
                            <strong style="color:var(--ink)">{{ $c['label'] }}</strong>
                        </span>
                        <span class="muted" style="font-size:.74rem;display:block;margin-top:.25rem">{{ $c['blurb'] }}</span>
                    </label>
                @endforeach
            </div>

            <label for="admin_name" style="margin-top:1rem">Your name</label>
            <input id="admin_name" type="text" name="admin_name" value="{{ old('admin_name') }}" required>

            <label for="admin_email">Your email</label>
            <input id="admin_email" type="email" name="admin_email" value="{{ old('admin_email') }}" required>

            <label for="admin_password">Password</label>
            <input id="admin_password" type="password" name="admin_password" minlength="8" required>

            <label for="admin_password_confirmation">Confirm password</label>
            <input id="admin_password_confirmation" type="password" name="admin_password_confirmation" minlength="8" required>

            <button type="submit" class="btn btn-block">Create my account</button>
        </form>

        <p class="muted" style="font-size:.78rem;margin-top:1rem">
            We'll email you a link to confirm your address and sign in. Already have an account?
            Sign in at <strong>your-company.{{ \App\Support\TenantUrl::baseDomain() }}</strong>.
        </p>
    </div>

    <script>
        (function () {
            const input = document.getElementById('subdomain');
            const hint = document.getElementById('sub-hint');
            const url = @json(route('signup.subdomain-available'));
            let timer = null, seq = 0;

            function render(state, text) {
                const colors = { ok: '#0B6E4F', bad: '#b23b32', muted: '#5c6b63' };
                hint.style.color = colors[state] || colors.muted;
                hint.textContent = text;
            }

            input.addEventListener('input', function () {
                const slug = input.value.trim().toLowerCase();
                clearTimeout(timer);
                if (slug.length < 3) { render('muted', slug ? 'Keep typing…' : ''); return; }
                render('muted', 'Checking…');
                const mine = ++seq;
                timer = setTimeout(function () {
                    fetch(url + '?slug=' + encodeURIComponent(slug), { headers: { 'Accept': 'application/json' } })
                        .then(r => r.json())
                        .then(d => {
                            if (mine !== seq) return; // a newer keystroke won
                            render(d.available ? 'ok' : 'bad',
                                d.available ? '✓ ' + d.slug + '.{{ \App\Support\TenantUrl::baseDomain() }} is available'
                                            : '✕ ' + d.reason);
                        })
                        .catch(() => { if (mine === seq) render('muted', ''); });
                }, 300);
            });
        })();
    </script>
</x-layouts.plain>
