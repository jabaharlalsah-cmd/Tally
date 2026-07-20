@extends('layouts.app')
@section('title', 'Scenario Manager — ZeroBook')
@section('region', $region)
@section('content')
    <livewire:scenarios.scenario-manager :scenario-id="$scenarioId" />
@endsection
