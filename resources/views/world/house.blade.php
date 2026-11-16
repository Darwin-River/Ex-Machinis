@extends('layouts.app')
@section('pageTitle', 'House demand and supply')
@include('partials.market-styles')
@section('content')
    {{-- The House's demand (#550) and supply (#630), moved off the Market page (#721) --}}
    <x-page-header :title="'House demand and supply'" :subtitle="'What the House exchanges still buy today, what they have on sale, and when each refills.'">
        <li><a href="{{ url('market') }}">Market</a></li>
        <li class="active">House</li>
    </x-page-header>
    @php($n = fn ($v) => number_format($v))
    <section class="section section-xs section-first">
        <div class="container" id="container">
            <h2 class="h4" id="house-demand">What does the House still buy today?</h2>
            <p class="mk-lead">
                Each House exchange buys up to a day's demand of each good. Its bid falls toward half its listed price
                as it fills, and it stops bidding once it has bought the day's demand. What it bought goes down to the
                surface at the start of each game day, and its bid refills. The bid and the units come from the House's
                buy order on the book now, so they're what a sale gets. Where an exchange also sells the good, it buys back
                its own stock to sell too (up to a quarter of a day's sales), so what it still takes can be more than its
                day's demand. <i>Sold today</i> is what drones have sold into
                it since the day began, and <i>Runs out in</i> how long what's left lasts at that pace. Drones ask with <code>house_demand(r)</code>.
            </p>
            <form class="mk-form mb-2" method="get" action="{{ url()->current() }}#house-demand">
                <label for="house">Show</label>
                <select class="mk-select" id="house" name="house">
                    <option value="">the bids closest to full</option>
                    @foreach($houseGoods as $id => $name)
                        <option value="{{ $id }}" {{ $houseResource === (int) $id ? 'selected' : '' }}>{{ $name }}</option>
                    @endforeach
                </select>
                @if($supplyResource !== null)<input type="hidden" name="supply" value="{{ $supplyResource }}">@endif
                <button class="mk-button" type="submit">Show</button>
            </form>
            <div class="mk-scroll">
                <table class="mk-table mk-cards" id="house-demand-table">
                    <thead><tr><th>Good</th><th>Where</th><th class="num">Bid now</th><th class="num">Listed</th><th class="num">Still buying today</th><th class="num">Sold today</th><th>Runs out in</th><th>Refills in</th></tr></thead>
                    <tbody>
                    @forelse($demand as $d)
                        <tr>
                            <td><a href="{{ url('resources/' . $d['resource']) }}">{{ $d['resource_name'] }}</a></td><td data-label="Where">{{ $d['place_name'] }}</td>
                            <td class="num" data-label="Bid now">{{ $d['left'] > 0 ? $n($d['price']) . ' cr' : 'no bid' }}</td><td class="num" data-label="Listed">{{ $n($d['listed']) }} cr</td>
                            <td class="num" data-label="Still buying today">{{ $n($d['left']) }} of {{ $n($d['daily']) }}</td>
                            <td class="num" data-label="Sold today">{{ $n($d['sold']) }}</td>
                            <td data-label="Runs out in">{{ $d['empty_hours'] !== null ? $d['empty_hours'] . ' game ' . ($d['empty_hours'] === 1 ? 'hour' : 'hours') : ($d['left'] > 0 ? '–' : 'bought its fill') }}</td>
                            <td data-label="Refills in">{{ $d['hours'] }} game {{ $d['hours'] === 1 ? 'hour' : 'hours' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8">The House buys none of it.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <p class="mk-src">Data: <code>GET /api/v1/house-demand</code></p>

            <h2 class="h4 mt-5" id="house-supply">What does the House have on sale?</h2>
            <p class="mk-lead">
                Each House exchange sells goods from stock its station drone brings up from the surface. In rounds,
                about every game hour or two, it tops each good it sells back up to a quarter of a day's supply. A good
                that sells out is back, up to that, at its next round. <i>On sale</i> is what a purchase can take now,
                <i>Restocked</i> when it last brought this good up, and <i>Next round</i> when its next round is due, from
                how often its rounds have come over the last two game days. Drones ask with <code>house_supply(r)</code>.
            </p>
            <form class="mk-form mb-2" method="get" action="{{ url()->current() }}#house-supply">
                <label for="supply">Show</label>
                <select class="mk-select" id="supply" name="supply">
                    <option value="">the goods closest to sold out</option>
                    @foreach($supplyGoods as $id => $name)
                        <option value="{{ $id }}" {{ $supplyResource === (int) $id ? 'selected' : '' }}>{{ $name }}</option>
                    @endforeach
                </select>
                @if($houseResource !== null)<input type="hidden" name="house" value="{{ $houseResource }}">@endif
                <button class="mk-button" type="submit">Show</button>
            </form>
            <div class="mk-scroll">
                <table class="mk-table mk-cards" id="house-supply-table">
                    <thead><tr><th>Good</th><th>Where</th><th class="num">Price</th><th class="num">On sale</th><th class="num">Sold today</th><th>Restocked</th><th>Next round</th></tr></thead>
                    <tbody>
                    @forelse($supply as $s)
                        <tr>
                            <td><a href="{{ url('resources/' . $s['resource']) }}">{{ $s['resource_name'] }}</a></td><td data-label="Where">{{ $s['place_name'] }}</td>
                            <td class="num" data-label="Price">{{ $s['on_sale'] > 0 ? $n($s['price']) . ' cr' : 'sold out' }}</td>
                            <td class="num" data-label="On sale">{{ $n($s['on_sale']) }} of {{ $n($s['refill_to']) }}</td>
                            <td class="num" data-label="Sold today">{{ $n($s['sold']) }}</td>
                            <td data-label="Restocked">{{ $s['last_restock_hours'] !== null ? $s['last_restock_hours'] . ' game hours ago' : 'not in two game days' }}</td>
                            <td data-label="Next round">{{ $s['next_hours'] === null ? '–' : ($s['next_hours'] <= 0 ? 'due now' : 'in ' . $s['next_hours'] . ' game hours') }}{{ $s['round_hours'] !== null ? ' (every ' . $s['round_hours'] . ')' : '' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7">The House sells none of it.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <p class="mk-src">Data: <code>GET /api/v1/house-supply</code></p>
        </div>
    </section>
@endsection
