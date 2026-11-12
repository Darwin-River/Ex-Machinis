@extends('layouts.app')
@section('pageTitle', 'Market history')
@section('content')
    {{-- Market history (#380): daily prices and volumes, from /api/v1/history --}}
    <x-page-header :title="'Market history'" />
    <section class="section section-xs section-first text-md-left">
        <div class="container" id="container">
            <div class="mh-intro">
                What every resource traded for, game day by game day, at each place. Each day's price is the average a
                unit fetched over all of that day's trades; the shaded band runs from the cheapest unit to the dearest.
                The same numbers come from the API's <code>/api/v1/history</code>. Open orders are on the
                <a href="{{ url('market') }}">Market</a> page.
            </div>

            @if($resources->isEmpty())
                <p>No trades yet.</p>
            @else
                @include('partials.price-volume-chart', ['chartResource' => null, 'chartDays' => 60, 'chartTitle' => 'Price a unit and units traded'])
            @endif
        </div>
    </section>

@endsection
