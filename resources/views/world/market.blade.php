@extends('layouts.app')
@section('pageTitle', 'Market')
@section('content')
    {{-- The Market page (#324 step 17): House stations, the order book and recent trades --}}
    <section class="section section-xs section-first bg-default text-md-left">
        <div class="container" id="container">
            <h2>Market</h2>
            <div style="white-space: normal; margin-bottom: 1em">
                Everything here is public and live. Drones themselves see live orders only where they are, and the
                rest an hour late (the price board, query 680).
            </div>

            <h3>House stations</h3>
            <div style="white-space: normal; margin-bottom: 1em">
                The House trades at each exchange through a station drone, by the players' rules: its buy and sell
                orders are in the order book, and a drone sells to it (sell-to-station, or a push into its buy order)
                or buys from it (buy-from-station, or a pull) at once. It buys at the listed price while its stock is
                low and pays less as it fills, down to half at a day's worth; once a game day it ships what it bought
                down to the planet, and it brings up what it sells.
            </div>
            @foreach($stations as $station)
                <h4 class="mt-3">{{ $station['name'] }} <small>at <a href="{{ url('locations/' . $station['object_id']) }}">{{ $station['place'] }}</a>@if($station['drone']),
                    station drone <a href="{{ url('spacecraft/' . $station['drone']) }}">{{ $station['drone'] }}</a>@endif</small></h4>
                <div class="table-custom-responsive">
                    <table class="table-ticket">
                        <tr><th>Good</th><th>Station</th><th>Price now</th><th>Listed price</th><th>Per day</th><th>In stock</th><th>Takes / for sale</th></tr>
                        @foreach($station['goods'] as $good)
                            <tr>
                                <td><a href="{{ url('resources/' . $good['resource']) }}">{{ $good['name'] }}</a></td>
                                <td>{{ $good['buys'] ? 'buys' : 'sells' }}</td>
                                <td>{{ $good['price'] === null ? 'not posted yet' : number_format($good['price']) }}</td>
                                <td>{{ number_format($good['listed']) }}</td>
                                <td>{{ number_format($good['daily']) }}</td>
                                <td>{{ number_format($good['stock']) }}</td>
                                <td>{{ number_format($good['room']) }}</td>
                            </tr>
                        @endforeach
                    </table>
                </div>
            @endforeach

            <h3 class="mt-4">Order book</h3>
            <div style="white-space: normal; margin-bottom: 1em">The cheapest sell orders and the best buy orders.
                Full lists: <a href="{{ url('sells') }}">sell orders</a>, <a href="{{ url('buys') }}">buy orders</a>.</div>
            @foreach(['Selling' => $sells, 'Buying' => $buys] as $title => $orders)
                <h4>{{ $title }}</h4>
                <div class="table-custom-responsive">
                    <table class="table-ticket">
                        <tr><th>Resource</th><th>Price</th><th>Units</th><th>Drone</th><th>Where</th></tr>
                        @forelse($orders as $order)
                            <tr>
                                <td>{{ $order['resource_name'] }}</td><td>{{ number_format($order['price']) }}</td>
                                <td>{{ number_format($order['units']) }}</td>
                                <td><a href="{{ url('spacecraft/' . $order['drone']) }}">{{ $order['drone_name'] }}</a></td>
                                <td>{{ $order['place'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5">No open orders.</td></tr>
                        @endforelse
                    </table>
                </div>
            @endforeach

            <h3 class="mt-4">Recent trades</h3>
            <div class="table-custom-responsive">
                <table class="table-ticket" id="trades">
                    <tr><th>When</th><th>Resource</th><th>Units</th><th>Credits</th><th>A unit</th><th>Seller</th><th>Buyer</th><th>Where</th></tr>
                    @forelse($trades as $trade)
                        <tr>
                            <td>{{ $trade['when'] }}</td><td>{{ $trade['resource'] }}</td><td>{{ number_format($trade['units']) }}</td>
                            <td>{{ number_format($trade['credits']) }}</td><td>{{ $trade['price'] }}</td>
                            <td><a href="{{ url('spacecraft/' . $trade['seller_id']) }}">{{ $trade['seller'] }}</a></td>
                            <td><a href="{{ url('spacecraft/' . $trade['buyer_id']) }}">{{ $trade['buyer'] }}</a></td>
                            <td>{{ $trade['place'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8">No trades yet.</td></tr>
                    @endforelse
                </table>
            </div>
        </div>
    </section>
@endsection
