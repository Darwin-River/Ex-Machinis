@extends('layouts.app')
@php $title = 'Resources' ; @endphp
@section('pageTitle', $title)
@section('content')
    <x-page-header>
        <x-slot:title>{{$title}}</x-slot:title>
        <li class="active">{{$title}}</li>
    </x-page-header>
    <section class="section section-xs section-first text-md-left">

        <div class="container" id="container">

            <resources :resources="{{json_encode($resources)}}"></resources>

        </div>
    </section>
@endsection
