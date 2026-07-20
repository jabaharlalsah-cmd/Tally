@extends('layouts.app')
@section('title', 'Stock Item Movement — ZeroBook')
@section('region', $region)
@section('content')
    <livewire:reports.stock-item-movement :stock-item="$stockItem" :from="$from" :to="$to" />
@endsection
