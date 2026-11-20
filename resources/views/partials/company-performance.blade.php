{{--
    A company's performance (#699), every chart on one game-day axis so they
    read together: net wealth and credits; a share's cash-out value against
    the bank's selling price, with players' trades as dots; earnings (#600:
    sales up, purchases down, the day's profit a dot); dividends a share and
    their running total; working drones. Takes $perf (UserController) and
    $companyId. The data is in the page; the API gives the same. Every chart
    marks the world's events and the company's own (#720), and cash-out a
    share is a 7-day average with its range (#720: it swung day to day).
--}}
@include('partials.ex-charts')
@push('styles')
    <style>
        .perf-card { margin-bottom: 14px; }
        .perf-card h3 { font-size: 17px; margin: 0; }
    </style>
@endpush
@php($hasCash = collect($perf['history'])->contains(fn ($r) => $r['cash_out_share'] !== null) || count($perf['trades']))
@php($hasDiv = collect($perf['earnings'])->contains(fn ($r) => $r['dividends'] > 0))
<div class="mh" id="company-performance">
    <div class="mh-controls">
        <label for="perf-days">Period
            <select id="perf-days">
                @foreach([10, 30, 60, 365] as $d)
                    <option value="{{ $d }}" @selected($d === 60)>Last {{ $d }} game days</option>
                @endforeach
                <option value="0">All its life</option>
            </select>
        </label>
    </div>
    @include('partials.event-marks-key', ['company' => true, 'companyId' => $companyId])
    <div class="mh-card perf-card">
        <h3>Is it growing?</h3>
        <p class="mh-note">Net wealth and credits at each game day's snapshot. The gap between them is mostly the goods and
            equipment aboard its drones, at market prices. When they move within a small part of their size, the axis is fitted
            to them and doesn't start at 0 (two strokes at its foot mark the cut).</p>
        <div class="ex-legend" id="perf-wealth-legend"></div>
        <div class="ex-chart" id="perf-wealth" aria-label="Net wealth and credits by game day"></div>
        <p class="ex-src">Data: <code>GET /api/v1/companies/{{ $companyId }}/history</code> (net_wealth, credits)</p>
    </div>
    @if($hasCash)
        <div class="mh-card perf-card">
            <h3>What is a share worth?</h3>
            <p class="mh-note">A share's cash-out value, what the bank pays for it, as an average over 7 game days (the line)
                with the least and most it was on those days (the band), and the bank's selling price (cash-out plus {{ \App\Services\PlayerShares::premiumDays() }} game days of
                dividends), averaged the same way; each dot is a trade between two players at its price (the bank's trades are
                at its own prices, and aren't dots). The day-to-day figure swung with where the drones were until cash-out was
                steadied (#685); hover a day for it.
                @if($perf['cash_out_from'] !== null)The world has recorded cash-out since {{ \App\Services\GameDate::ofDay((int) $perf['cash_out_from']) }}: no line
                    before that day, even for a company that matured earlier.@endif</p>
            <div class="ex-legend" id="perf-cash-legend"></div>
            <div class="ex-chart" id="perf-cash" aria-label="Cash-out a share, the bank's price and players' trades"></div>
            <p class="ex-src">Data: <code>GET /api/v1/companies/{{ $companyId }}/history</code> (cash_out_share, bank_sells_at) and
                <code>GET /api/v1/player-shares/{{ $companyId }}</code></p>
        </div>
    @endif
    <div class="mh-card perf-card" id="earnings">
        <h3>Is it earning?</h3>
        <p class="mh-note">Each game day: sales after sales tax (up) and what it paid for goods and equipment (down); the dot
            is the day's profit. Equipment and drones it bought (modules, kits and hulls) are an investment, not counted against profit: the tooltip shows them (#759). Processor tax and push fees aren't trades and aren't shown.</p>
        <div class="ex-legend" id="ce-legend"></div>
        <div class="ex-chart" id="ce-chart" aria-label="Earnings by game day"></div>
        <p class="ex-src" id="ce-total"></p>
        <p class="ex-src">Data: <code>GET /api/v1/companies/{{ $companyId }}/earnings</code></p>
    </div>
    @if(!$hasDiv && $perf['matured'])
        {{-- Why there are none (#744: an empty chart read as a broken one) --}}
        <div class="mh-card perf-card" id="perf-no-dividends">
            <h3>What has a share paid?</h3>
            <p class="mh-note">No dividends yet: {{ ($shares['carried_loss'] ?? 0) > 0 ? 'it is making up earlier losses (' . number_format($shares['carried_loss']) . ' credits to go) before its profit pays its shares' : 'it has made no profit to pay them from' }}.</p>
        </div>
    @endif
    @if($hasDiv)
        <div class="mh-card perf-card">
            <h3>What has a share paid?</h3>
            <p class="mh-note">Dividends a share from each game day's profit (bars), and their running total since the period began (line below).</p>
            <div class="ex-chart" id="perf-div" aria-label="Dividends a share by game day"></div>
            <div class="ex-chart" id="perf-div-total" aria-label="Dividends a share, running total"></div>
            <p class="ex-src">Data: <code>GET /api/v1/companies/{{ $companyId }}/earnings</code> (dividends, over {{ $perf['shares'] }} shares)</p>
        </div>
    @endif
    <div class="mh-card perf-card">
        <h3>How many drones does it run?</h3>
        <div class="ex-chart" id="perf-drones" aria-label="Working drones by game day"></div>
        <p class="ex-src">Data: <code>GET /api/v1/companies/{{ $companyId }}/history</code> (drones)</p>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const X = window.ExCharts, C = X.COLOURS, P = @json($perf), $ = (id) => document.getElementById(id);
    const L = 64;   // one left margin, so every chart's days line up
    X.legend($('perf-wealth-legend'), [{name: 'Net wealth', colour: C[0], kind: 'line'}, {name: 'Credits', colour: C[2], kind: 'line'}]);
    if ($('perf-cash-legend')) X.legend($('perf-cash-legend'), [{name: 'Cash-out a share, 7-day average', colour: C[0], kind: 'line'},
        {name: 'Its least and most those 7 days', colour: C[0], kind: 'band'},
        {name: "Bank's selling price, 7-day average", colour: C[3], kind: 'line'}, {name: 'Trades between players', colour: C[4], kind: 'dot'}]);

    // A 7-day rolling average and range (#720), over the days with a figure:
    // each day's from it and the 6 before, so a period's first days have theirs
    const ROLL = 7;
    function rolling(key) {
        const out = {};
        P.history.forEach((r, i) => {
            const vals = [];
            for (let j = i; j >= 0 && P.history[j].day > r.day - ROLL; j--) if (P.history[j][key] !== null) vals.push(P.history[j][key]);
            out[r.day] = r[key] === null || !vals.length ? null
                : {avg: vals.reduce((a, b) => a + b, 0) / vals.length, lo: Math.min(...vals), hi: Math.max(...vals), n: vals.length};
        });
        return out;
    }
    const cashRoll = rolling('cash_out_share'), bankRoll = rolling('bank_sells_at');
    X.legend($('ce-legend'), [{name: 'Sales after tax', colour: C[0]}, {name: 'Spent on goods', colour: C[1]}, {name: 'Profit', colour: '#e9e9ef', kind: 'dot'}]);

    function draw() {
        const days = Number($('perf-days').value), last = P.today;
        const born = Math.min(last, P.earnings.length ? P.earnings[0].day : last, P.history.length ? P.history[0].day : last);
        const first = days ? Math.max(born, last - days + 1) : born;
        const o = {first, last, L, marks: P.marks};
        const inRange = (r) => r.day >= first && r.day <= last;
        // A day the world stood still (an outage mark) is left out of the
        // earnings and their average, so the chart shows a gap under the
        // outage's band, as the lines do, not a flat zero (#742)
        const stopped = (d) => (P.marks || []).some((m) => m.kind === 'outage' && d >= m.day && d <= (m.to_day ?? m.day));
        const hist = P.history.filter(inRange), earn = P.earnings.filter((r) => inRange(r) && !stopped(r.day));
        const skipped = P.earnings.filter((r) => inRange(r) && stopped(r.day)).length;

        X.lines($('perf-wealth'), [
            {name: 'Net wealth', colour: C[0], points: hist.map((r) => ({day: r.day, v: r.net_wealth}))},
            {name: 'Credits', colour: C[2], points: hist.map((r) => ({day: r.day, v: r.credits}))}],
            // From zero (#744: starting at 375k made a small rise look like a rocket), unless it
            // moves within a small part of its size: then fitted, with the axis marked as cut (#882)
            Object.assign({H: 220, unit: 'cr', zoom: 'auto', empty: 'No daily snapshot in this period yet.'}, o));

        if ($('perf-cash')) {
            const trades = P.trades.filter(inRange);
            X.lines($('perf-cash'), [
                {name: 'Cash-out a share', colour: C[0], band: hist.map((r) => ({day: r.day, lo: cashRoll[r.day]?.lo ?? null, hi: cashRoll[r.day]?.hi ?? null})),
                    points: hist.map((r) => {
                        const a = cashRoll[r.day];
                        return {day: r.day, v: a ? Math.round(a.avg) : null, label: a ? `${X.num(a.avg)} on average over ${a.n} game `
                            + `${a.n === 1 ? 'day' : 'days'} (${X.num(a.lo)} to ${X.num(a.hi)}); ${X.num(r.cash_out_share)} that day` : null};
                    })},
                {name: "Bank's selling price", colour: C[3], dash: '5 3', points: hist.map((r) => {
                    const a = bankRoll[r.day];
                    return {day: r.day, v: a ? Math.round(a.avg) : null, label: a ? `${X.num(a.avg)} on average; ${X.num(r.bank_sells_at)} that day` : null};
                })},
                {name: 'Trade between players', colour: C[4], dotsOnly: true, r: 4, points: trades.map((t) => ({day: t.day, v: t.price,
                    label: `${t.units} at ${t.price} (${t.seller} to ${t.buyer})`}))}],
                // Whole credits a step (#744: a young company's axis read 1, 1, 2, 2)
                Object.assign({H: 200, unit: 'cr', fromZero: false, minStep: 1, empty: 'No cash-out recorded in this period.'}, o));
        }

        X.diverging($('ce-chart'), earn.map((r) => ({day: r.day, up: r.sales, down: r.purchases, dot: r.net, r})),
            Object.assign({H: 240, unit: 'cr', dots: true, empty: 'No sales or purchases in this period.',
                tip: (r, day) => r ? [X.dayDate(day), {colour: C[0], text: `Sales after tax: ${X.num(r.r.sales)} (tax ${X.num(r.r.tax)})`},
                    {colour: C[1], text: `Spent on goods: ${X.num(r.r.purchases)}`}, `Profit: ${X.num(r.r.net)} · ${r.r.trades} trades`,
                    ...(r.r.invested ? [`Invested in equipment and drones: ${X.num(r.r.invested)} (not counted against profit)`] : []),
                    `Dividends paid: ${X.num(r.r.dividends)}`] : [X.dayDate(day)]}, o));
        const s = earn.reduce((a, r) => ({sales: a.sales + r.sales, purchases: a.purchases + r.purchases, dividends: a.dividends + r.dividends,
            invested: a.invested + (r.invested || 0)}), {sales: 0, purchases: 0, dividends: 0, invested: 0});
        $('ce-total').textContent = earn.length ? `Over these ${earn.length} game days: sales after tax ${X.num(s.sales)}, spent on goods `
            + `${X.num(s.purchases)}, profit ${X.num(s.sales - s.purchases)} (${X.num((s.sales - s.purchases) / earn.length)} a day); `
            + `dividends paid ${X.num(s.dividends)}.` + (s.invested ? ` It also invested ${X.num(s.invested)} in equipment and drones.` : '') + (skipped ? ` (${skipped} game ${skipped === 1 ? 'day' : 'days'} of outage left out.)` : '') : '';

        if ($('perf-div')) {
            let run = 0;
            const per = earn.map((r) => ({day: r.day, v: r.dividends / P.shares}));
            // Nothing paid in the period: a line saying so, not two empty charts (#716: a 0 to 1 axis)
            if (!per.some((r) => r.v > 0)) {
                X.empty($('perf-div'), 'No dividends in this period.');
                $('perf-div-total').replaceChildren();
                return drones();
            }
            X.diverging($('perf-div'), per.map((r) => ({day: r.day, up: r.v})), Object.assign({H: 140, unit: 'cr', n: 3,
                upColour: C[2], empty: 'No dividends in this period.',
                tip: (r, day) => [X.dayDate(day), `Dividends a share: ${r ? (Math.round(r.up * 100) / 100).toLocaleString('en') : 0}`]}, o));
            X.lines($('perf-div-total'), [{name: 'Dividends a share so far', colour: C[2],
                points: per.map((r) => ({day: r.day, v: Math.round((run += r.v) * 100) / 100}))}], Object.assign({H: 130, unit: 'cr', n: 3}, o));
        }

        drones();
        function drones() {
            // A company that has only ever run one drone needs no chart of it (#744)
            const card = $('perf-drones').closest('.mh-card');
            card.hidden = hist.length > 0 && Math.max(...hist.map((r) => r.drones || 0)) <= 1;
            if (card.hidden) return;
            X.step($('perf-drones'), hist.map((r) => ({day: r.day, v: r.drones})),
                Object.assign({H: 120, name: 'Working drones', yFmt: (v) => String(v), empty: 'No daily snapshot in this period yet.'}, o));
        }
    }
    $('perf-days').addEventListener('change', draw);
    X.onResize($('company-performance'), draw);
});
</script>
