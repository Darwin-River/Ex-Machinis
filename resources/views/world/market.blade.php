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

            {{-- Where fuel is on sale now (#609): round 63's drones stranded where none was --}}
            <p id="fuel-for-sale" @class(['health-banner' => $fuel['none']]) style="white-space: normal">
                <b>Fuel on sale now:</b>
                @forelse($fuel['selling'] as $f)
                    {{ $f['place'] }} {{ number_format($f['units']) }} from {{ $f['price'] }} cr{{ $loop->last ? '.' : ';' }}
                @empty
                    nowhere.
                @endforelse
                @if($fuel['none'])
                    No fuel on sale at {{ implode(', ', $fuel['none']) }}: a drone there with an empty tank waits until someone sells some.
                @endif
            </p>

            {{-- Jump links (#591): the page is long --}}
            <nav class="page-jumps" style="white-space: normal; margin-bottom: 1em">
                Jump to: <a href="#order-book">Order book</a> · <a href="#house-demand">House demand</a> · <a href="#house-supply">House supply</a> · <a href="#trades-heading">Recent trades</a>
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
                surface at the start of each game day, and its bid refills. The bid and the units come from the House's
                buy order on the book now, so they're what a sale gets. Where an exchange also sells the good, it buys back
                its own stock to sell too (up to a quarter of a day's sales), so what it still takes can be more than its
                day's demand. <i>Sold today</i> is what drones have sold into
                it since the day began, and <i>Runs out in</i> how long what's left lasts at that pace. Drones ask with <code>house_demand(r)</code>;
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
                    <tr><th>Resource</th><th>Where</th><th class="num">Bid now</th><th class="num">Listed</th><th class="num">Still buying today</th><th class="num">Sold today</th><th>Runs out in</th><th>Refills in</th></tr>
                    @forelse($demand as $d)
                        <tr>
                            <td>{{ $d['resource_name'] }}</td><td>{{ $d['place_name'] }}</td>
                            <td class="num">{{ $d['left'] > 0 ? number_format($d['price']) . ' cr' : 'no bid' }}</td><td class="num">{{ number_format($d['listed']) }} cr</td>
                            <td class="num">{{ number_format($d['left']) }} of {{ number_format($d['daily']) }}</td>
                            <td class="num">{{ number_format($d['sold']) }}</td>
                            <td>{{ $d['empty_hours'] !== null ? $d['empty_hours'] . ' game ' . ($d['empty_hours'] === 1 ? 'hour' : 'hours') : ($d['left'] > 0 ? '–' : 'bought its fill') }}</td>
                            <td>{{ $d['hours'] }} game {{ $d['hours'] === 1 ? 'hour' : 'hours' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8">The House buys none of it.</td></tr>
                    @endforelse
                </table>
            </div>

            {{-- What the House sells, and when it restocks (#630; round 64: supply ran out with no word of when it came back) --}}
            <h3 class="mt-4" id="house-supply">House supply</h3>
            <div style="white-space: normal; margin-bottom: 1em">
                Each House exchange sells goods from stock its station drone brings up from the surface. In rounds,
                about every game hour or two, it tops each good it sells back up to a quarter of a day's supply. A good
                that sells out is back, up to that, at its next round. <i>On sale</i> is what a purchase can take now,
                <i>Restocked</i> when it last brought this good up, and <i>Next round</i> when its next round is due, from
                how often its rounds have come over the last two game days. Drones ask with <code>house_supply(r)</code>;
                programs with <code>/api/v1/house-supply</code>.
            </div>
            <form method="get" action="{{ url()->current() }}#house-supply" style="white-space: normal; margin-bottom: 1em">
                <select name="supply" aria-label="A good the House sells">
                    <option value="">The goods closest to sold out</option>
                    @foreach($supplyGoods as $id => $name)
                        <option value="{{ $id }}" {{ $supplyResource === (int) $id ? 'selected' : '' }}>{{ $name }}</option>
                    @endforeach
                </select>
                <button type="submit">Show</button>
            </form>
            <div class="table-custom-responsive">
                <table class="table-ticket" id="house-supply-table">
                    <tr><th>Resource</th><th>Where</th><th class="num">Price</th><th class="num">On sale</th><th class="num">Sold today</th><th>Restocked</th><th>Next round</th></tr>
                    @forelse($supply as $s)
                        <tr>
                            <td>{{ $s['resource_name'] }}</td><td>{{ $s['place_name'] }}</td>
                            <td class="num">{{ $s['on_sale'] > 0 ? number_format($s['price']) . ' cr' : 'sold out' }}</td>
                            <td class="num">{{ number_format($s['on_sale']) }} of {{ number_format($s['refill_to']) }}</td>
                            <td class="num">{{ number_format($s['sold']) }}</td>
                            <td>{{ $s['last_restock_hours'] !== null ? $s['last_restock_hours'] . ' game hours ago' : 'not in two game days' }}</td>
                            <td>{{ $s['next_hours'] === null ? '–' : ($s['next_hours'] <= 0 ? 'due now' : 'in ' . $s['next_hours'] . ' game hours') }}{{ $s['round_hours'] !== null ? ' (every ' . $s['round_hours'] . ')' : '' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7">The House sells none of it.</td></tr>
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
