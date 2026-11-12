@extends('layouts.app')
@section('pageTitle', 'Market')
@push('styles')
    <style>
        /* Numbers right-aligned with units; resource names readable (#591) */
        #container .table-ticket .num { text-align: right !important; font-variant-numeric: tabular-nums; white-space: nowrap; }
        #container .table-ticket td.resource { font-weight: 600; color: #fff !important; }
    </style>
@endpush
@section('content')
    {{-- The Market page (#324 step 17): the order book and recent trades --}}
    <x-page-header :title="'Market'" />
    <section class="section section-xs section-first text-md-left">
        <div class="container" id="container">
            <div style="white-space: normal; margin-bottom: 1em">
                Everything here is public and live. Drones themselves see live orders only where they are, and the
                rest an hour late (the price board, query 680). Prices and volumes day by day are charted on
                <a href="{{ url('market/history') }}">Market history</a>.
            </div>

            {{-- Jump links (#591): the page is long --}}
            <nav class="page-jumps" style="white-space: normal; margin-bottom: 1em">
                Jump to: <a href="#order-book">Order book</a> · <a href="#house-demand">House demand</a> · <a href="#trades-heading">Recent trades</a>
            </nav>

            <h3 id="order-book">Order book</h3>
            <form method="get" action="{{ url()->current() }}#order-book" style="white-space: normal; margin-bottom: 1em">
                Show
                <select name="resource">
                    <option value="">every resource</option>
                    @foreach($bookResources as $id => $name)
                        <option value="{{ $id }}" {{ $bookResource === (int) $id ? 'selected' : '' }}>{{ $name }}</option>
                    @endforeach
                </select>
                @if($houseResource !== null)<input type="hidden" name="house" value="{{ $houseResource }}">@endif
                <button type="submit">Show</button>
            </form>
            <div style="white-space: normal; margin-bottom: 1em">For each resource, the cheapest sell order and the best buy order at each place.
                Full lists: <a href="{{ url('sells') }}">sell orders</a>, <a href="{{ url('buys') }}">buy orders</a>.</div>
            @foreach(['Selling' => $sells, 'Buying' => $buys] as $title => $orders)
                <h4>{{ $title }}</h4>
                <div class="table-custom-responsive">
                    <table class="table-ticket">
                        <tr><th>Resource</th><th class="num">Price</th><th class="num">Units</th><th>Drone</th><th>Where</th></tr>
                        @forelse($orders as $order)
                            <tr>
                                <td class="resource">{{ $order['resource_name'] }}</td><td class="num">{{ number_format($order['price']) }} cr</td>
                                <td class="num">{{ number_format($order['units']) }}</td>
                                <td><a href="{{ url('spacecraft/' . $order['drone']) }}">{{ $order['drone_name'] }}</a></td>
                                <td>{{ $order['place'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5">No open orders.</td></tr>
                        @endforelse
                    </table>
                </div>
            @endforeach

            {{-- The House's demand (#550): how much more it takes today, and when it refills --}}
            <h3 class="mt-4" id="house-demand">House demand</h3>
            <div style="white-space: normal; margin-bottom: 1em">
                Each House exchange buys up to a day's demand of each good. Its bid falls toward half its listed price
                as it fills, and it stops bidding once it has bought the day's demand. What it bought goes down to the
                surface at the start of each game day, and its bid refills. Drones ask with <code>house_demand(r)</code>;
                programs with <code>/api/v1/house-demand</code>.
            </div>
            <form method="get" action="{{ url()->current() }}#house-demand" style="white-space: normal; margin-bottom: 1em">
                <select name="house">
                    <option value="">The bids closest to full</option>
                    @foreach($houseGoods as $id => $name)
                        <option value="{{ $id }}" {{ $houseResource === (int) $id ? 'selected' : '' }}>{{ $name }}</option>
                    @endforeach
                </select>
                <button type="submit">Show</button>
            </form>
            <div class="table-custom-responsive">
                <table class="table-ticket" id="house-demand-table">
                    <tr><th>Resource</th><th>Where</th><th class="num">Bid now</th><th class="num">Listed</th><th class="num">Still buying today</th><th>Refills in</th></tr>
                    @forelse($demand as $d)
                        <tr>
                            <td>{{ $d['resource_name'] }}</td><td>{{ $d['place_name'] }}</td>
                            <td class="num">{{ number_format($d['price']) }} cr</td><td class="num">{{ number_format($d['listed']) }} cr</td>
                            <td class="num">{{ number_format($d['left']) }} of {{ number_format($d['daily']) }}</td>
                            <td>{{ $d['hours'] }} game {{ $d['hours'] === 1 ? 'hour' : 'hours' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6">The House buys none of it.</td></tr>
                    @endforelse
                </table>
            </div>

            <h3 class="mt-4" id="trades-heading">Recent trades</h3>
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
