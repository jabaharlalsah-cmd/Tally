<x-layouts.plain title="Platform sign-in — ZeroBook">
    <div class="zb-card zb-auth">
        <h1>Platform admin</h1>
        <p class="sub">Sign in to manage tenants.</p>

        @if (session('flash'))
            <div class="flash">{{ session('flash') }}</div>
        @endif

        @if ($errors->any())
            <div class="err">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('platform.login.attempt') }}">
            @csrf
            <label for="email">Email</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" autofocus required>
            <label for="password">Password</label>
            <input id="password" type="password" name="password" required>
            <button type="submit" class="btn btn-block">Sign in</button>
        </form>

        <p style="margin-top:1rem;font-size:.85rem;text-align:center">
            <a href="{{ route('platform.password.request') }}">Forgot password?</a>
        </p>
    </div>
</x-layouts.plain>
