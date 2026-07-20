@extends('layouts.app')
@section('title', 'Budgets — ZeroBook')
@section('region', $region)
@section('content')
    <livewire:budgets.budget-list />
@endsection
