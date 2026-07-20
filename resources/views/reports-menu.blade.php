@extends('layouts.app')

@section('title', 'Display More Reports — ZeroBook')
@section('region', 'Reports · Display More Reports')

@section('content')
    @include('partials.menu-screen', [
        'heading'  => 'Display More Reports',
        'sub'      => 'Use <span class="zb-kbd">&uarr;</span> <span class="zb-kbd">&darr;</span> and
                       <span class="zb-kbd">Enter</span>, or press the highlighted letter.
                       <span class="zb-kbd">Esc</span> returns to the Gateway.',
        'sections' => $sections,
        'name'     => 'reports.hub',
        'focusEl'  => '#zb-reports-hub',
        'hubUrl'   => route('gateway'),
    ])
@endsection
