@extends('layouts.app')

@section('title', 'Voucher — ZeroBook')
@section('region', $region)

@section('content')
    @if ($voucher)
        <livewire:voucher-screen :voucher="$voucher" />
    @else
        <livewire:voucher-screen :type="$type" />
    @endif
@endsection
