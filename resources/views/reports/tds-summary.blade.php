@extends('layouts.app')
@section('title', 'TDS Deduction Summary — ZeroBook')
@section('region', $region)
@section('content')
    <livewire:reports.tds-summary />
@endsection
