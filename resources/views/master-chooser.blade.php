@extends('layouts.app')

@section('title', ($mode === 'alter' ? 'Alter' : 'Create') . ' Master — ZeroBook')
@section('region', 'Masters · ' . ($mode === 'alter' ? 'Alter' : 'Create'))

@section('content')
    @include('partials.menu-screen', [
        'heading'  => ($mode === 'alter' ? 'Alter' : 'Create') . ' Master',
        'sub'      => 'Use <span class="zb-kbd">&uarr;</span> <span class="zb-kbd">&darr;</span> and
                       <span class="zb-kbd">Enter</span>, or press the highlighted letter.
                       <span class="zb-kbd">Esc</span> returns to the Gateway.',
        'sections' => $sections,
        'name'     => 'masters.chooser.' . $mode,
        'focusEl'  => '#zb-master-chooser',
        'hubUrl'   => route('gateway'),
    ])
@endsection
