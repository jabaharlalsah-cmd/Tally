@extends('layouts.app')
@section('title', 'Scenario Impact — ZeroBook')
@section('region', $region)
@section('content')
    <livewire:scenarios.scenario-impact :scenario-id="$scenarioId" :as-of="$asOf" />
@endsection
