@extends('layouts.app')
@section('pageTitle', 'Stock exchange')
@push('styles')
    <style>
        /* One colour for each health state (#758: "stalled" was red, "failing" plain white) */
        .health-bad { color: #ff8a7a; }
        .health-warn { color: #f0b429; }
        .health-ok { color: #5ad19a; }
        /* On a phone each company is a card, not a row of eight columns to scroll sideways (#744) */
        @media (max-width: 760px) {
            .share-cards table, .share-cards tbody, .share-cards tr, .share-cards td { display: block; width: 100%; }
            .share-cards tr:first-child { display: none; }
            .share-cards tr { border: 1px solid #263052; border-radius: 10px; padding: 8px 12px; margin-bottom: 10px; background: #111729; }
            .share-cards td { display: flex; justify-content: space-between; gap: 12px; border: 0 !important; padding: 3px 0 !important;
                text-align: right !important; white-space: normal; }
            .share-cards td::before { content: attr(data-label); color: #9aa3b8; text-align: left; flex: 0 0 auto; }
            .share-cards td:first-child { display: block; text-align: left !important; font-size: 1.05em; padding-bottom: 6px !important; }
            .share-cards td:first-child::before { content: none; }
            .share-cards td[colspan] { display: block; text-align: left !important; }
            .share-cards td[colspan]::before { content: none; }
            .share-cards .share-spark svg { max-width: 50%; }
            .share-cards td[data-label="Health"] { display: block; text-align: left !important; }
            .share-cards td[data-label="Health"]::before { margin-right: .5em; }
            /* The board's cards in three short columns, and (here and in the trades) the first FIRST_ON_PHONE rows until asked (#884: 47 cards of
               seven rows made the page 21,000 pixels tall on a phone) */
            #player-share-market tr { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 2px 12px; }
            #player-share-market tr:first-child { display: none; }
            #player-share-market td { display: block; text-align: left !important; }
            #player-share-market td::before { content: attr(data-short); display: block; font-size: .78em; line-height: 1.3; }
            #player-share-market td:first-child, #player-share-market td[colspan] { grid-column: 1 / -1; }
            #player-share-market td.share-spark, #player-share-market td[data-label="Health"] { grid-column: span 2; }
            #player-share-market td.share-spark svg { max-width: 100%; }
            #player-share-market td[data-label="Health"]::before { margin-right: 0; }
            .share-cards table:not(.show-all) tr.share-more { display: none !important; }
            .share-show-all { display: block; width: 100%; margin-bottom: 1em; }
        }
        @media (min-width: 761px) { .share-show-all { display: none !important; } }
    </style>
@endpush
@section('content')
    {{-- The Stock exchange page (#484): every company's shares, what's for sale, and the latest trades --}}
    <x-page-header :title="'Stock exchange'">
        <li><a href="{{ url('market') }}">Market</a></li>
        <li class="active">Stock exchange</li>
    </x-page-header>
    <section class="section section-xs section-first text-md-left">
        <div class="container" id="container">
            @include('partials.market-tabs')
            @if($playerMarket !== null)
                {{-- The new rules (#666): matured companies' shares, traded by players and the bank --}}
                <h3>Players' shares</h3>
                <div style="white-space: normal; margin-bottom: 1em">Each matured company starts with {{ \App\Services\PlayerShares::COUNT }}
                    shares, and its owner may offer {{ \App\Services\PlayerShares::OFFER_UNITS }} new ones at a time at its cash-out value, to
                    fund its growth, up to {{ \App\Services\PlayerShares::MAX_COUNT }}. The bank buys them at their cash-out value (paid {{ config('forge.bank_pay_days') }} game days later at the
                    lower of that value and the value then) and sells the ones it holds at its selling price; players trade between
                    the range's prices. Trade on the <a href="{{ url('market/trade') }}">Trading desk</a>, on a company's page, or through the API.
                    <span class="text-muted">As of {{ \App\Services\DronePage::when($asOf ?? \App\Services\World::gameNow()) }} ({{ \App\Services\Dashboard::agoText($asOf ?? null) }}); worked out again every few real minutes.</span></div>
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
                    Sorted by return over the last 30 game days (the change in cash-out plus dividends), highest first; a company
                    counting down to dissolving goes last.</div>
                <div class="table-custom-responsive share-cards">
                    <table class="table-ticket" id="player-share-market">
                        <tr><th>Company</th><th class="text-right">Cash-out a share</th><th>Cash-out change, 30 game days</th><th class="text-right">Dividends a share, 30 days</th>
                            <th class="text-right">Yield, 30 days</th><th class="text-right" title="Cash-out change plus dividends a share, over 30 game days">Return, 30 days</th><th class="text-right">Bank sells at</th><th>Health</th></tr>
                        @forelse($playerMarket as $i => $m)
                            <tr @if($loop->index >= \App\Http\Controllers\WorldController::FIRST_ON_PHONE) class="share-more" @endif><td><a href="{{ url('companies/' . $m['company']) }}#player-shares">{{ $m['name'] }}</a>
                                    @if(!empty($m['holders'][0]['name']))<br><small class="text-muted">mostly {{ $m['holders'][0]['bank'] ? 'the bank' : $m['holders'][0]['name'] }}</small>@endif
                                    {{-- Its share count once it has sold new ones, and an open offering (#780) --}}
                                    @if($m['count'] !== \App\Services\PlayerShares::COUNT)<br><small class="text-muted">{{ $m['count'] }} shares</small>@endif
                                    @if($m['offered'] > 0)<br><small class="health-ok">{{ $m['offered'] }} new {{ \Illuminate\Support\Str::plural('share', $m['offered']) }} on offer at cash-out or the market price</small>@endif
                                    {{-- The last trade between players, when recent (#744: its own column was mostly empty) --}}
                                    @if($m['last_price'] !== null && $m['last_days_ago'] <= 30)<br><small class="text-muted">players traded at {{ number_format($m['last_price']) }} {{ $m['last_days_ago'] === 0 ? 'today' : $m['last_days_ago'] . ' game ' . \Illuminate\Support\Str::plural('day', $m['last_days_ago']) . ' ago' }}@if($m['premium_percent'] !== null) ({{ $m['premium_percent'] > 0 ? '+' . $m['premium_percent'] . '% premium' : ($m['premium_percent'] < 0 ? '−' . abs($m['premium_percent']) . '% discount' : 'at cash-out') }})@endif</small>@endif</td>
                                <td class="text-right" data-label="Cash-out a share" data-short="Cash-out">{{ number_format($m['buys_at']) }}</td>
                                <td class="share-spark" data-label="Cash-out, 30 game days" data-short="Cash-out, 30 days" data-spark="{{ $i }}">{{ count($m['spark']) ? '' : '–' }}</td>
                                <td class="text-right" data-label="Dividends a share, 30 days" data-short="Dividends, 30 days">{{ number_format($m['dividends30']) }}</td>
                                <td class="text-right" data-label="Yield, 30 days" data-short="Yield, 30 days">{{ $m['yield_percent'] === null ? '–' : $m['yield_percent'] . '%' }}</td>
                                <td class="text-right" data-label="Return, 30 days" data-short="Return, 30 days">{{ ($m['return_percent'] ?? null) === null ? '–' : (($m['return_percent'] > 0 ? '+' : '') . $m['return_percent'] . '%') }}</td>
                                <td class="text-right" data-label="Bank sells at" data-short="Bank sells at">{{ number_format($m['sells_at']) }}</td>
                                {{-- The worst first, in words anyone can read, not only on hover (#758) --}}
                                <td style="white-space: normal" data-label="Health" data-short="Health">@if(($m['dissolves_in'] ?? null) !== null)<strong class="health-bad">dissolves in {{ $m['dissolves_in'] }} game days</strong>@elseif(($m['winds_up_in'] ?? null) !== null)<strong class="health-bad">winds up in {{ $m['winds_up_in'] }} game days</strong>@elseif($m['failing'])<strong class="health-bad">failing</strong>@elseif($m['at_risk'])<strong class="health-warn">at risk</strong>@elseif($m['stalled'])<strong class="health-warn">stalled</strong>@else <span class="health-ok">steady</span> @endif
                                    @if($m['health_note'])<br><small class="text-muted">{{ $m['health_note'] }}</small>@endif</td></tr>
                        @empty
                            <tr><td colspan="8">No company has matured yet.</td></tr>
                        @endforelse
                    </table>
                </div>
                @if(count($playerMarket) > \App\Http\Controllers\WorldController::FIRST_ON_PHONE)
                    <button type="button" class="button button-secondary share-show-all"
                        onclick="document.getElementById('player-share-market').classList.add('show-all'); this.remove()">Show all {{ count($playerMarket) }} companies</button>
                @endif
                <p class="ex-src">Cash-out is what the bank pays for a share; its selling price adds the share's dividends over the last {{ \App\Services\PlayerShares::premiumDays() }} game days.
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
                            tip: (v, i) => [m.name, `${X.dayDate(m.spark[i].day)}: cash-out ${X.num(m.spark[i].v)} a share`,
                                `${sign(v)} since game day ${m.spark[0].day}`]}));
                    });
                });
                </script>
                {{-- The latest share trades, players' and the bank's (#676; #758: titled "between players", most were with the bank) --}}
                <h3 class="mt-4">Latest share trades</h3>
                <div class="table-custom-responsive share-cards">
                    <table class="table-ticket" id="player-share-trades">
                        <tr><th>When</th><th>Company</th><th>Shares</th><th>Price</th><th>Seller</th><th>Buyer</th></tr>
                        @forelse($playerTrades as $t)
                            <tr @if($loop->index >= \App\Http\Controllers\WorldController::FIRST_ON_PHONE) class="share-more" @endif><td>{{ $t['when'] }}<br><small class="text-muted">{{ \App\Services\DronePage::ago($t['game_time']) }}</small></td><td data-label="Company"><a href="{{ url('companies/' . $t['company']) }}#player-shares">{{ $t['name'] }}</a></td>
                                <td data-label="Shares">{{ number_format($t['units']) }}</td><td data-label="Price">{{ number_format($t['price']) }}</td>
                                <td data-label="Seller">@if($t['seller_is_bank'])<i>the bank</i>@else{{ $t['seller_name'] }}@endif</td><td data-label="Buyer">@if($t['buyer_is_bank'])<i>the bank</i>@else{{ $t['buyer_name'] }}@endif</td></tr>
                        @empty
                            <tr><td colspan="6">No matured company's shares have traded yet.</td></tr>
                        @endforelse
                    </table>
                </div>
                @if(count($playerTrades) > \App\Http\Controllers\WorldController::FIRST_ON_PHONE)
                    <button type="button" class="button button-secondary share-show-all"
                        onclick="document.getElementById('player-share-trades').classList.add('show-all'); this.remove()">Show all {{ count($playerTrades) }} trades</button>
                @endif
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
                for goods and equipment, after making up any losses.
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
                new shares take if it fills.</div>
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
