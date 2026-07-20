<x-layouts.plain title="Reset password — ZeroBook platform">
    <div class="zb-card zb-auth">
        <h1>Forgot password</h1>
        <p class="sub">Enter your admin email and we’ll send you a reset link.</p>

        @if (session('flash'))
            <div class="flash">{{ session('flash') }}</div>
        @endif

        @if ($errors->any())
            <div class="err">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('platform.password.email') }}">
            @csrf
            <label for="email">Email</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" autofocus required>
            <button type="submit" class="btn btn-block">Send reset link</button>
        </form>

        <p style="margin-top:1rem;font-size:.85rem;text-align:center">
            <a href="{{ route('platform.login') }}">← Back to sign in</a>
        </p>
    </div>
</x-layouts.plain>
