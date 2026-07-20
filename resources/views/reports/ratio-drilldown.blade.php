@extends('layouts.app')
@section('title', 'Ratio Inputs — ZeroBook')
@section('region', $region)
@section('content')
    <livewire:ratios.ratio-drilldown :ratio="$ratio" :as-of="$asOf" />
@endsection
