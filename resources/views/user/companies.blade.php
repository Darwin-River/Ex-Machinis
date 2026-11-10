@extends('layouts.app')
@php $title = 'Companies' ; @endphp
@section('pageTitle', $title)
@section('content')
    <x-page-header>
        <x-slot:title>{{$title}}</x-slot:title>
        <li class="active">{{$title}}</li>
    </x-page-header>
<section class="section section-xs section-first text-md-left">

    <div class="container" id="container">

        <companies-table :results-per-page="{{Config::get('constants.options.results_per_page')}}"></companies-table>

    </div>
</section>
@endsection
