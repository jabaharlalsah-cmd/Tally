@extends('layouts.app')
@section('title', 'Budget vs Actual — ZeroBook')
@section('region', $region)
@section('content')
    <livewire:budgets.budget-variance-report />
@endsection
