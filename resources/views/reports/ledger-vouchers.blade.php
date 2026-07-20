@extends('layouts.app')
@section('title', 'Ledger Vouchers — ZeroBook')
@section('region', $region)
@section('content')
    <livewire:reports.ledger-vouchers :ledger="$ledger" :from="$from" :to="$to" />
@endsection
