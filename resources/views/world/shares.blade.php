@extends('layouts.app')
@section('pageTitle', 'Stock exchange')
@section('content')
    {{-- The Stock exchange page (#484): every company's shares, what's for sale, and the latest trades --}}
    <x-page-header :title="'Stock exchange'" />
    <section class="section section-xs section-first text-md-left">
        <div class="container" id="container">
            <div style="white-space: normal; margin-bottom: 1em">
                Every company starts with 1,000 shares, and more once it issues new ones to raise credits; each share pays
                its holder its part of the company's profit, paid once a game day: its sales after tax less what it paid
                for goods and equipment, after making up any losses (#603).
                Shares trade at the Earth exchange: a drone buys by pulling from a sell order and sells by pushing into
                a buy order, wherever it is. See <a href="{{ url('players-manual') }}#shares-and-the-stock-exchange">Shares
                and the stock exchange</a> in the players' manual. Everything here is live.
            </div>

            <h3>Offerings of new shares</h3>
            <div style="white-space: normal; margin-bottom: 1em">Companies raising credits by issuing new shares. Investors
                commit and pay now. Once every share is committed the shares are issued and the company paid. At the
                deadline, if at least the offering's minimum is committed, those shares are issued; if not, everyone is
                refunded. <i>A share a day</i> is the company's profit a day (30-day average) divided by the
                shares it will have if the offering fills. <i>Income sold</i> is the part of the company's future profit the
                new shares take if it fills (#572).</div>
            <div class="table-custom-responsive">
                <table class="table-ticket" id="share-offerings">
                    <tr><th>Company</th><th>New shares</th><th>At least</th><th>Price</th><th>Raising up to</th><th>Committed</th><th>Hours left</th>
                        <th>A share a day</th><th>Income sold</th></tr>
                    @forelse($exchange['offerings'] as $o)
                        <tr>
                            <td><a href="{{ url('companies/' . $o['company']) }}">{{ $o['name'] }}</a></td>
                            <td>{{ number_format($o['units']) }} <small>(has {{ number_format($o['count']) }})</small></td>
                            <td>{{ number_format($o['min']) }}</td><td>{{ number_format($o['price']) }}</td><td>{{ number_format($o['raise']) }}</td>
                            <td>{{ number_format($o['committed']) }} of {{ number_format($o['units']) }}</td>
                            <td>{{ $o['hours'] }}</td><td>{{ $o['a_day_after'] }}</td><td>{{ $o['income_percent'] }}%</td>
                        </tr>
                    @empty
                        <tr><td colspan="9">No open offerings.</td></tr>
                    @endforelse
                </table>
            </div>

            <h3 class="mt-4">Companies</h3>
            <div style="white-space: normal; margin-bottom: 1em">Companies whose shares have traded, are on offer, or are
                held by others, busiest first. <i>Sales a day</i> is sales after tax, averaged over the last 7 game days. <i>Profit a day</i> is sales after tax less what it paid for goods and equipment, averaged over the last 30 game days, since a company that buys a hull or a load makes it in lumps. Dividends are paid from profit, but only once earlier losses are made up: <i>Losses to make up</i> is how much more profit the company must make before it pays any. <i>A share a day</i> is profit divided by its shares, and
                <i>Pays back</i> is how many days of profit it takes to make up those losses and then earn back the last price. <i>Value</i> is what a share counts for in net
                wealth: the average price of its trades over the last 3 game days, weighted by shares, or the last price if
                none traded then, so one odd trade doesn't move every holder's wealth. <i>Health</i> is whether it can keep its
                drones flying: the game days until its first drone runs dry at the last day's fall in fuel, and
                <i>at risk</i> when that is 3 days or fewer and it has under 500 credits, or no drone works (a warning, not a
                verdict: see the company's page for why), and <i>stalled</i> when none of its drones has bought, sold or
                made anything for a game day.</div>
            <div class="table-custom-responsive">
                <table class="table-ticket" id="share-companies">
                    <tr><th>Company</th><th>Shares</th><th>Last price</th><th>Value</th><th>Sales a day</th><th>Profit a day</th><th>Losses to make up</th><th>A share a day</th><th>Pays back</th>
                        <th>Held by others</th><th>Dividends paid</th><th>Health</th></tr>
                    @forelse($exchange['companies'] as $c)
                        <tr>
                            <td><a href="{{ url('companies/' . $c['company']) }}">{{ $c['name'] }}</a></td>
                            <td>{{ number_format($c['count']) }}</td>
                            <td>{{ $c['price'] !== null ? number_format($c['price']) : 'never traded' }}</td>
                            <td>{{ $c['value_price'] !== null ? number_format($c['value_price']) : '–' }}</td>
                            <td>{{ number_format($c['sales']) }}</td>
                            <td>{{ number_format($c['profit30']) }}</td>
                            <td>{{ $c['carried_loss'] > 0 ? number_format($c['carried_loss']) : '–' }}</td>
                            <td>{{ $c['a_day'] }}</td>
                            <td>{{ $c['payback'] !== null ? $c['payback'] . ' days' : '–' }}</td>
                            <td>{{ number_format($c['held_by_others']) }}</td>
                            <td>{{ number_format($c['dividends_paid']) }}</td>
                            @php($h = $c['health'])
                            @php($days = $h && $h['days'] >= 0 ? $h['days'] . ' ' . \Illuminate\Support\Str::plural('day', $h['days']) : null)
                            <td>
                                @if(!$h)
                                    –
                                @elseif($h['at_risk'])
                                    <strong>at risk</strong>{{ $days ? ', ' . $days : '' }}
                                @else
                                    {{ $days ? $days . ' of fuel' : 'steady' }}
                                @endif
                                @if($h && $h['stalled'])
                                    , <strong>stalled</strong>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="11">No company has traded or offered shares yet.</td></tr>
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
                            <td>{{ $t['when'] }} ({{ $t['days_ago'] === 0 ? 'today' : $t['days_ago'] . ' game ' . \Illuminate\Support\Str::plural('day', $t['days_ago']) . ' ago' }})</td>
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
