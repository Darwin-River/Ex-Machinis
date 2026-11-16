@extends('layouts.app')
@section('pageTitle', 'Stock exchange')
@section('content')
    {{-- The Stock exchange page (#484): every company's shares, what's for sale, and the latest trades --}}
    <x-page-header :title="'Stock exchange'" />
    <section class="section section-xs section-first text-md-left">
        <div class="container" id="container">
            @if($playerMarket !== null)
                {{-- The new rules (#666): matured companies' shares, traded by players and the bank --}}
                <h3>Players' shares</h3>
                <div style="white-space: normal; margin-bottom: 1em">Each matured company has {{ \App\Services\PlayerShares::COUNT }}
                    shares. The bank buys them at their cash-out value (paid {{ config('forge.bank_pay_days') }} game days later at the
                    lower of that value and the value then) and sells the ones it holds at its selling price; players trade between
                    the range's prices. Trade on a company's page, or through the API.
                    <span class="text-muted">As of {{ \App\Services\DronePage::when($asOf ?? \App\Services\World::gameNow()) }}; worked out again each minute.</span></div>
                @php($stale = \App\Services\Dashboard::staleNote($asOf ?? null))
                @if($stale)<div class="econ-banner econ-warn" id="shares-stale" role="status">{{ $stale }}</div>@endif
                {{-- One row a company (#699): what a share is worth, what it pays, what players paid, and its health --}}
                @include('partials.ex-charts')
                {{-- What Health means, here by the table it describes (#717: it was only in the old rules' section) --}}
                <div style="white-space: normal; margin-bottom: 1em" id="health-meaning"><i>Health</i> is whether the company
                    is still working: <i>at risk</i> when it has no working drone, a drone with no fuel, or a drone that runs dry
                    within {{ \App\Services\CompanyHealth::RISK_DAYS }} game days while it has under
                    {{ number_format(\App\Services\CompanyHealth::RISK_CREDITS) }} credits; <i>stalled</i> when its drones have bought, sold or
                    made nothing for a game day, half or more of them have and it hasn't sold for a game day, or (once it is
                    {{ \App\Services\CompanyHealth::STALLED_GRACE_DAYS }} game days old) it has sold nothing for
                    {{ \App\Services\CompanyHealth::STALLED_SALE_HOURS }} game hours; and <i>failing</i> when its drones keep trying an action
                    that fails (over the last game day). Each is a warning, not a verdict: hover it, or see the company's page, for why.
                    Sorted by yield, highest first.</div>
                <div class="table-custom-responsive">
                    <table class="table-ticket" id="player-share-market">
                        <tr><th>Company</th><th class="text-right">Cash-out a share</th><th>Cash-out change, 30 game days</th><th class="text-right">Dividends a share, 30 days</th>
                            <th class="text-right">Yield, 30 days</th><th class="text-right">Last price between players</th><th class="text-right">Bank sells at</th><th>Health</th></tr>
                        @forelse($playerMarket as $i => $m)
                            <tr><td><a href="{{ url('companies/' . $m['company']) }}#player-shares">{{ $m['name'] }}</a>
                                    @if(!empty($m['holders'][0]['name']))<br><small class="text-muted">mostly {{ $m['holders'][0]['bank'] ? 'the bank' : $m['holders'][0]['name'] }}</small>@endif</td>
                                <td class="text-right">{{ number_format($m['buys_at']) }}</td>
                                <td class="share-spark" data-spark="{{ $i }}">{{ count($m['spark']) ? '' : '–' }}</td>
                                <td class="text-right">{{ number_format($m['dividends30']) }}</td>
                                <td class="text-right">{{ $m['yield_percent'] === null ? '–' : $m['yield_percent'] . '%' }}</td>
                                <td class="text-right">@if($m['last_price'] !== null){{ number_format($m['last_price']) }}
                                        <br><small>{{ $m['last_days_ago'] === 0 ? 'today' : $m['last_days_ago'] . ' game ' . \Illuminate\Support\Str::plural('day', $m['last_days_ago']) . ' ago' }}@if($m['premium_percent'] !== null),
                                        {{ $m['premium_percent'] > 0 ? '+' . $m['premium_percent'] . '% premium' : ($m['premium_percent'] < 0 ? '−' . abs($m['premium_percent']) . '% discount' : 'at cash-out') }}@endif</small>@else no trade between players @endif</td>
                                <td class="text-right">{{ number_format($m['sells_at']) }}</td>
                                <td style="white-space: normal" title="{{ $m['health_note'] }}">@if($m['stalled'])<strong style="color: #ff8a7a">stalled</strong>@elseif($m['at_risk'])<strong>at risk</strong>@elseif($m['failing'])<strong>failing</strong>@else steady @endif</td></tr>
                        @empty
                            <tr><td colspan="8">No company has matured yet.</td></tr>
                        @endforelse
                    </table>
                </div>
                <p class="ex-src">Cash-out is what the bank pays for a share; its selling price adds the share's dividends over 30 game days.
                    Yield is those dividends over the last 30 game days against cash-out. The last price is the last trade between two players
                    (the bank's trades are at its own prices); its premium or discount against today's cash-out is shown only for a trade in the last
                    {{ \App\Http\Controllers\WorldController::PREMIUM_DAYS }} game days.
                    Players trade between {{ (int) round(config('forge.share_range_low') * 100) }}% and {{ (int) round(config('forge.share_range_high') * 100) }}% of the selling price.
                    The 30-day lines share one scale (<span id="share-spark-scale">a percent scale</span>): each is the change in cash-out from
                    its first day, in percent, with that day's level dashed, so a steady share is a flat line whatever its price. Hover a line for the credits.
                    Data: <code>GET /api/v1/player-shares/{id}</code> and <code>GET /api/v1/companies/{id}/history</code> (cash_out_share).</p>
                <script>
                document.addEventListener('DOMContentLoaded', function () {
                    const X = window.ExCharts, M = @json($playerMarket);
                    // One percent scale for every row (#720): each line is the change
                    // from its first day with a value, so a 38-credit share moving by 3
                    // no longer looks as wild as a 1,500-credit one moving by 300
                    const pct = (m) => {
                        const base = (m.spark.find((p) => p.v > 0) || {}).v;
                        return m.spark.map((p) => base ? (p.v / base - 1) * 100 : 0);
                    };
                    const all = M.flatMap((m) => pct(m));
                    const lo = Math.min(0, ...all), hi = Math.max(0, ...all);
                    if (all.length) document.getElementById('share-spark-scale').textContent =
                        `from ${lo < 0 ? '−' : ''}${Math.round(Math.abs(lo))}% to +${Math.round(hi)}%`;
                    const sign = (v) => (v > 0 ? '+' : v < 0 ? '−' : '') + Math.abs(Math.round(v * 10) / 10) + '%';
                    document.querySelectorAll('#player-share-market [data-spark]').forEach((td) => {
                        const m = M[Number(td.dataset.spark)];
                        if (!m.spark.length) return;
                        const p = pct(m);
                        td.replaceChildren(X.spark(p, {line: true, width: 100, height: 24, lo, hi, base: 0,
                            label: m.name + "'s cash-out a share, change from its first day",
                            tip: (v, i) => [m.name, `Game day ${m.spark[i].day}: cash-out ${X.num(m.spark[i].v)} a share`,
                                `${sign(v)} since game day ${m.spark[0].day}`]}));
                    });
                });
                </script>
                {{-- The latest trades between players (#676) --}}
                <h3 class="mt-4">Latest trades between players</h3>
                <div class="table-custom-responsive">
                    <table class="table-ticket" id="player-share-trades">
                        <tr><th>When</th><th>Company</th><th>Shares</th><th>Price</th><th>Seller</th><th>Buyer</th></tr>
                        @forelse($playerTrades as $t)
                            <tr><td>{{ $t['when'] }}</td><td><a href="{{ url('companies/' . $t['company']) }}#player-shares">{{ $t['name'] }}</a></td>
                                <td>{{ number_format($t['units']) }}</td><td>{{ number_format($t['price']) }}</td>
                                <td>{{ $t['seller_name'] }}</td><td>{{ $t['buyer_name'] }}</td></tr>
                        @empty
                            <tr><td colspan="6">No matured company's shares have traded yet.</td></tr>
                        @endforelse
                    </table>
                </div>
                {{-- The old world's exchange, folded away (#717: 2,500 px of it on a phone) --}}
                <details class="mt-4" id="before-new-rules">
                <summary><h3 class="d-inline">Before the new rules</h3></summary>
                <p style="white-space: normal"><i>The old world's stock exchange, kept for its history:
                    under the new rules players trade matured companies' shares above, and companies trade none.</i></p>
            @endif
            @php($archive = $playerMarket !== null)
            <div style="white-space: normal; margin-bottom: 1em">
                @if($archive)
                    {{-- #686: the old world's exchange, in the past tense --}}
                    Under the old rules companies traded shares with each other: each started with 1,000, and could issue
                    more to raise credits. Shares traded at the Earth exchange, from drones' sell and buy orders.
                @else
                Every company starts with 1,000 shares, and more once it issues new ones to raise credits; each share pays
                its holder its part of the company's profit, paid once a game day: its sales after tax less what it paid
                for goods and equipment, after making up any losses (#603).
                Shares trade at the Earth exchange: a drone buys by pulling from a sell order and sells by pushing into
                a buy order, wherever it is. See <a href="{{ url('players-manual') }}#shares-and-the-stock-exchange">Shares
                and the stock exchange</a> in the players' manual. Everything here is live.
                @endif
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
                        <tr><td colspan="12">{{ $archive ? 'None.' : 'No company has traded or offered shares yet.' }}</td></tr>
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
            @if($archive)
                </details>
            @endif
        </div>
    </section>
@endsection
