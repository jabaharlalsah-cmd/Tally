<x-layouts.plain :title="$tenantName.' — change password'">
    <div class="zb-card zb-auth">
        <h1>Change password</h1>
        <p class="sub">Update the password for {{ auth('tenant')->user()?->email }}.</p>

        @if (session('flash'))
            <div class="flash">{{ session('flash') }}</div>
        @endif

        @if ($errors->any())
            <div class="err">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('tenant.password.change.update') }}">
            @csrf
            <label for="current_password">Current password</label>
            <input id="current_password" type="password" name="current_password" autocomplete="current-password" autofocus required>

            <label for="password">New password</label>
            <input id="password" type="password" name="password" autocomplete="new-password" required>

            <label for="password_confirmation">Confirm new password</label>
            <input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" required>

            <button type="submit" class="btn btn-block">Change password</button>
        </form>

        <p style="margin-top:1rem;font-size:.85rem;text-align:center">
            <a href="{{ route('gateway') }}">← Back to Gateway</a>
        </p>
    </div>
</x-layouts.plain>
