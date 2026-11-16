@extends('layouts.app')
@section('pageTitle', 'Market')
@include('partials.market-styles')
@section('content')
    {{-- The Market page (#721): a goods board, one row per good. Round 70: it was 28,300 pixels of order tables;
         each good's whole order book is on its own page, the House's demand and supply on /market/house --}}
    <x-page-header :title="'Market'" :subtitle="'Where each good is cheapest to buy and where it sells for most, right now. Click a good for its order book and price history.'" />
    @php
        $colours = ['#3987e5', '#d95926', '#199e70', '#c98500', '#d55181', '#9085e9'];
        $colour = [];
        foreach (array_keys($places) as $i => $id) { $colour[$id] = $colours[$i % count($colours)]; }
        $n = fn ($v) => number_format($v);
    @endphp
    <section class="section section-xs section-first">
        <div class="container" id="container">
            {{-- Where fuel is on sale now (#609): round 63's drones stranded where none was --}}
            <p id="fuel-for-sale" @class(['mk-lead', 'health-banner' => $fuel['none']])>
                <b>Fuel on sale now:</b>
                @forelse($fuel['selling'] as $f)
                    {{ $f['place'] }} {{ $n($f['units']) }} from {{ $f['price'] }} cr{{ $loop->last ? '.' : ';' }}
                @empty
                    nowhere.
                @endforelse
                @if($fuel['none'])
                    No fuel on sale at {{ implode(', ', $fuel['none']) }}: a drone there with an empty tank waits until someone sells some.
                @endif
            </p>

            <h2 class="h4 mt-4" id="board-heading">Where is each good cheapest, and where does it pay most?</h2>
            <div class="mk-bar">
                <nav aria-label="Show">
                    <ul class="mk-chips" id="board-filters">
                        @foreach(\App\Services\MarketBoard::FILTERS as $key => $label)
                            @continue($counts[$key] === 0 && $key !== $filter)
                            <li><a class="mk-chip" href="{{ url('market') . ($key === 'traded' ? '' : '?show=' . $key) }}#board-heading"
                                   @if($key === $filter) aria-current="page" @endif>{{ $label }} <small>{{ $counts[$key] }}</small></a></li>
                        @endforeach
                    </ul>
                </nav>
                {{-- Any good's page, by name (#721: the old "Show" select, styled) --}}
                <form class="mk-form" method="get" action="{{ url('market') }}">
                    <label for="good">Go to a good</label>
                    <select class="mk-select" id="good" name="resource">
                        @foreach($goods as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                    <button class="mk-button" type="submit">Go</button>
                </form>
            </div>
            <div class="mk-key" aria-hidden="true">
                @foreach($places as $id => $name)<span><i style="background: {{ $colour[$id] }}"></i>{{ $name }}</span>@endforeach
                <span><i style="background: #9aa3b8"></i>cheapest seller</span><span><i class="mk-ring"></i>best buyer</span>
            </div>

            @if(empty($rows))
                <p class="mk-empty">No open orders right now.</p>
            @else
                <ol class="mk-board" id="board">
                    @foreach($rows as $r)
                        @php
                            $span = max(1, $r['high'] - $r['low']);
                            $at = fn ($v) => $r['high'] === $r['low'] ? 50 : round(6 + 88 * ($v - $r['low']) / $span, 1);
                            $edge = fn ($pos) => $pos < 15 ? 'mk-l' : ($pos > 85 ? 'mk-r' : '');
                            $summary = collect($r['places'])->map(fn ($p) => $p['place'] . ': '
                                . ($p['ask'] !== null ? 'sells from ' . $p['ask'] : 'no seller') . ', '
                                . ($p['bid'] !== null ? 'buys at ' . $p['bid'] : 'no buyer'))->implode('; ');
                        @endphp
                        <li><a class="mk-row" href="{{ url('resources/' . $r['resource']) }}#order-book" data-resource="{{ $r['resource'] }}">
                            <span class="mk-name"><b>{{ $r['name'] }}</b>
                                <small>{{ ucfirst(\App\Services\MarketBoard::SHORT_TIERS[$r['tier_name']] ?? $r['tier_name']) }} &middot; {{ $r['units'] > 0 ? $n($r['units']) . ' traded, 7 days' : 'no trades, 7 days' }}</small></span>
                            <span class="mk-spread" role="img" aria-label="{{ $summary }}">
                                <span class="mk-axis"></span>
                                @foreach($r['places'] as $p)
                                    @if($p['bid'] !== null)
                                        @php($pos = $at($p['bid']))
                                        <span class="mk-dot mk-buy" style="left: {{ $pos }}%; color: {{ $colour[$p['place_id']] }}" title="{{ $p['place'] }} buys at {{ $n($p['bid']) }}">
                                            @if($r['best_bid'] && $p['place'] === $r['best_bid']['place'] && $p['bid'] === $r['best_bid']['price'])<em class="{{ $edge($pos) }}">{{ $p['place'] }} buys {{ $n($p['bid']) }}</em>@endif
                                        </span>
                                    @endif
                                    @if($p['ask'] !== null)
                                        @php($pos = $at($p['ask']))
                                        <span class="mk-dot mk-sell" style="left: {{ $pos }}%; color: {{ $colour[$p['place_id']] }}" title="{{ $p['place'] }} sells from {{ $n($p['ask']) }}">
                                            @if($r['cheapest'] && $p['place'] === $r['cheapest']['place'] && $p['ask'] === $r['cheapest']['price'])<em class="{{ $edge($pos) }}">{{ $p['place'] }} sells {{ $n($p['ask']) }}</em>@endif
                                        </span>
                                    @endif
                                @endforeach
                            </span>
                            <span class="mk-deal">
                                @if($r['margin'] !== null)
                                    Buy at {{ $r['cheapest']['place'] }}, sell at {{ $r['best_bid']['place'] }}<br>
                                    <b>{{ $n($r['cheapest']['price']) }} &rarr; {{ $n($r['best_bid']['price']) }}</b> <span class="mk-gain">+{{ $n($r['margin']) }} a unit</span>
                                @elseif($r['cheapest'])
                                    Buy cheapest<br><b>{{ $n($r['cheapest']['price']) }} &middot; {{ $r['cheapest']['place'] }}</b>
                                    @if($r['best_bid'])<br>Sells for {{ $n($r['best_bid']['price']) }} &middot; {{ $r['best_bid']['place'] }}@endif
                                @else
                                    Sell dearest<br><b>{{ $n($r['best_bid']['price']) }} &middot; {{ $r['best_bid']['place'] }}</b><br>nobody sells it
                                @endif
                            </span>
                            <span class="mk-trend">
                                @if($r['change_percent'] === null)
                                    <span class="mk-flat">&ndash;</span><small>{{ $r['units'] > 0 ? 'avg ' . $r['average'] : 'no trades' }}</small>
                                @else
                                    <span @class(['mk-up' => $r['change_percent'] > 0, 'mk-down' => $r['change_percent'] < 0, 'mk-flat' => $r['change_percent'] === 0])>{{ $r['change_percent'] > 0 ? '+' : ($r['change_percent'] < 0 ? '−' : '') }}{{ abs($r['change_percent']) }}%</span>
                                    <small>avg {{ $r['average'] }}</small>
                                @endif
                            </span>
                        </a></li>
                    @endforeach
                </ol>
            @endif
            <p class="mk-src">Data: <code>GET /api/v1/orders?side=sell</code> and <code>?side=buy</code>, the best price for each good and place;
                the change is the average price over the last 7 game days against the 7 before, from <code>GET /api/v1/history</code>. Worked out once a minute.</p>

            <p class="mk-lead mt-3">Everything here is public and live. Drones themselves see live orders only where they are, and the
                rest an hour late (the price board, query 680). Prices day by day are on <a href="{{ url('market/history') }}">Market history</a>,
                and the whole economy on the <a href="{{ url('dashboard') }}">dashboard</a>.</p>

            <ul class="mk-links">
                <li><a href="{{ url('market/house') }}" id="house-link">What does the House buy and sell? (House demand and supply)</a></li>
                <li><a href="{{ url('sells') }}">Every sell order</a></li>
                <li><a href="{{ url('buys') }}">Every buy order</a></li>
            </ul>

            <details class="mk-fold" id="trades-heading">
                <summary>Latest trades</summary>
                <div class="mk-scroll">
                    <table class="mk-table mk-cards" id="trades">
                        <thead><tr><th>Good</th><th>When</th><th class="num">Units</th><th class="num">A unit</th><th>Seller</th><th>Buyer</th><th>Where</th></tr></thead>
                        <tbody>
                        @forelse($trades as $trade)
                            <tr>
                                <td>{{ $trade['resource'] }}</td><td data-label="When">{{ $trade['when'] }}</td>
                                <td class="num" data-label="Units">{{ $n($trade['units']) }}</td><td class="num" data-label="A unit">{{ $trade['price'] }}</td>
                                <td data-label="Seller"><a href="{{ url('spacecraft/' . $trade['seller_id']) }}">{{ $trade['seller'] }}</a></td>
                                <td data-label="Buyer"><a href="{{ url('spacecraft/' . $trade['buyer_id']) }}">{{ $trade['buyer'] }}</a></td>
                                <td data-label="Where">{{ $trade['place'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7">No trades yet.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                <p class="mk-src">Data: <code>GET /api/v1/trades</code></p>
            </details>
        </div>
    </section>
    <script>
        // The manual's old anchors on this page (#721): House demand and supply moved to /market/house
        (function () {
            var id = location.hash.slice(1);
            if (id === 'house-demand' || id === 'house-supply') location.replace('{{ url('market/house') }}#' + id);
            if (id === 'order-book') location.replace('#board-heading');
        })();
    </script>
@endsection
