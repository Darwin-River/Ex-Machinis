@extends('layouts.app')
@php $title = 'Resources' ; @endphp
@section('pageTitle', $title)
@section('content')
<section class="breadcrumbs-modern box-transform-wrap bg-image context-dark"
         style="background-image: url('/images/planet-surface-1.jpg');">
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
    <div class="box-transform" style="background-image: url('/images/planet-surface-1.jpg');"></div>

</section>
    <section class="section section-xs section-first bg-default text-md-left">

        <div class="container" id="container">

            <resources :resources="{{json_encode($resources)}}"></resources>

        </div>
    </section>
@endsection
