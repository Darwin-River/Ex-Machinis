@extends('layouts.app')
@section('pageTitle', 'Stock exchange')
@section('content')
    {{-- The Stock exchange page (#484): every company's shares, what's for sale, and the latest trades --}}
    <section class="section section-xs section-first bg-default text-md-left">
        <div class="container" id="container">
            <h2>Stock exchange</h2>
            <div style="white-space: normal; margin-bottom: 1em">
                Every company starts with 1,000 shares, and more once it issues new ones to raise credits; each share pays
                its holder its part of the company's sales income after tax.
                Shares trade at the Earth exchange: a drone buys by pulling from a sell order and sells by pushing into
                a buy order, wherever it is. See <a href="{{ url('players-manual') }}#shares-and-the-stock-exchange">Shares
                and the stock exchange</a> in the players' manual. Everything here is live.
            </div>

            <h3>Offerings of new shares</h3>
            <div style="white-space: normal; margin-bottom: 1em">Companies raising credits by issuing new shares. Investors
                commit and pay now; when every share is committed the shares are issued and the company paid, and if the
                deadline passes first everyone is refunded. <i>A share a day</i> is the company's sales after tax a day
                (7-day average) divided by the shares it will have once the offering fills.</div>
            <div class="table-custom-responsive">
                <table class="table-ticket" id="share-offerings">
                    <tr><th>Company</th><th>New shares</th><th>Price</th><th>Raising</th><th>Committed</th><th>Hours left</th>
                        <th>A share a day</th></tr>
                    @forelse($exchange['offerings'] as $o)
                        <tr>
                            <td><a href="{{ url('companies/' . $o['company']) }}">{{ $o['name'] }}</a></td>
                            <td>{{ number_format($o['units']) }} <small>(has {{ number_format($o['count']) }})</small></td>
                            <td>{{ number_format($o['price']) }}</td><td>{{ number_format($o['raise']) }}</td>
                            <td>{{ number_format($o['committed']) }} of {{ number_format($o['units']) }}</td>
                            <td>{{ $o['hours'] }}</td><td>{{ $o['a_day_after'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7">No open offerings.</td></tr>
                    @endforelse
                </table>
            </div>

            <h3 class="mt-4">Companies</h3>
            <div style="white-space: normal; margin-bottom: 1em">Companies whose shares have traded, are on offer, or are
                held by others, busiest first. <i>Sales a day</i> is sales after tax averaged over the last 7 game days (sales come in lumps); <i>A share a day</i> is that divided by its shares;
                <i>Pays back</i> is how many days of that the last price comes to.</div>
            <div class="table-custom-responsive">
                <table class="table-ticket" id="share-companies">
                    <tr><th>Company</th><th>Shares</th><th>Last price</th><th>Sales a day</th><th>A share a day</th><th>Pays back</th>
                        <th>Held by others</th><th>Dividends paid</th></tr>
                    @forelse($exchange['companies'] as $c)
                        <tr>
                            <td><a href="{{ url('companies/' . $c['company']) }}">{{ $c['name'] }}</a></td>
                            <td>{{ number_format($c['count']) }}</td>
                            <td>{{ $c['price'] !== null ? number_format($c['price']) : 'never traded' }}</td>
                            <td>{{ number_format($c['sales']) }}</td>
                            <td>{{ $c['a_day'] }}</td>
                            <td>{{ $c['payback'] !== null ? $c['payback'] . ' days' : '–' }}</td>
                            <td>{{ number_format($c['held_by_others']) }}</td>
                            <td>{{ number_format($c['dividends_paid']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8">No company has traded or offered shares yet.</td></tr>
                    @endforelse
                </table>
            </div>

            <h3 class="mt-4">For sale now</h3>
            <div style="white-space: normal; margin-bottom: 1em">Sell orders that can sell now, cheapest first.
                <i>Days</i> is how many days of a share's income the price asks.</div>
            <div class="table-custom-responsive">
                <table class="table-ticket" id="shares-for-sale">
                    <tr><th>Shares of</th><th>Price</th><th>Units</th><th>Days</th><th>Seller</th></tr>
                    @forelse($exchange['for_sale'] as $o)
                        <tr>
                            <td><a href="{{ url('companies/' . $o['company']) }}">{{ $o['name'] }}</a></td>
                            <td>{{ number_format($o['price']) }}</td><td>{{ number_format($o['units']) }}</td>
                            <td>{{ $o['days'] ?? '–' }}</td>
                            <td><a href="{{ url('companies/' . $o['by']) }}">{{ $o['by_name'] }}</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="5">No shares for sale.</td></tr>
                    @endforelse
                </table>
            </div>

            <h3 class="mt-4">Buy orders</h3>
            <div class="table-custom-responsive">
                <table class="table-ticket" id="shares-wanted">
                    <tr><th>Shares of</th><th>Price</th><th>Units wanted</th><th>Days</th><th>Buyer</th></tr>
                    @forelse($exchange['buying'] as $o)
                        <tr>
                            <td><a href="{{ url('companies/' . $o['company']) }}">{{ $o['name'] }}</a></td>
                            <td>{{ number_format($o['price']) }}</td><td>{{ number_format($o['units']) }}</td>
                            <td>{{ $o['days'] ?? '–' }}</td>
                            <td><a href="{{ url('companies/' . $o['by']) }}">{{ $o['by_name'] }}</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="5">No buy orders.</td></tr>
                    @endforelse
                </table>
            </div>

            <h3 class="mt-4">Recent trades</h3>
            <div class="table-custom-responsive">
                <table class="table-ticket" id="share-trades">
                    <tr><th>When</th><th>Shares of</th><th>Units</th><th>Price</th><th>Seller</th><th>Buyer</th></tr>
                    @forelse($exchange['trades'] as $t)
                        <tr>
                            <td>{{ $t['when'] }}</td>
                            <td><a href="{{ url('companies/' . $t['company']) }}">{{ $t['name'] }}</a></td>
                            <td>{{ number_format($t['units']) }}</td><td>{{ number_format($t['price']) }}</td>
                            <td><a href="{{ url('companies/' . $t['seller']) }}">{{ $t['seller_name'] }}</a></td>
                            <td><a href="{{ url('companies/' . $t['buyer']) }}">{{ $t['buyer_name'] }}</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6">No share trades yet.</td></tr>
                    @endforelse
                </table>
            </div>
        </div>
    </section>
@endsection
