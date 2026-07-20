@extends('layouts.app')

@section('title', 'Orders Outstanding — ZeroBook')
@section('region', 'Orders Outstanding')

@section('content')
    <livewire:orders-outstanding :scope="$scope" />
@endsection
