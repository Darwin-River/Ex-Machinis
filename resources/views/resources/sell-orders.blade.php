@extends('layouts.app')
@php $title = 'Sell Orders' ; @endphp
@section('pageTitle', $title)
@section('content')

    <section class="section section-xs section-first bg-default text-md-left">

        <div class="container" id="container">
            <h1>{{$title}}</h1>
            <buy-orders-table :results-per-page="{{Config::get('constants.options.results_per_page')}}" data-url="sells/search"></buy-orders-table>

        </div>
    </section>
@endsection
