@extends('layouts.app')
@php $title = 'Company Search' ; @endphp
@section('pageTitle', $title)
@section('content')

<section class="section section-xs section-first bg-default text-md-left">

    <div class="container" id="container">
        <h1>{{$title}}</h1>
        <companies-table :results-per-page="{{Config::get('constants.options.results_per_page')}}"></companies-table>

    </div>
</section>
@endsection
