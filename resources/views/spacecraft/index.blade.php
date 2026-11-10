@extends('layouts.app')
@php $title = 'Spacecraft Search' ; @endphp
@section('pageTitle', $title)
@section('content')
    <!-- Breadcrumbs-->
    <!--    <x-page-header>
        <x-slot:title></x-slot:title>
        <li class="active">{{$title}}</li>
    </x-page-header> -->

    <section class="section section-xs section-first text-md-left">

        <div class="container" id="container">
            <h1>{{$title}}</h1>
            <spacecraft-table
                    :results-per-page="{{Config::get('constants.options.results_per_page')}}"></spacecraft-table>

        </div>
    </section>
@endsection
