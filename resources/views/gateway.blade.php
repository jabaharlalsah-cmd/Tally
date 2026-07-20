@extends('layouts.app')

@section('title', 'Gateway of ZeroBook')
@section('region', 'Gateway')

@section('content')
    @include('partials.menu-screen', [
        'heading'  => 'Gateway of ZeroBook',
        'sub'      => 'Use <span class="zb-kbd">&uarr;</span> <span class="zb-kbd">&darr;</span> and
                       <span class="zb-kbd">Enter</span>, or press an item&rsquo;s highlighted letter.',
        'sections' => $sections,
        'name'     => 'gateway',
        'focusEl'  => '#zb-gateway',
    ])

    <div class="zb-gateway-account">
        <a href="{{ route('tenant.password.change') }}" class="text-muted">Change password</a>
        <span class="text-muted" aria-hidden="true">&middot;</span>
        <form method="POST" action="{{ route('tenant.logout') }}">
            @csrf
            <button type="submit" class="zb-linkbutton text-muted">Log out</button>
        </form>
    </div>
@endsection
