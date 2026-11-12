@extends('layouts.app')
@php $title = $resource->name ; @endphp
@section('pageTitle', $title)
@section('content')
    <x-page-header>
        <x-slot:title>{{$title}}</x-slot:title>
        <li><a href="/resources">Resources</a></li>
        <li class="active">{{$title}}</li>
    </x-page-header>

    <section class="section section-xs section-first text-md-left">

        <div class="container" id="container">
            <div class="row row-30 description-fields">
                <div class="col-12 col-lg-6 col mb-0 mb-lg-4">
                    <b>Id: </b>{{$resource->id}}<br/>
                </div>
                <div class="col-12 col-lg-6">
                    <b>Unit Mass: </b>{{$resource->mass}} kg.<br/>
                </div>
            </div>
            <div class="row">
                <div class="col">
                    <p>
                        {{$resource->description}}
                    </p>
                </div>
            </div>
            {{-- Its recent price and volume (#597) --}}
            <div class="row row-30">
                <div class="col" id="recent-sales">
                    <h2 class="">Recent sales</h2>
                    @if($traded)
                        @include('partials.price-volume-chart', ['chartResource' => (int) $resource->id, 'chartDays' => 30,
                            'chartTitle' => 'Price a unit and units traded'])
                        <p style="white-space: normal">Every resource's history is on <a href="{{ url('market/history') }}">Market history</a>.</p>
                    @else
                        <p>No trades yet.</p>
                    @endif
                </div>
            </div>
            <div class="row row-30">
                <div class="col">
                    <h2 class="" style="visibility: visible; animation-name: fadeInLeft;">
                        Buy Orders</h2>
                    <resource-orders-table :resource-id="{{$resource->id}}"
                                           data-url="/buys/search?resource={{$resource->id}}&type={{App\EventType::BUY_RESOURCE}}"
                                           :operation="{{\App\EventType::BUY_RESOURCE}}"></resource-orders-table>
                </div>
            </div>
            <div class="row row-30">
                <div class="col">
                    <h2 class="" style="visibility: visible; animation-name: fadeInLeft;">
                        Sell Orders</h2>
                    <resource-orders-table :resource-id="{{$resource->id}}"
                                           data-url="/sells/search?resource={{$resource->id}}&type={{App\EventType::BUY_RESOURCE}}"
                                           :operation="{{\App\EventType::BUY_RESOURCE}}"></resource-orders-table>
                </div>
            </div>
        </div>
    </section>
@endsection