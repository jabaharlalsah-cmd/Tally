@extends('layouts.app')
@section('title', 'Payables — ZeroBook')
@section('region', $region)
@section('content')
    <livewire:reports.outstandings mode="payable" />
@endsection
