@extends('layouts.app')
@section('pageTitle', 'Startup race')
@section('content')
    <x-page-header :title="'Startup race'" />
    <section class="section section-xs section-first text-md-left">
        <div class="container">
            {{-- The startup race (#776): recent startups against the line they mature at and the starters' usual paths --}}
            <p class="mh-intro">The startups founded over the last {{ $race['days'] }} game days: each one's credits, from the day it bought its
                kit, by game days since it started, against the line a fresh startup matures at ({{ number_format($race['line']) }}: its debt and
                {{ number_format(\App\Services\Startups::matureMargin()) }} to work with). Credits are what maturing needs; goods still aboard
                aren't counted, so a Miner's line climbs in steps each time it sells. Each shaded band is an unchanged starter's usual
                range: from what it holds once it has bought its kit (about {{ number_format($race['starters'][0]['after_kit'] ?? 1000) }} of the
                {{ number_format($race['start_credits']) }} it is lent) to the line, between the fastest and slowest times its card gives.
                Each startup is drawn in its starter's colour (grey when its code is changed)@if($me), yours thicker @endif. Inside or above
                its band, it is doing as well as a typical starter.</p>
            @include('partials.ex-charts')
            <div class="mh">
                <div class="mh-card">
                    <div class="ex-legend" id="race-legend"></div>
                    <div class="ex-chart" id="race" aria-label="Recent startups' credits by game days since they started, against the maturing line"></div>
                    <p class="ex-src">Data: <code>GET /api/v1/startups/race</code></p>
                </div>
            </div>
            @if($race['startups'])
                <div class="table-responsive mt-3">
                    <table class="table table-sm" id="race-table">
                        <thead><tr><th>Startup</th><th>Starter</th><th class="text-right">Game days in</th><th class="text-right">Credits</th><th>Now</th></tr></thead>
                        <tbody>
                        @foreach($race['startups'] as $s)
                            @php($last = end($s['points']) ?: ['age' => 0, 'credits' => 0])
                            <tr @if($me === $s['owner_id']) class="table-active" @endif>
                                <td><a href="{{ url('companies/' . $s['company_id']) }}">{{ $s['name'] }}</a></td>
                                <td>{{ $s['starter'] === 'custom' ? 'its own code' : ucfirst($s['starter']) }}</td>
                                <td class="text-right">{{ $last['age'] }}</td><td class="text-right">{{ number_format($last['credits']) }}</td>
                                <td>{{ $s['state'] === 'matured' ? 'matured in ' . $s['matured_in'] . ' game days' : $s['state'] }}</td></tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </section>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const X = window.ExCharts, C = X.COLOURS, RACE = @json($race), ME = @json($me), box = document.getElementById('race');
        const mine = RACE.startups.filter((s) => s.owner_id === ME);
        // Each starter its own colour; a startup is drawn in its starter's (#864)
        const colourOf = {}; RACE.starters.forEach((s, i) => { colourOf[s.key] = C[i + 1]; });
        const GREY = '#6b7283', lineColour = (s) => colourOf[s.starter] || GREY;
        function draw() {
            const all = RACE.startups.flatMap((s) => s.points);
            if (!all.length) return X.empty(box, 'No startup started in the last ' + RACE.days + ' game days.');
            const {W, H, svg} = (function () { box.replaceChildren(); const W = Math.max(260, box.clientWidth), H = 300;
                return {W, H, svg: X.el('svg', {viewBox: `0 0 ${W} ${H}`, role: 'img', 'aria-label': box.getAttribute('aria-label')}, box)}; })();
            const L = 56, R = 12, T = 16, B = 26;
            // Zoomed to the oldest startup and a fifth more (#833: all were 0 to 2 game days in on an axis to 115)
            const maxAge = Math.max(10, Math.ceil(Math.max(...all.map((p) => p.age)) * 1.2));
            // A starter's usual path, cut where the axis ends
            const clip = (pts) => {
                const out = [pts[0]];
                for (let i = 1; i < pts.length; i++) {
                    const a = pts[i - 1], b = pts[i];
                    if (b.age <= maxAge) { out.push(b); continue; }
                    if (a.age < maxAge) out.push({age: maxAge, credits: a.credits + (b.credits - a.credits) * (maxAge - a.age) / (b.age - a.age)});
                    break;
                }
                return out;
            };
            const tk = X.ticks(Math.min(0, ...all.map((p) => p.credits), ...RACE.starters.map((s) => s.fast[0].credits)),
                Math.max(RACE.line, ...all.map((p) => p.credits)), 4, 100);
            const lo = tk[0], hi = tk[tk.length - 1];
            const x = (a) => L + a / maxAge * (W - L - R), y = (v) => T + (hi - v) / (hi - lo) * (H - T - B);
            tk.forEach((t) => {
                X.el('line', {x1: L, x2: W - R, y1: y(t), y2: y(t), stroke: '#34353f'}, svg);
                X.el('text', {x: L - 6, y: y(t), dy: '0.32em', 'text-anchor': 'end'}, svg).textContent = X.short(t);
            });
            X.el('text', {x: L - 6, y: 6, 'text-anchor': 'end'}, svg).textContent = 'credits';
            // The axis title sits at the right on its own, and a tick too near it is left out (#864: "10 ga7.5me days in" on a phone)
            const title = X.el('text', {x: W - R, y: H - 6, 'text-anchor': 'end'}, svg);
            title.textContent = 'game days in';
            const titleW = (title.getComputedTextLength ? title.getComputedTextLength() : 80) + 12;
            X.ticks(0, maxAge, W < 480 ? 4 : 8, 1).filter((d) => d <= maxAge && x(d) + 12 < W - R - titleW).forEach((d) => {
                X.el('text', {x: x(d), y: H - 6, 'text-anchor': 'middle'}, svg).textContent = d;
            });
            // The line it matures at
            X.el('line', {x1: L, x2: W - R, y1: y(RACE.line), y2: y(RACE.line), stroke: '#d6d8e0', 'stroke-width': 2}, svg);
            X.el('text', {x: W - R, y: y(RACE.line) - 5, 'text-anchor': 'end', fill: '#d6d8e0'}, svg).textContent = 'matures at ' + X.num(RACE.line);
            const path = (pts) => pts.map((p, i) => (i ? 'L' : 'M') + x(p.age) + ',' + y(p.credits)).join('');
            // Each starter's band (#865): between its fast and slow paths, shaded, edged dashed.
            // Only the starters someone is racing, so two near-identical bands don't hide each other
            const racing = new Set(RACE.startups.map((s) => s.starter));
            RACE.starters.filter((s) => racing.has(s.key) || !RACE.startups.length).forEach((s) => {
                const f = clip(s.fast), sl = clip(s.slow);
                const band = path(f) + sl.slice().reverse().map((p) => 'L' + x(p.age) + ',' + y(p.credits)).join('') + 'Z';
                X.el('path', {d: band, fill: colourOf[s.key], 'fill-opacity': 0.12, stroke: 'none'}, svg);
                [f, sl].forEach((pts) => X.el('path', {d: path(pts), fill: 'none', stroke: colourOf[s.key], 'stroke-width': 1.5, 'stroke-dasharray': '6 4'}, svg));
            });
            // Everyone else's, in its starter's colour; then yours, thicker
            const draw1 = (s, wide) => {
                const c = lineColour(s);
                const pl = X.el('path', {d: path(s.points), fill: 'none', stroke: c, 'stroke-width': wide ? 3 : 1.5, 'stroke-opacity': wide ? 1 : 0.75}, svg);
                // A dot at its head, so a startup a few days in still shows
                const head = s.points[s.points.length - 1];
                X.el('circle', {cx: x(head.age), cy: y(head.credits), r: wide ? 3.5 : 2.5, fill: c}, svg);
                const kind = s.starter === 'custom' ? 'its own code' : s.starter;
                X.tip(pl, [s.name + ' (' + kind + ', ' + s.state + ')', X.num(head.credits) + ' credits at ' + head.age + ' game days in']);
            };
            RACE.startups.filter((s) => s.owner_id !== ME).forEach((s) => draw1(s, false));
            mine.forEach((s) => draw1(s, true));
        }
        X.legend(document.getElementById('race-legend'), RACE.starters.map((s) => ({name: s.name + ' (usual: ' + s.days[0] + ' to ' + s.days[1] + ' game days)', colour: colourOf[s.key]}))
            .concat([{name: 'Its own code', colour: GREY}, {name: 'Matures at', colour: '#d6d8e0'}]));
        draw();
        X.onResize(box, draw);
    });
    </script>
@endsection
