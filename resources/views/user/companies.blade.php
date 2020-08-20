@extends('layouts.app')
@php $title = 'Companies' ; @endphp
@section('pageTitle', $title)
@section('content')
<section class="breadcrumbs-modern box-transform-wrap bg-image context-dark"
         style="background-image: url('/images/company-1.jpg');">
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
    <div class="box-transform" style="background-image: url('/images/company-1.jpg');"></div>
</section>
<section class="section section-xs section-first bg-default text-md-left">

    <div class="container" id="container">

        <companies-table :results-per-page="{{Config::get('constants.options.results_per_page')}}"></companies-table>

    </div>
</section>
@endsection
