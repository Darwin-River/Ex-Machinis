@extends('layouts.app')
@section('pageTitle', 'Economy dashboard')
@push('styles')
    <style>
        /* The economy dashboard (#698): one column on a phone, no sideways scroll */
        .dash-grid { display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 14px; }
        .dash-grid > .mh-card { margin: 0; min-width: 0; }
        .dash-12 { grid-column: span 12; } .dash-8 { grid-column: span 8; } .dash-6 { grid-column: span 6; } .dash-4 { grid-column: span 4; }
        @media (max-width: 900px) { .dash-8, .dash-6, .dash-4 { grid-column: span 12; } }
        .dash-q { color: var(--mh-muted); font-size: 14px; margin: .25em 0 .6em; white-space: normal; }
        .dash-spreads { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 14px; }
        .dash-news { list-style: none; padding: 0; margin: 0; }
        .dash-news li { padding: 6px 0; border-bottom: 1px solid var(--mh-grid); font-size: 14px; white-space: normal; }
        .dash-pill { display: inline-block; font-size: 12px; font-weight: 600; padding: 1px 8px; border-radius: 99px; margin-right: 6px;
              background: rgba(57, 135, 229, 0.18); color: #9cc3f5; }
        .dash-pill.dash-neg { background: rgba(230, 103, 103, 0.18); color: #ffaaa0; }
        #dash-companies { width: 100%; border-collapse: collapse; font-size: 14px; }
        #dash-companies th { color: var(--mh-muted); font-weight: 600; font-size: 13px; text-align: right; padding: 6px; border-bottom: 1px solid var(--mh-grid); }
        #dash-companies td { text-align: right; padding: 6px; border-bottom: 1px solid var(--mh-grid); font-variant-numeric: tabular-nums; }
        #dash-companies th:first-child, #dash-companies td:first-child { text-align: left; }
        /* A phone: each company is a small card of label and value */
        @media (max-width: 700px) {
            #dash-companies thead { display: none; }
            #dash-companies tr { display: grid; grid-template-columns: 1fr 1fr; gap: 2px 12px; padding: 8px 0; border-bottom: 1px solid var(--mh-grid); }
            #dash-companies td { border: 0; padding: 2px 0; text-align: left; }
            #dash-companies td:first-child { grid-column: span 2; font-weight: 600; }
            #dash-companies td[data-label]::before { content: attr(data-label) ": "; color: var(--mh-muted); font-size: 12px; }
            #dash-companies td.dash-spark { grid-column: span 2; }
        }
    </style>
@endpush
@section('content')
    <x-page-header :title="'Economy dashboard'" />
    <section class="section section-xs section-first text-md-left">
        <div class="container" id="container">
            <p class="mh-intro">Is the market busy, are the companies making money, what is traded where, and who is
                winning. Worked out from the same data as the <a href="https://github.com/Darwin-River/Ex-Machinis/blob/main/documents/api.md">API</a>
                (<code>GET /api/v1/dashboard</code>) every few real minutes. As of {{ $dash['as_of'] }} ({{ \App\Services\Dashboard::agoText($dash['as_of_t'] ?? null) }}).</p>

            @include('partials.dashboard-strip', ['dash' => $dash, 'dashLink' => false])
            @include('partials.ex-charts')

            <div class="dash-grid mh">
                <div class="mh-card dash-12">
                    <h3>Is the market busy?</h3>
                    <p class="dash-q">Credits changing hands each game day over the last {{ \App\Services\Dashboard::DAYS }}, by place. Hover over a bar, or tap it, for the day; today's bar, faded and hatched, is the day so far.</p>
                    <div class="ex-legend" id="dash-trading-legend"></div>
                    <div class="ex-chart" id="dash-trading" aria-label="Credits traded each game day, by place"></div>
                    <p class="ex-src">Data: <code>{{ $dash['sources']['trading'] }}</code></p>
                    @include('partials.event-marks-key', ['company' => false])
                </div>
                <div class="mh-card dash-6">
                    <h3>Are the matured companies making money?</h3>
                    <p class="dash-q">Their combined profit each game day: sales after tax less purchases, from the day each matured, for the
                        companies in the table below (its 30 days add up to the last 30 bars). Blue a gain, red a loss; today's bar, hatched, is the day so far.</p>
                    <div class="ex-chart" id="dash-profit" aria-label="Matured companies' combined profit each game day"></div>
                    <p class="ex-src">Data: <code>{{ $dash['sources']['matured_profit'] }}</code></p>
                </div>
                <div class="mh-card dash-6">
                    <h3>What is being traded?</h3>
                    <p class="dash-q">Credits traded in each good over the last {{ \App\Services\Dashboard::GOODS_DAYS }} game days, the top 8.</p>
                    <div class="ex-chart" id="dash-goods" aria-label="Credits traded by good"></div>
                    <p class="ex-src">Data: <code>{{ $dash['sources']['goods'] }}</code></p>
                </div>
                @php($hasNews = count($dash['news']) > 0)
                <div @class(['mh-card', 'dash-8' => $hasNews, 'dash-12' => !$hasNews])>
                    <h3>Where is each good cheapest, and who pays most?</h3>
                    <p class="dash-q">The cheapest seller (filled dot) and the highest buyer (ring) at each place for the main goods,
                        from the orders of drones that are at that place now (not flying).
                        The gap between them is a trader's margin; an amber bar is a crossed book, where a buyer bids more than a seller asks.</p>
                    <div class="ex-legend" id="dash-spreads-legend"></div>
                    <div class="dash-spreads" id="dash-spreads"></div>
                    <p class="ex-src">Data: <code>{{ $dash['sources']['spreads'] }}</code></p>
                    @unless($hasNews)
                        {{-- #716: an empty panel of its own; one line here instead --}}
                        <p class="dash-q" id="dash-news">What is moving prices? Nothing now: the House has no booms, slumps, shortages or gluts under way.</p>
                    @endunless
                </div>
                @if($hasNews)
                <div class="mh-card dash-4">
                    <h3>What is moving prices?</h3>
                    {{-- Coloured by what it does to the House's prices (#716: "glut red, shortage blue" read backwards):
                         the arrow and colour say up or down, the word says why --}}
                    <p class="dash-q">The House's booms, slumps, shortages and gluts under way, strongest first.
                        <span class="dash-pill">&#9650; up</span> the House's price goes up; <span class="dash-pill dash-neg">&#9660; down</span> it goes down.
                        A boom or slump moves what it bids (pays) for goods; a shortage or glut moves what it asks (charges): a glut brings its prices down.</p>
                    <ul class="dash-news" id="dash-news">
                        @foreach($dash['news'] as $n)
                            <li title="{{ $n['text'] }}"><span @class(['dash-pill', 'dash-neg' => $n['percent_now'] < 0])>{{ $n['percent_now'] < 0 ? "\u{25BC}" : "\u{25B2}" }} {{ ucfirst($n['kind']) }}</span>
                                {{ $n['place'] }}: {{ $n['tier'] }}, {{ $n['side'] === 'demand' ? 'bids' : 'asks' }}
                                {{ $n['percent_now'] > 0 ? '+' : '−' }}{{ abs($n['percent_now']) }}% until day {{ $n['to_day'] }}</li>
                        @endforeach
                    </ul>
                    <p class="ex-src">Data: <code>{{ $dash['sources']['news'] }}</code></p>
                </div>
                @endif
                <div class="mh-card dash-12">
                    <h3>Which companies are worth buying?</h3>
                    <p class="dash-q">Every matured company: what a share fetches from the bank (its cash-out value), its working drones,
                        what it made and paid out a share over 30 game days, the yield (those dividends against the cash-out value) and its daily profit.
                        The daily-profit bars of every company share one scale (<span id="dash-spark-scale">the same credits a pixel</span>), so a
                        small company's wobble looks small next to a big one's: hover a bar for the day's figure.</p>
                    @if(count($dash['companies']))
                        <table id="dash-companies">
                            <thead><tr><th>Company</th><th>Cash-out a share</th><th>Drones</th><th>Profit, 30 days</th>
                                <th>Dividends a share, 30 days</th><th>Yield, 30 days</th><th>Daily profit, 30 days</th></tr></thead>
                            <tbody>
                            @foreach($dash['companies'] as $i => $c)
                                <tr><td><a href="{{ url('companies/' . $c['company']) }}">{{ $c['name'] }}</a>@if($c['owner'])
                                        <small class="text-muted">({{ $c['owner'] }})</small>@endif</td>
                                    <td data-label="Cash-out a share">{{ number_format($c['cash_out']) }}</td>
                                    <td data-label="Drones">{{ $c['drones'] }}</td>
                                    <td data-label="Profit, 30 days" @class(['econ-down' => $c['profit30'] < 0])>{{ number_format($c['profit30']) }}</td>
                                    <td data-label="Dividends a share">{{ number_format($c['dividends30']) }}</td>
                                    <td data-label="Yield">{{ $c['yield_percent'] === null ? '–' : $c['yield_percent'] . '%' }}</td>
                                    <td class="dash-spark" data-spark="{{ $i }}"></td></tr>
                            @endforeach
                            </tbody>
                        </table>
                    @else
                        <p id="dash-companies-none">No company has matured yet.</p>
                    @endif
                    <p class="ex-src">Data: <code>{{ $dash['sources']['companies'] }}</code></p>
                </div>
                <div class="mh-card dash-6">
                    <h3>Where are the drones, and where do they die?</h3>
                    <p class="dash-q">Players' working drones and wrecks at each place.</p>
                    <div class="ex-legend" id="dash-drones-legend"></div>
                    <div class="ex-chart" id="dash-drones" aria-label="Working drones and wrecks by place"></div>
                    <p class="ex-src">Data: <code>{{ $dash['sources']['drones_by_place'] }}</code></p>
                </div>
                <div class="mh-card dash-6">
                    <h3>Who is winning?</h3>
                    <p class="dash-q">Each player's wealth, as the <a href="{{ url('players') }}">Players</a> board counts it: shares at cash-out value, credits, and
                        what the bank still owes them for shares it bought (a class group's pupils have a board of their own).</p>
                    <div class="ex-legend" id="dash-players-legend"></div>
                    <div class="ex-chart" id="dash-players" aria-label="Players' wealth"></div>
                    <p class="ex-src">Data: <code>{{ $dash['sources']['players'] }}</code></p>
                </div>
            </div>
        </div>
    </section>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const X = window.ExCharts, D = @json($dash), M = @json($marks), $ = (id) => document.getElementById(id), C = X.COLOURS;

        // Credits traded by place: each place keeps its colour; "Elsewhere" is grey
        // One colour for each place, the same on every page (#760: they went by rank, and swapped between reloads)
        const PLACES = @json(\App\Services\World::PLACE_COLOURS);
        const placeColour = D.trading.places.map((p, i) => p === 'Elsewhere' ? X.GREY : (PLACES[p] || C[(i + 5) % C.length]));
        X.legend($('dash-trading-legend'), D.trading.places.map((p, i) => ({name: p, colour: placeColour[i]})));
        const trading = () => X.stacked($('dash-trading'), D.trading.days.map((d) => ({day: d.day, values: D.trading.places.map((p) => d.by_place[p] || 0)})),
            D.trading.places.map((p, i) => ({name: p, colour: placeColour[i]})),
            {H: 240, unit: 'cr', unitWord: 'credits', empty: 'No trades in the last 60 game days.', partial: D.today, marks: M,
             dayNote: (d) => d ? ` · ${X.num(d.trades)} trades` : ''});

        const profit = () => X.diverging($('dash-profit'), D.matured_profit.map((r) => ({day: r.day, up: Math.max(0, r.net), down: Math.max(0, -r.net), r})),
            {H: 220, unit: 'cr', downColour: X.NEG, empty: 'No matured company has traded yet.', partial: D.today, marks: M,
             tip: (r, day) => r ? ['Game day ' + day, `Profit: ${X.num(r.r.net)}`, `Sales after tax: ${X.num(r.r.sales)}`, `Purchases: ${X.num(r.r.purchases)}`] : ['Game day ' + day]});

        const goods = () => X.hbars($('dash-goods'), D.goods.map((g) => ({label: g.name, segs: [g.credits]})), [{name: 'Credits', colour: C[0]}],
            {empty: 'Nothing traded in the last 14 game days.'});

        X.legend($('dash-spreads-legend'), [{name: 'cheapest seller', colour: C[1], kind: 'dot'}, {name: 'highest buyer', colour: C[2], kind: 'ring'},
            {name: 'crossed book', colour: C[3]}]);
        const spreads = () => {
            const box = $('dash-spreads'); box.replaceChildren();
            if (!D.spreads.length) { X.empty(box, 'No open orders.'); return; }
            // Every box first, so each is drawn at its own column's width
            const boxes = D.spreads.map(() => { const d = document.createElement('div'); d.className = 'ex-chart'; box.appendChild(d); return d; });
            D.spreads.forEach((g, i) => {
                const d = boxes[i];
                X.dots(d, g.name, g.places.map((p) => ({label: p.place, a: p.ask, b: p.bid})),
                    {aName: 'Cheapest seller asks', bName: 'Highest buyer bids', aColour: C[1], bColour: C[2]});
            });
        };

        // One scale for every row (#720): each row fitted to its own least and
        // most made a company wobbling by a few credits look as wild as a big one
        const all = D.companies.flatMap((c) => c.daily_profit), sLo = Math.min(0, ...all), sHi = Math.max(0, ...all);
        if ($('dash-spark-scale') && all.length) $('dash-spark-scale').textContent = `from ${X.num(sLo)} to ${X.num(sHi)} credits a game day`;
        document.querySelectorAll('#dash-companies [data-spark]').forEach((td) => {
            const c = D.companies[Number(td.dataset.spark)], first = D.today - c.daily_profit.length + 1;
            td.appendChild(X.spark(c.daily_profit, {label: c.name + "'s daily profit", lo: sLo, hi: sHi,
                tip: (v, i) => [c.name, `Game day ${first + i}: profit ${X.num(v)}`]}));
        });

        X.legend($('dash-drones-legend'), [{name: 'Working', colour: C[0]}, {name: 'Wrecks', colour: X.GREY}]);
        const drones = () => X.hbars($('dash-drones'), D.drones_by_place.slice(0, 12).map((p) => ({label: p.place, segs: [p.working, p.wrecks]})),
            [{name: 'Working', colour: C[0]}, {name: 'Wrecks', colour: X.GREY}], {total: true, empty: 'No drones yet.'});

        // Only the parts someone has: a legend entry with nothing drawn (#716: "Bank payments due") is left out
        const wealthParts = [{key: 'shares', name: 'Shares', colour: C[2]}, {key: 'credits', name: 'Credits', colour: C[3]},
            {key: 'bank_due', name: 'Bank payments due', colour: C[5]}].filter((s) => D.players.some((p) => p[s.key] > 0));
        X.legend($('dash-players-legend'), wealthParts);
        const players = () => X.hbars($('dash-players'), D.players.map((p) => ({label: p.name, segs: wealthParts.map((s) => p[s.key])})),
            wealthParts, {total: true, empty: 'No players yet.'});

        [['dash-trading', trading], ['dash-profit', profit], ['dash-goods', goods], ['dash-spreads', spreads],
         ['dash-drones', drones], ['dash-players', players]].forEach(([id, f]) => X.onResize($(id), f));
    });
    </script>
@endsection
