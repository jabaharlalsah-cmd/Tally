<x-layouts.plain title="Set a new password — ZeroBook platform">
    <div class="zb-card zb-auth">
        <h1>Set a new password</h1>
        <p class="sub">Choose a new password for your admin account.</p>

        @if ($errors->any())
            <div class="err">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('platform.password.update') }}">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">

            <label for="email">Email</label>
            <input id="email" type="email" name="email" value="{{ old('email', $email) }}" required>

            <label for="password">New password</label>
            <input id="password" type="password" name="password" autocomplete="new-password" autofocus required>

            <label for="password_confirmation">Confirm new password</label>
            <input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" required>

            <button type="submit" class="btn btn-block">Update password</button>
        </form>

        <p style="margin-top:1rem;font-size:.85rem;text-align:center">
            <a href="{{ route('platform.login') }}">← Back to sign in</a>
        </p>
    </div>
</x-layouts.plain>
