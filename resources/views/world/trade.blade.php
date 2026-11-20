@extends('layouts.app')
@section('pageTitle', 'Trading desk')
@include('partials.market-styles')
@push('styles')
    <style>
        /* The Trading desk (#779): watch list, ladder and ticket side by side, your shares and script below */
        .td-grid { display: grid; grid-template-columns: minmax(0, 300px) minmax(0, 1fr); gap: 16px; align-items: start; }
        .td-grid-2 { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); }
        @media (max-width: 860px) { .td-grid, .td-grid-2 { grid-template-columns: minmax(0, 1fr); } }
        .td-card { background: #111729; border: 1px solid #263052; border-radius: 12px; padding: 12px 14px; white-space: normal; }
        .td-card h2 { font-size: 1.05rem; margin: 0 0 .6rem; }
        .td-watch { list-style: none; margin: 0; padding: 0; }
        .td-watch li a { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 2px 8px; padding: 7px 8px; border-radius: 8px;
            color: #e6e9f2 !important; text-decoration: none; border: 1px solid transparent; }
        .td-watch li a:hover { border-color: #263052; }
        .td-watch li a[aria-current="true"] { border-color: #ffb547; background: rgba(255, 181, 71, .06); }
        .td-watch small { color: #9aa3b8; }
        .td-pill { display: inline-block; font-size: 11px; font-weight: 700; padding: 0 8px; border-radius: 99px; white-space: nowrap; }
        .td-pill-ok { background: rgba(90, 209, 154, .16); color: #5ad19a; }
        .td-pill-warn { background: rgba(240, 180, 41, .16); color: #f0b429; }
        .td-pill-bad { background: rgba(255, 138, 122, .16); color: #ff8a7a; }
        .td-ladder { width: 100%; border-collapse: collapse; font-variant-numeric: tabular-nums; }
        .td-ladder td { padding: 3px 8px; border-top: 1px solid #1c2440; }
        .td-ladder td.num { text-align: right; white-space: nowrap; }
        .td-ask td:first-child { color: #ff8a7a; }
        .td-bid td:first-child { color: #5ad19a; }
        .td-bank td { color: #4cc9f0; font-weight: 600; }
        .td-band td { color: #6b7283; font-style: italic; }
        .td-spread td { color: #9aa3b8; text-align: center !important; font-size: 13px; }
        .td-ticket { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: .6rem 0; }
        .td-say { background: #0b1020; border-left: 3px solid #ffb547; padding: 8px 12px; border-radius: 6px; min-height: 2.6em; }
        .td-gain-up { color: #5ad19a; } .td-gain-down { color: #ff8a7a; }
        .td-log { font: 13px ui-monospace, monospace; color: #c3c9d8; margin: 0; padding-left: 1.1rem; }
    </style>
@endpush
@section('content')
    <x-page-header :title="'Trading desk'">
        <li><a href="{{ url('market') }}">Market</a></li>
        <li class="active">Trading desk</li>
    </x-page-header>
    <section class="section section-xs section-first text-md-left">
        <div class="container" id="trading-desk">
            @include('partials.market-tabs')
            @if($desk['board'] === null)
                <p>Players trade matured companies' shares under the new rules, which aren't on in this world.</p>
            @elseif(!$desk['watch'])
                <p>No company has matured yet, so there are no shares to trade. <a href="{{ url('getting-started') }}">Found a startup</a>.</p>
            @else
                @php($L = $desk['ladder'])
                @php($me = $desk['me'])
                <p class="mh-intro">Pick a company on the left; its book is in the middle, from the dearest offer at the top to the
                    best bid at the bottom, between the bank's two prices. Players trade between the band's two ends. The ticket says,
                    before you post, what your order will do.</p>
                <div class="td-grid">
                    {{-- The watch list: your holdings first, each with a health pill --}}
                    <div class="td-card">
                        <h2>Watch list</h2>
                        <ul class="td-watch" id="watch-list">
                            @foreach($desk['watch'] as $w)
                                <li><a href="{{ url('market/trade?company=' . $w['company']) }}" @if($w['company'] === $desk['company']) aria-current="true" @endif>
                                    <b>{{ $w['name'] }}</b><span class="td-pill td-pill-{{ $w['health'][0] }}" @if($w['note']) title="{{ $w['note'] }}" @endif>{{ $w['health'][1] }}</span>
                                    <small>cash-out {{ number_format($w['buys_at']) }} · return {{ $w['return_percent'] === null ? '–' : ($w['return_percent'] > 0 ? '+' : '') . $w['return_percent'] . '%' }}</small>
                                    <small>@if($w['held'] > 0)you hold {{ $w['held'] }} @elseif($w['offered'] > 0)new shares on offer @endif</small>
                                </a></li>
                            @endforeach
                        </ul>
                    </div>

                    <div>
                        {{-- The ladder: the band, the bank's prices and players' orders, dearest first --}}
                        <div class="td-card mb-3" id="ladder">
                            <h2><a href="{{ url('companies/' . $L['company']) }}#player-shares">{{ $L['name'] }}</a>
                                <small class="text-muted">{{ $L['count'] }} shares</small></h2>
                            @php($p = $L['prices'])
                            @php($rows = collect($L['asks'])->map(fn ($o) => $o + ['kind' => 'ask'])->concat(collect($L['bids'])->map(fn ($o) => $o + ['kind' => 'bid'])))
                            <table class="td-ladder">
                                <tr class="td-band"><td>Top of the band</td><td></td><td class="num">{{ number_format($p['high']) }}</td></tr>
                                @foreach($rows->where('kind', 'ask')->where('price', '>', $p['sells_at']) as $o)
                                    <tr class="td-ask"><td>Sell · {{ $o['by'] }}@if($o['script']) <small>(script)</small>@endif</td><td class="num">{{ $o['units'] }}</td><td class="num">{{ number_format($o['price']) }}</td></tr>
                                @endforeach
                                <tr class="td-bank"><td>The bank sells{{ $p['bank_holds'] > 0 ? ' (' . $p['bank_holds'] . ' held)' : ' (holds none)' }}</td><td></td><td class="num">{{ number_format($p['sells_at']) }}</td></tr>
                                @foreach($rows->where('kind', 'ask')->where('price', '<=', $p['sells_at']) as $o)
                                    <tr class="td-ask"><td>Sell · {{ $o['by'] }}@if($o['script']) <small>(script)</small>@endif</td><td class="num">{{ $o['units'] }}</td><td class="num">{{ number_format($o['price']) }}</td></tr>
                                @endforeach
                                @php($bestAsk = collect($L['asks'])->min('price'))
                                @php($bestBid = collect($L['bids'])->max('price'))
                                <tr class="td-spread"><td colspan="3">@if($bestAsk !== null && $bestBid !== null)spread {{ number_format($bestAsk - $bestBid) }}@else{{ $bestAsk === null && $bestBid === null ? 'no player orders' : ($bestAsk === null ? 'no player sells' : 'no player bids') }}@endif</td></tr>
                                @foreach($rows->where('kind', 'bid')->where('price', '>=', $p['buys_at']) as $o)
                                    <tr class="td-bid"><td>Buy · {{ $o['by'] }}@if($o['script']) <small>(script)</small>@endif</td><td class="num">{{ $o['units'] }}</td><td class="num">{{ number_format($o['price']) }}</td></tr>
                                @endforeach
                                <tr class="td-bank"><td>The bank buys (pays in {{ config('forge.bank_pay_days') }} game days)</td><td></td><td class="num">{{ number_format($p['buys_at']) }}</td></tr>
                                @foreach($rows->where('kind', 'bid')->where('price', '<', $p['buys_at']) as $o)
                                    <tr class="td-bid"><td>Buy · {{ $o['by'] }}@if($o['script']) <small>(script)</small>@endif</td><td class="num">{{ $o['units'] }}</td><td class="num">{{ number_format($o['price']) }}</td></tr>
                                @endforeach
                                <tr class="td-band"><td>Bottom of the band</td><td></td><td class="num">{{ number_format($p['low']) }}</td></tr>
                            </table>
                            @if($L['offering'])
                                <p class="mt-2 mb-0"><small><b>{{ $L['offering']['left'] }} new shares on offer</b> at cash-out or the market price if higher ({{ number_format($L['offering']['price']) }}):
                                    buy them on <a href="{{ url('companies/' . $L['company']) }}#share-offering">its page</a>; the credits go to the company.</small></p>
                            @endif
                        </div>

                        {{-- The ticket: what an order will do, in words, before it is posted --}}
                        <div class="td-card mb-3" id="ticket">
                            <h2>Order ticket</h2>
                            @if($me)
                                <form method="POST" action="{{ url('companies/' . $L['company'] . '/share-orders') }}">
                                    {{ csrf_field() }}
                                    <div class="td-ticket">
                                        <select class="mk-select" name="side" id="t-side" aria-label="Buy or sell"><option value="buy">Buy</option><option value="sell">Sell</option></select>
                                        <input class="mk-input" type="number" name="units" id="t-units" min="1" value="1" aria-label="Shares" style="width: 6em">
                                        <span>shares at</span>
                                        <input class="mk-input" type="number" name="price" id="t-price" min="{{ $p['low'] }}" max="{{ $p['high'] }}"
                                               value="{{ $bestAsk ?? $p['sells_at'] }}" aria-label="Price a share" style="width: 7em">
                                        <button class="mk-button" type="submit">Post the order</button>
                                    </div>
                                </form>
                                <div class="td-say" id="t-say" role="status" aria-live="polite"></div>
                            @else
                                <p><a href="{{ url('login') }}">Log in</a> to trade: the ticket says what an order will do before you post it.</p>
                            @endif
                        </div>
                    </div>
                </div>

                @if($me)
                    <div class="td-grid td-grid-2 mt-3">
                        {{-- Your shares, with their gain including dividends --}}
                        <div class="td-card" id="your-shares">
                            <h2>Your shares</h2>
                            @if($me['shares'])
                                <div class="table-responsive"><table class="td-ladder">
                                    <tr><td></td><td class="num">Shares</td><td class="num">Worth</td><td class="num">Cost</td><td class="num">Dividends</td><td class="num">Gain</td></tr>
                                    @foreach($me['shares'] as $s)
                                        <tr><td><a href="{{ url('market/trade?company=' . $s['company_id']) }}">{{ $s['name'] }}</a></td>
                                            <td class="num">{{ $s['units'] }}</td><td class="num">{{ number_format($s['value']) }}</td>
                                            <td class="num">{{ number_format($s['cost']) }}@if($s['founder_units'] > 0)<br><small class="text-muted">{{ $s['founder_units'] }} from maturing</small>@endif</td>
                                            <td class="num">{{ number_format($s['dividends']) }}</td>
                                            <td class="num {{ $s['gain'] >= 0 ? 'td-gain-up' : 'td-gain-down' }}">{{ $s['gain'] >= 0 ? '+' : '−' }}{{ number_format(abs($s['gain'])) }}@if($s['gain_percent'] !== null)<br><small>{{ $s['gain_percent'] > 0 ? '+' : '' }}{{ $s['gain_percent'] }}%</small>@endif</td></tr>
                                    @endforeach
                                </table></div>
                                <p class="ex-src">Worth is at today's cash-out value. Cost is what you paid for the shares you bought (at their average
                                    price); shares that came to you when your company matured cost nothing. Gain is worth less cost, plus the
                                    dividends they paid you.</p>
                            @else
                                <p>None yet. You have {{ number_format($me['credits']) }} credits @if($me['share_credits'] > 0) and {{ number_format($me['share_credits']) }}
                                    starting credits that buy shares at or below the bank's price @endif.</p>
                            @endif
                            @if($me['orders'])
                                <h2 class="mt-3">Your orders</h2>
                                <ul class="mb-0">
                                    @foreach($me['orders'] as $o)
                                        <li>{{ ucfirst($o['side']) }} {{ $o['units'] }} of {{ $o['name'] }} at {{ number_format($o['price']) }}@if($o['out_of_range']) <small class="text-muted">(outside today's band: it waits)</small>@endif</li>
                                    @endforeach
                                </ul>
                                <p class="ex-src">Cancel one on <a href="{{ url('account') }}#player-shares">your account page</a>.</p>
                            @endif
                        </div>
                        {{-- Your trading script and its latest run --}}
                        <div class="td-card" id="your-script">
                            <h2>Your trading script</h2>
                            @php($sc = $me['script'])
                            @if($sc)
                                <p>{{ $sc['enabled'] ? 'On' : 'Off' }} · {{ $sc['runs'] }} {{ \Illuminate\Support\Str::plural('run', $sc['runs']) }}@if($sc['last_run']) · last {{ $sc['last_run'] }}@endif</p>
                                @if($sc['log'])
                                    <p class="mb-1"><small class="text-muted">Its latest run ({{ $sc['log'][0]['when'] }}):</small></p>
                                    <ul class="td-log">@foreach($sc['log'][0]['lines'] as $line)<li>{{ $line }}</li>@endforeach</ul>
                                @endif
                                <p class="mt-2 mb-0"><a href="{{ url('account') }}#trade-script">Edit it, run it now, or read its whole log</a>.</p>
                            @else
                                <p>A trading script trades your shares while you're away, every few real minutes, under the same rules as you.
                                    Its orders show on the book as <i>(script)</i>.</p>
                                <p class="mb-0">Start from <a href="https://github.com/Darwin-River/Ex-Machinis/blob/main/examples/traders/payback.lua">the payback
                                    trader</a> (buy when a share pays back in under 40 game days, sell when a company is at risk), made for classes, or
                                    <a href="https://github.com/Darwin-River/Ex-Machinis/blob/main/examples/traders/value.lua">the value trader</a>, on
                                    <a href="{{ url('account') }}#trade-script">your account page</a>.</p>
                            @endif
                        </div>
                    </div>
                @endif
                <p class="ex-src mt-3">Data: <code>GET /api/v1/player-shares/{{ $L['company'] }}</code>, <code>GET /api/v1/account/shares</code>
                    and <code>GET /api/v1/account/trade-script</code>.</p>
                @if($me)
                @php($ticketData = \App\Services\TradingDesk::ticket($L, $me))
                <script>
                document.addEventListener('DOMContentLoaded', function () {
                    // What an order will do, worked out from the book shown (#779)
                    const B = @json($ticketData);
                    const side = document.getElementById('t-side'), units = document.getElementById('t-units'),
                        price = document.getElementById('t-price'), say = document.getElementById('t-say');
                    const n = (v) => Number(v).toLocaleString('en-US');
                    function tell() {
                        const s = side.value, N = Number(units.value), P = Number(price.value);
                        if (!Number.isInteger(N) || N < 1 || !Number.isInteger(P)) return say.textContent = 'Shares and price are whole numbers, at least 1.';
                        if (P < B.p.low || P > B.p.high) return say.textContent = `Refused: players trade ${B.name} between ${n(B.p.low)} and ${n(B.p.high)} a share now.`;
                        if (s === 'sell' && N > B.held) return say.textContent = `Refused: you hold ${B.held} of its shares.`;
                        const book = (s === 'buy' ? B.asks.filter((o) => o.price <= P) : B.bids.filter((o) => o.price >= P)).filter((o) => o.by !== B.me);
                        let left = N, cost = 0; const fills = [];
                        for (const o of book) { if (!left) break; const k = Math.min(left, o.units); fills.push(`${k} ${s === 'buy' ? 'from' : 'to'} ${o.by} at ${n(o.price)}`); cost += k * o.price; left -= k; }
                        const done = N - left;
                        let t = done ? `${s === 'buy' ? 'Buys' : 'Sells'} ${done} now: ${fills.join(', ')} (${n(cost)} credits ${s === 'buy' ? 'paid' : 'to you'}).` : 'Nothing on the book meets it now.';
                        if (left) t += ` ${done ? 'The other ' + left : 'All ' + left} stand as ${s === 'buy' ? 'a buy' : 'a sell'} order at ${n(P)} until someone meets it; once it has rested a game hour, each fill earns you a ${B.rebate}% rebate from the bank.`;
                        if (s === 'buy') {
                            const can = B.credits + (P <= B.p.sells_at ? B.share_credits : 0);
                            if (N * P > can) t += ` But it commits up to ${n(N * P)} credits and you can pay ${n(can)}${P > B.p.sells_at ? ' (starting credits pay only at or below the bank\'s ' + n(B.p.sells_at) + ')' : ''}: what can't be paid won't stand.`;
                            if (P > B.p.sells_at && B.p.bank_holds > 0) t += ` The bank sells for less (${n(B.p.sells_at)}).`;
                        } else if (P < B.p.buys_at) {
                            t += ` The bank buys for more (${n(B.p.buys_at)}), paid in ${B.pay_days} game days.`;
                        }
                        say.textContent = t;
                    }
                    [side, units, price].forEach((e) => e.addEventListener('input', tell));
                    tell();
                });
                </script>
                @endif
            @endif
        </div>
    </section>
@endsection
