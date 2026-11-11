@extends('layouts.app')
@php $title = 'Buy Orders' ; @endphp
@section('pageTitle', $title)
@section('content')

    <x-page-header :title="$title" />
<section class="section section-xs section-first text-md-left">

    <div class="container" id="container">
        <buy-orders-table :results-per-page="{{Config::get('constants.options.results_per_page')}}" data-url="buys/search"></buy-orders-table>

    </div>
</section>
@endsection
