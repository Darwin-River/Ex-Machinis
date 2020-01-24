@extends('layouts.app')
@php $title = 'Resources' ; @endphp
@section('pageTitle', $title)
@section('content')

    <section class="section section-xs section-first bg-default text-md-left">

        <div class="container" id="container">
            <h1>{{$title}}</h1>
            <resources :resources="{{json_encode($resources)}}"></resources>

        </div>
    </section>
@endsection

