@extends('layouts.app')
@section('title', 'Receivables — ZeroBook')
@section('region', $region)
@section('content')
    <livewire:reports.outstandings mode="receivable" />
@endsection
