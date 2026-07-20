<x-layouts.plain title="Check your email — ZeroBook">
    <div class="zb-card zb-auth" style="max-width:480px;text-align:center">
        <div style="font-size:2.4rem;line-height:1">📮</div>
        <h1 style="margin-top:.6rem">Check your email</h1>
        <p class="sub">
            We've sent a confirmation link to
            <strong style="color:var(--ink)">{{ $email }}</strong>.
            Click it to activate <strong style="color:var(--ink)">{{ $subdomain }}.{{ \App\Support\TenantUrl::baseDomain() }}</strong>
            and sign in.
        </p>

        <div class="flash" style="text-align:left">
            Your workspace is provisioned and waiting. You just need to confirm your email before your first sign-in —
            this keeps your books secure.
        </div>

        <p class="muted" style="font-size:.8rem;margin-top:1rem">
            Didn't get it? Check your spam folder. The link expires in {{ (int) config('auth.verification.expire', 60) }} minutes.
        </p>
    </div>
</x-layouts.plain>
