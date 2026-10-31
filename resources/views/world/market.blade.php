@extends('layouts.app')
@section('pageTitle', 'Market')
@section('content')
    {{-- The Market page (#324 step 17): the order book and recent trades --}}
    <section class="section section-xs section-first bg-default text-md-left">
        <div class="container" id="container">
            <h2>Market</h2>
            <div style="white-space: normal; margin-bottom: 1em">
                Everything here is public and live. Drones themselves see live orders only where they are, and the
                rest an hour late (the price board, query 680). Prices and volumes day by day are charted on
                <a href="{{ url('market/history') }}">Market history</a>.
            </div>

            <h3>Order book</h3>
            <div style="white-space: normal; margin-bottom: 1em">For each resource, the cheapest sell order and the best buy order at each place.
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
