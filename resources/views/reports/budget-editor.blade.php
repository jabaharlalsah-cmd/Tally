@extends('layouts.app')
@section('title', 'Budget Editor — ZeroBook')
@section('region', $region)
@section('content')
    <livewire:budgets.budget-editor :budget="$budgetId" :revise="$revise" />
@endsection
