@extends('layouts.app')
@php $title = 'Locations' ; @endphp
@section('pageTitle', $title)
@section('content')
    <!-- Breadcrumbs-->
   <!-- <section class="breadcrumbs-classic mt-4">

        <div class="breadcrumbs-classic-footer">
            <div class="container">
                <ul class="breadcrumbs-path breadcrumbs-classic-path">
                    <li><a href="/">Home</a></li>
                    <li class="active">{{$title}}</li>
                </ul>
            </div>
        </div>
    </section> -->
    <section class="breadcrumbs-modern box-transform-wrap bg-image context-dark"
             style="background-image: url('/images/space-object-2.jpg');">
        <div class="container">
            <div class="breadcrumbs-modern-body">
                <h5 class="breadcrumbs-modern-subtitle"></h5>
                <h1 class="breadcrumbs-modern-title">{{$title}}</h1>
            </div>
            <ul class="breadcrumbs-path breadcrumbs-modern-path">
                <li><a href="/">Home</a></li>
                <li class="active">{{$title}}</li>
            </ul>
        </div>
        <div class="box-transform" style="background-image: url('/images/space-object-2.jpg');"></div>
        <div class="comets comets-left"></div>
        <div class="comets comets-center"></div>
        <div class="comets comets-top"></div>
    </section>
    <section class="section section-xs section-first bg-default text-md-left">

        <div class="container" id="container">

            <space-objects-table :results-per-page="{{Config::get('constants.options.results_per_page')}}"></space-objects-table>

        </div>
    </section>
@endsection
