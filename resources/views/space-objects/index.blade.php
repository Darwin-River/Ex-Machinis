@extends('layouts.app')
@php $title = 'Astronomical Objects' ; @endphp
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

    <section class="section section-xs section-first bg-default text-md-left">

        <div class="container" id="container">
            <h1>{{$title}}</h1>
            <space-objects-table></space-objects-table>
            
        </div>
    </section>
@endsection
