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
        .td-pill { display: inline-block; font-size: 12px; font-weight: 700; padding: 0 8px; border-radius: 99px; white-space: nowrap; }
        .td-pill-ok { background: rgba(90, 209, 154, .16); color: #5ad19a; }
        .td-pill-warn { background: rgba(240, 180, 41, .16); color: #f0b429; }
        .td-pill-bad { background: rgba(255, 138, 122, .16); color: #ff8a7a; }
        .td-ladder { width: 100%; border-collapse: collapse; font-variant-numeric: tabular-nums; }
        .td-ladder td { padding: 3px 8px; border-top: 1px solid #1c2440; }
        .td-ladder td.num { text-align: right; white-space: nowrap; }
        .td-ask td:first-child { color: #ff8a7a; }
        .td-bid td:first-child { color: #5ad19a; }
        .td-bank td { color: #4cc9f0; font-weight: 600; }
        .td-band td { color: #9aa3b8; font-style: italic; }   /* #840: 3.69:1 was too faint */
        .td-spread td { color: #9aa3b8; text-align: center !important; font-size: 13px; }
        .td-ticket { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: .6rem 0; }
        .td-say { background: #0b1020; border-left: 3px solid #ffb547; padding: 8px 12px; border-radius: 6px; min-height: 2.6em; }
        .td-gain-up { color: #5ad19a; } .td-gain-down { color: #ff8a7a; }
        .td-log { font: 13px ui-monospace, monospace; color: #c3c9d8; margin: 0; padding-left: 1.1rem; }
        #ladder tr[data-side] { cursor: pointer; }
        #ladder tr[data-side]:hover td, #ladder tr[data-side]:focus td { background: rgba(255, 181, 71, .07); }
        .td-orders li { margin-bottom: .4rem; }
        .td-order-acts { display: inline-flex; flex-wrap: wrap; gap: 6px; margin-left: .4rem; }
        .td-order-acts form { display: inline-flex; gap: 4px; align-items: center; }
        .td-picker { display: none; margin-bottom: .6rem; }
        @media (max-width: 860px) {
            .td-watch-card { order: 2; }
            .td-picker { display: block; }
        }
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
                    best bid at the bottom, between the bank's two prices. Players trade between the band's two ends. Click a row to fill the
                    ticket. The ticket says, before you trade, what it will do: <b>Buy or sell now</b> takes the best route across players'
                    orders, new shares on offer and the bank.</p>
                <div class="td-grid">
                    {{-- The watch list: your holdings first, each with a health pill; on a phone it comes after the
                         ticket and the book, with a picker above them (#845) --}}
                    <div class="td-card td-watch-card">
                        <h2>{{ $me ? 'Watch list' : 'All companies' }}</h2>
                        <ul class="td-watch" id="watch-list">
                            @foreach($desk['watch'] as $w)
                                <li><a href="{{ url('market/trade?company=' . $w['company']) }}" @if($w['company'] === $desk['company']) aria-current="true" @endif>
                                    <b>{{ $w['name'] }}</b><span class="td-pill td-pill-{{ $w['health'][0] }}" @if($w['note']) title="{{ $w['note'] }}" @endif>{{ $w['health'][1] }}</span>
                                    <small>cash-out {{ number_format($w['buys_at']) }} · 30-day return {{ $w['return_percent'] === null ? '–' : ($w['return_percent'] > 0 ? '+' : '') . $w['return_percent'] . '%' }}</small>
                                    <small>@if($w['held'] > 0)you hold {{ $w['held'] }} @elseif($w['offered'] > 0)new shares on offer @endif</small>
                                </a></li>
                            @endforeach
                        </ul>
                    </div>

                    <div class="td-main">
                        <label class="td-picker"><span class="sr-only">Company</span>
                            <select class="mk-select" onchange="location.href = '{{ url('market/trade') }}?company=' + this.value">
                                @foreach($desk['watch'] as $w)<option value="{{ $w['company'] }}" @if($w['company'] === $desk['company']) selected @endif>{{ $w['name'] }}{{ $w['held'] > 0 ? ' (you hold ' . $w['held'] . ')' : '' }}</option>@endforeach
                            </select></label>
                        {{-- The ladder: the band, the bank's prices and players' orders, dearest first --}}
                        <div class="td-card mb-3" id="ladder">
                            <h2><a href="{{ url('companies/' . $L['company']) }}#player-shares">{{ $L['name'] }}</a>
                                <small class="text-muted">{{ $L['count'] }} shares</small></h2>
                            @php($p = $L['prices'])
                            @php($rows = collect($L['asks'])->map(fn ($o) => $o + ['kind' => 'ask'])->concat(collect($L['bids'])->map(fn ($o) => $o + ['kind' => 'bid'])))
                            <table class="td-ladder">
                                <tr class="td-band"><td>Top of the band</td><td></td><td class="num">{{ number_format($p['high']) }}</td></tr>
                                @foreach($rows->where('kind', 'ask')->where('price', '>', $p['sells_at']) as $o)
                                    <tr class="td-ask" data-side="buy" data-price="{{ $o['price'] }}" title="Buy from this order"><td>Sell · {{ $o['by'] }}@if($o['script']) <small>(script)</small>@endif</td><td class="num">{{ $o['units'] }}</td><td class="num">{{ number_format($o['price']) }}</td></tr>
                                @endforeach
                                @if($p['bank_holds'] > 0)<tr class="td-bank" data-side="buy" title="Buy now"><td>The bank sells ({{ $p['bank_holds'] }} held)</td><td></td><td class="num">{{ number_format($p['sells_at']) }}</td></tr>
                                @else<tr class="td-bank"><td>The bank holds none to sell</td><td></td><td class="num">–</td></tr>@endif
                                @foreach($rows->where('kind', 'ask')->where('price', '<=', $p['sells_at']) as $o)
                                    <tr class="td-ask" data-side="buy" data-price="{{ $o['price'] }}" title="Buy from this order"><td>Sell · {{ $o['by'] }}@if($o['script']) <small>(script)</small>@endif</td><td class="num">{{ $o['units'] }}</td><td class="num">{{ number_format($o['price']) }}</td></tr>
                                @endforeach
                                @php($bestAsk = collect($L['asks'])->min('price'))
                                @php($bestBid = collect($L['bids'])->max('price'))
                                <tr class="td-spread"><td colspan="3">@if($bestAsk !== null && $bestBid !== null)spread {{ number_format($bestAsk - $bestBid) }}@else{{ $bestAsk === null && $bestBid === null ? 'no player orders' : ($bestAsk === null ? 'no player sells' : 'no player bids') }}@endif</td></tr>
                                @foreach($rows->where('kind', 'bid')->where('price', '>=', $p['buys_at']) as $o)
                                    <tr class="td-bid" data-side="sell" data-price="{{ $o['price'] }}" title="Sell into this bid"><td>Buy · {{ $o['by'] }}@if($o['script']) <small>(script)</small>@endif</td><td class="num">{{ $o['units'] }}</td><td class="num">{{ number_format($o['price']) }}</td></tr>
                                @endforeach
                                <tr class="td-bank" data-side="sell" title="Sell now"><td>The bank buys (pays in {{ config('forge.bank_pay_days') }} game days)</td><td></td><td class="num">{{ number_format($p['buys_at']) }}</td></tr>
                                @foreach($rows->where('kind', 'bid')->where('price', '<', $p['buys_at']) as $o)
                                    <tr class="td-bid" data-side="sell" data-price="{{ $o['price'] }}" title="Sell into this bid"><td>Buy · {{ $o['by'] }}@if($o['script']) <small>(script)</small>@endif</td><td class="num">{{ $o['units'] }}</td><td class="num">{{ number_format($o['price']) }}</td></tr>
                                @endforeach
                                <tr class="td-band"><td>Bottom of the band</td><td></td><td class="num">{{ number_format($p['low']) }}</td></tr>
                            </table>
                            @if($L['offering'])
                                <p class="mt-2 mb-0"><small><b>{{ $L['offering']['left'] }} new shares on offer</b> at cash-out or the market price if higher ({{ number_format($L['offering']['price']) }}):
                                    buy them on <a href="{{ url('companies/' . $L['company']) }}#share-offering">its page</a>; the credits go to the company.</small></p>
                            @endif
                        </div>

                        {{-- One order ticket (#845): Buy or sell now by the best route, or name your price --}}
                        <div class="td-card mb-3" id="ticket-card">
                            <h2>Order ticket</h2>
                            @include('partials.share-ticket', ['tk' => ['company' => $L['company'], 'name' => $L['name'], 'p' => $L['prices'],
                                'held' => $me ? (collect($me['shares'])->firstWhere('company_id', $L['company'])['units'] ?? 0) : null,
                                'side' => request('side'), 'units' => request('units')]])
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
                                    <tr><td></td><td class="num">Shares</td><td class="num">Worth</td><td class="num">Cost</td><td class="num">Dividends</td><td class="num">Gain</td><td></td></tr>
                                    @foreach($me['shares'] as $s)
                                        <tr><td><a href="{{ url('market/trade?company=' . $s['company_id']) }}">{{ $s['name'] }}</a></td>
                                            <td class="num">{{ $s['units'] }}</td><td class="num">{{ number_format($s['value']) }}</td>
                                            <td class="num">{{ number_format($s['cost']) }}@if($s['founder_units'] > 0)<br><small class="text-muted">{{ $s['founder_units'] }} from maturing</small>@endif</td>
                                            <td class="num">{{ number_format($s['dividends']) }}</td>
                                            <td class="num {{ $s['gain'] >= 0 ? 'td-gain-up' : 'td-gain-down' }}">{{ $s['gain'] >= 0 ? '+' : '−' }}{{ number_format(abs($s['gain'])) }}@if($s['gain_percent'] !== null)<br><small>{{ $s['gain_percent'] > 0 ? '+' : '' }}{{ $s['gain_percent'] }}%</small>@endif</td>
                                            <td>@if($s['company_id'] === $L['company'])<button type="button" class="st-q" data-sell-all="{{ $s['units'] }}">Sell</button>@else<a class="st-q" href="{{ url('market/trade?company=' . $s['company_id'] . '&side=sell&units=' . $s['units']) }}#ticket">Sell</a>@endif</td></tr>
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
                                {{-- Each with Cancel and a new price (#845); the order keeps its place in time, and its rebate's rest --}}
                                <ul class="mb-0 td-orders" id="your-orders">
                                    @foreach($me['orders'] as $o)
                                        <li>{{ ucfirst($o['side']) }} {{ $o['units'] }} of {{ $o['name'] }} at {{ number_format($o['price']) }}@if(!empty($o['peg'])) <small class="text-muted">(follows the bank)</small>@endif @if($o['out_of_range']) <small class="text-muted">(outside today's band: it waits)</small>@endif
                                            <span class="td-order-acts">
                                                <form method="POST" action="{{ url('share-orders/' . $o['id'] . '/move') }}">{{ csrf_field() }}
                                                    <input class="mk-input" type="number" name="price" value="{{ $o['price'] }}" @if($o['low'] !== null) min="{{ $o['low'] }}" max="{{ $o['high'] }}" @endif
                                                           aria-label="New price for this order" style="width: 6.5em">
                                                    <button class="st-q" type="submit">Move</button></form>
                                                <form method="POST" action="{{ url('share-orders/' . $o['id'] . '/cancel') }}">{{ csrf_field() }}
                                                    <button class="st-q" type="submit">Cancel</button></form>
                                            </span></li>
                                    @endforeach
                                </ul>
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
                {{-- Click a row of the book to fill the ticket (#845) --}}
                <script>
                document.addEventListener('DOMContentLoaded', function () {
                    document.querySelectorAll('#ladder tr[data-side]').forEach((tr) => {
                        tr.tabIndex = 0;
                        const go = () => window.ExTicket && window.ExTicket.fill(tr.dataset.price
                            ? {mode: 'price', side: tr.dataset.side, price: Number(tr.dataset.price)} : {mode: 'now', side: tr.dataset.side});
                        tr.addEventListener('click', go);
                        tr.addEventListener('keydown', (e) => { if (e.key === 'Enter') go(); });
                    });
                    document.querySelectorAll('[data-sell-all]').forEach((b) => b.addEventListener('click', () =>
                        window.ExTicket && window.ExTicket.fill({mode: 'now', side: 'sell', units: Number(b.dataset.sellAll)})));
                });
                </script>
            @endif
        </div>
    </section>
@endsection
