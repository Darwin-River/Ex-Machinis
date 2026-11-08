@extends('layouts.app')
@php $title = $user->name ; @endphp
@section('pageTitle', $title)
@section('content')
    <section class="breadcrumbs-modern box-transform-wrap bg-image context-dark"
             style="background-image: url('/images/cityscape-1.jpg');">
        <div class="container">
            <div class="breadcrumbs-modern-body">
                <h5 class="breadcrumbs-modern-subtitle"></h5>
                <h1 class="breadcrumbs-modern-title">{{$title}}</h1>
            </div>
            <ul class="breadcrumbs-path breadcrumbs-modern-path">
                <li><a href="/">Home</a></li>
                <li><a href="/companies">Companies</a></li>
                <li class="active">{{$title}}</li>
            </ul>
        </div>
        <div class="box-transform" style="background-image: url('/images/cityscape-1.jpg');"></div>
    </section>
    <section class="section section-xs section-first bg-default text-md-left">
        <div class="container grid-demonstration" id="container">
            <div class="description-fields">
                <div class="row row-xxl row-30 row-md-60 ">
                    <div class="col-12 col-lg-12 col mb-0">
                        <b>Company Id</b>: <span style="font-weight: normal">{{$user->user_id}}</span><br/>
                    </div>
                    <div class="col-12 col-lg-12 col mb-0">
                        <b>Founded on</b>: <span
                                style="font-weight: normal">{{ \Carbon\Carbon::parse($user->created_at)->format('l, F j, Y') }}</span><br/>

                    </div>
                    <div class="col-12 col-lg-12 col ">

                        <b>Credits: </b><span style="font-weight: normal">{{$user->credits}}</span><br/>

                    </div>
                </div>
            </div>
            <div class="row row-xxl row-30 row-md-60">
                <div class="col">
                    <h2 class="wow fadeInLeft">Spacecraft</h2>
                </div>
            </div>

            <div class="table-custom-responsive">
                <table class="table-ticket">
                    @if(sizeof($spacecraft) == 0)
                        <tr>
                            <td colspan="4"> No spacecraft present.</td>
                        </tr>
                    @endif
                    @foreach($spacecraft as $agent)
                        <tr>
                            <td>
                                <a href="{{url('/spacecraft/'.$agent->agent_id)}}">{{$agent->name}}</a>
                            </td>
                            <td>
                            <span
                                    class="rd-nav-icon mdi mdi-border-outside"
                                    title="hull"></span> {{$agent->hull?$agent->hull->name:'no hull'}}
                            </td>
                            <td>
                                <span class="rd-nav-icon mdi mdi-map-marker" title="location"></span> <a
                                        href="{{url('locations/'.$agent->location->object_id)}}">{{$agent->location->object_name}}</a>
                            </td>
                            <td><span class="rd-nav-icon mdi mdi-package-down" title="cargo"></span>
                                @if(!$agent->hull || !$agent->hull->cargo_capacity)
                                    <span class="text-nowrap">no cargo capacity</span>
                                @else
                                    <span class="text-nowrap">{{( $agent->getCurrentCargoWeight())}}
                                        / {{$agent->hull->cargo_capacity}} Kg</span>
                                @endif

                            </td>
                        </tr>
                    @endforeach
                </table>
            </div>

            {{-- The stock market (#471) --}}
            <div class="row row-xxl row-30 row-md-60">
                <div class="col">
                    <h2 class="wow fadeInLeft">Shares</h2>
                    <p>{{ number_format($shares['count']) }} shares, each paying its part of the company's sales income after tax.
                        Last trade price: <b>{{ $shares['price'] !== null ? number_format($shares['price']) . ' credits' : 'never traded' }}</b>.</p>
                </div>
            </div>
            <div class="table-custom-responsive">
                <table class="table-ticket">
                    <tr><th>Holder</th><th>Shares</th><th>Dividends paid</th></tr>
                    @forelse($shares['holders'] as $h)
                        <tr><td><a href="{{ url('/companies/'.$h['company']) }}">{{ $h['name'] }}</a></td>
                            <td>{{ number_format($h['units']) }}</td><td>{{ number_format($h['dividends']) }}</td></tr>
                    @empty
                        <tr><td colspan="3">No holders.</td></tr>
                    @endforelse
                </table>
            </div>
            @if(count($shares['orders']))
                <h4>Orders at the Earth exchange</h4>
                <div class="table-custom-responsive">
                    <table class="table-ticket">
                        <tr><th>Company</th><th>Order</th><th>Price</th><th>Limit</th></tr>
                        @foreach($shares['orders'] as $o)
                            <tr><td><a href="{{ url('/companies/'.$o['company']) }}">{{ $o['name'] }}</a></td>
                                <td>{{ $o['side'] }}</td><td>{{ number_format($o['price']) }}</td>
                                <td>{{ $o['side'] === 'sell' ? 'keeps ' . $o['keep'] : 'wants ' . $o['want'] }}</td></tr>
                        @endforeach
                    </table>
                </div>
            @endif
            @if(count($shares['holdings']))
                <h4>Shares it holds in other companies</h4>
                <div class="table-custom-responsive">
                    <table class="table-ticket">
                        <tr><th>Company</th><th>Shares</th><th>Last price</th><th>Value</th><th>Dividends received</th></tr>
                        @foreach($shares['holdings'] as $h)
                            <tr><td><a href="{{ url('/companies/'.$h['company']) }}">{{ $h['name'] }}</a></td>
                                <td>{{ number_format($h['units']) }}</td>
                                <td>{{ $h['price'] !== null ? number_format($h['price']) : '-' }}</td>
                                <td>{{ number_format($h['value']) }}</td><td>{{ number_format($h['dividends']) }}</td></tr>
                        @endforeach
                    </table>
                </div>
            @endif
            @if(count($shares['trades']))
                <h4>Recent trades in its shares</h4>
                <div class="table-custom-responsive">
                    <table class="table-ticket">
                        <tr><th>Seller</th><th>Buyer</th><th>Shares</th><th>Price</th></tr>
                        @foreach($shares['trades'] as $t)
                            <tr><td><a href="{{ url('/companies/'.$t['seller']) }}">{{ $t['seller_name'] }}</a></td>
                                <td><a href="{{ url('/companies/'.$t['buyer']) }}">{{ $t['buyer_name'] }}</a></td>
                                <td>{{ number_format($t['units']) }}</td><td>{{ number_format($t['price']) }}</td></tr>
                        @endforeach
                    </table>
                </div>
            @endif
        </div>


    </section>
@endsection
