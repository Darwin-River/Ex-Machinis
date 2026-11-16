@extends('layouts.app')
@php
    $title = $resource->name;
    // Its turntable (#707), rendered by tools/render: a slowly turning picture,
    // and a still for visitors who ask their system to reduce motion
    $picture = 'images/resources/' . $resource->id;
    $hasPicture = is_file(public_path($picture . '.webp'));
@endphp
@section('pageTitle', $title)
@section('content')
    <x-page-header>
        <x-slot:title>{{$title}}</x-slot:title>
        <li><a href="/resources">Resources</a></li>
        <li class="active">{{$title}}</li>
    </x-page-header>

    <section class="section section-xs section-first text-md-left">

        <div class="container" id="container">
            <div class="row">
                <div class="col-12 {{ $hasPicture ? 'col-md-7 col-lg-8' : '' }}">
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
                </div>
                @if($hasPicture)
                    <div class="col-12 col-md-5 col-lg-4 mb-4 text-center resource-picture">
                        <picture>
                            <source srcset="{{ url($picture . '-still.webp') }}" media="(prefers-reduced-motion: reduce)">
                            <img src="{{ url($picture . '.webp') }}" alt="{{ $resource->name }}, turning slowly"
                                 width="400" height="400" style="width: 100%; max-width: min(400px, 70vw); height: auto;">
                        </picture>
                    </div>
                @endif
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