<x-layouts.plain title="Change password — ZeroBook platform">
    <div class="zb-card zb-auth">
        <h1>Change password</h1>
        <p class="sub">Update the password for {{ auth('platform')->user()?->email }}.</p>

        @if (session('flash'))
            <div class="flash">{{ session('flash') }}</div>
        @endif

        @if ($errors->any())
            <div class="err">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('platform.password.change.update') }}">
            @csrf
            <label for="password">New password</label>
            <input id="password" type="password" name="password" autocomplete="new-password" autofocus required>

            <label for="password_confirmation">Confirm new password</label>
            <input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" required>

            <button type="submit" class="btn btn-block">Change password</button>
        </form>

        <p style="margin-top:1rem;font-size:.85rem;text-align:center">
            <a href="{{ route('platform.dashboard') }}">← Back to console</a>
        </p>
    </div>
</x-layouts.plain>
