@extends('layouts.app')
@php $title = 'Locations' ; @endphp
@section('pageTitle', $title)
@section('content')
    <!-- Breadcrumbs-->
   <!--    <x-page-header>
        <x-slot:title></x-slot:title>
        <li class="active">{{$title}}</li>
    </x-page-header> -->
    <x-page-header>
        <x-slot:title>{{$title}}</x-slot:title>
        <li class="active">{{$title}}</li>
    </x-page-header>
    <section class="section section-xs section-first text-md-left">

        <div class="container" id="container">

            <space-objects-table :results-per-page="{{Config::get('constants.options.results_per_page')}}"></space-objects-table>

        </div>
    </section>
@endsection
