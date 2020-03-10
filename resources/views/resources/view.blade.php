@extends('layouts.app')
@php $title = $resource->name ; @endphp
@section('pageTitle', $title)
@section('content')
    <section class="breadcrumbs-modern box-transform-wrap bg-image context-dark"
             style="background-image: url('/images/slide-5.jpg');">
        <div class="container">
            <div class="breadcrumbs-modern-body">
                <h5 class="breadcrumbs-modern-subtitle"></h5>
                <h1 class="breadcrumbs-modern-title">{{$title}}</h1>
            </div>
            <ul class="breadcrumbs-path breadcrumbs-modern-path">
                <li><a href="/">Home</a></li>
                <li><a href="/resources">Resources</a></li>
                <li class="active">{{$title}}</li>
            </ul>
        </div>
        <div class="box-transform" style="background-image: url('/images/slide-5.jpg');"></div>
        <!--  <div class="comets comets-left"></div>
          <div class="comets comets-center"></div>
          <div class="comets comets-top"></div>-->
    </section>

    <section class="section section-xs section-first bg-default text-md-left">

        <div class="container grid-demonstration" id="container">
            <div class="row row-xxl row-30 row-md-60 description-fields">
                <div class="col-12 col-lg-6 col mb-0 mb-lg-4">
                    <b>Id: </b>{{$resource->id}}<br/>
                </div>
                <div class="col-12 col-lg-6 ">
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
            <div class="row row-xxl row-30 row-md-60">
                <div class="col">
                    <h2 class="wow fadeInLeft" style="visibility: visible; animation-name: fadeInLeft;">
                        Buy Orders</h2>
                    <resource-orders-table :resource-id="{{$resource->id}}"
                                           data-url="/buys/search?resource={{$resource->id}}&type={{App\EventType::BUY_RESOURCE}}"
                                           :operation="{{\App\EventType::BUY_RESOURCE}}"></resource-orders-table>
                </div>
            </div>
            <div class="row row-xxl row-30 row-md-60">
                <div class="col">
                    <h2 class="wow fadeInLeft" style="visibility: visible; animation-name: fadeInLeft;">
                        Sell Orders</h2>
                    <resource-orders-table :resource-id="{{$resource->id}}"
                                           data-url="/sells/search?resource={{$resource->id}}&type={{App\EventType::BUY_RESOURCE}}"
                                           :operation="{{\App\EventType::BUY_RESOURCE}}"></resource-orders-table>
                </div>
            </div>
        </div>
    </section>
@endsection