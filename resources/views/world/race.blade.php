@extends('layouts.app')
@section('pageTitle', 'Startup race')
@section('content')
    <x-page-header :title="'Startup race'" />
    <section class="section section-xs section-first text-md-left">
        <div class="container">
            {{-- The startup race (#776): recent startups against the line they mature at and the starters' usual paths --}}
            <p class="mh-intro">The startups founded over the last {{ $race['days'] }} game days: each one's credits by game days since it
                started, against the line a fresh startup matures at ({{ number_format($race['line']) }}: its debt and
                {{ number_format(\App\Services\Startups::matureMargin()) }} to work with). The dashed lines are each unchanged starter's usual
                path: from what it holds once it has bought its kit (about {{ number_format($race['starters'][0]['after_kit'] ?? 1000) }} of the
                {{ number_format($race['start_credits']) }} it is lent) to the line at the middle of the time its card gives.
                @if($me) Yours are in colour.@endif Above a dashed line is ahead of a typical starter of that kind.</p>
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
                        <thead><tr><th>Startup</th><th class="text-right">Game days in</th><th class="text-right">Credits</th><th>Now</th></tr></thead>
                        <tbody>
                        @foreach($race['startups'] as $s)
                            @php($last = end($s['points']) ?: ['age' => 0, 'credits' => 0])
                            <tr @if($me === $s['owner_id']) class="table-active" @endif>
                                <td><a href="{{ url('companies/' . $s['company_id']) }}">{{ $s['name'] }}</a></td>
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
                const [a, b] = pts;
                if (b.age <= maxAge) return pts;
                return [a, {age: maxAge, credits: a.credits + (b.credits - a.credits) * (maxAge - a.age) / (b.age - a.age)}];
            };
            const tk = X.ticks(Math.min(0, ...all.map((p) => p.credits), ...RACE.starters.map((s) => s.path[0].credits)),
                Math.max(RACE.line, ...all.map((p) => p.credits)), 4, 100);
            const lo = tk[0], hi = tk[tk.length - 1];
            const x = (a) => L + a / maxAge * (W - L - R), y = (v) => T + (hi - v) / (hi - lo) * (H - T - B);
            tk.forEach((t) => {
                X.el('line', {x1: L, x2: W - R, y1: y(t), y2: y(t), stroke: '#34353f'}, svg);
                X.el('text', {x: L - 6, y: y(t), dy: '0.32em', 'text-anchor': 'end'}, svg).textContent = X.short(t);
            });
            X.el('text', {x: L - 6, y: 6, 'text-anchor': 'end'}, svg).textContent = 'credits';
            X.ticks(0, maxAge, W < 480 ? 4 : 8, 1).filter((d) => d <= maxAge).forEach((d, i, a) => {
                X.el('text', {x: x(d), y: H - 6, 'text-anchor': i === a.length - 1 ? 'end' : 'middle'}, svg).textContent = d + (i === a.length - 1 ? ' game days in' : '');
            });
            // The line it matures at
            X.el('line', {x1: L, x2: W - R, y1: y(RACE.line), y2: y(RACE.line), stroke: '#d6d8e0', 'stroke-width': 2}, svg);
            X.el('text', {x: W - R, y: y(RACE.line) - 5, 'text-anchor': 'end', fill: '#d6d8e0'}, svg).textContent = 'matures at ' + X.num(RACE.line);
            // Everyone else's, grey; then the starters' paths; then yours, in colour
            const path = (pts) => pts.map((p, i) => (i ? 'L' : 'M') + x(p.age) + ',' + y(p.credits)).join('');
            RACE.startups.filter((s) => s.owner_id !== ME).forEach((s) => {
                const pl = X.el('path', {d: path(s.points), fill: 'none', stroke: '#6b7283', 'stroke-width': 1.5, 'stroke-opacity': 0.7}, svg);
                // A dot at its head, so a startup a few days in still shows
                const head = s.points[s.points.length - 1];
                X.el('circle', {cx: x(head.age), cy: y(head.credits), r: 2.5, fill: '#6b7283'}, svg);
                X.tip(pl, [s.name + ' (' + s.state + ')', X.num(s.points[s.points.length - 1].credits) + ' credits at ' + s.points[s.points.length - 1].age + ' game days in']);
            });
            RACE.starters.forEach((s, i) => {
                X.el('path', {d: path(clip(s.path)), fill: 'none', stroke: C[i + 1], 'stroke-width': 2, 'stroke-dasharray': '6 4'}, svg);
            });
            mine.forEach((s) => {
                X.el('path', {d: path(s.points), fill: 'none', stroke: C[0], 'stroke-width': 3}, svg);
                s.points.forEach((p) => X.tip(X.el('circle', {cx: x(p.age), cy: y(p.credits), r: 3, fill: C[0], stroke: '#111729', 'stroke-width': 1}, svg),
                    [s.name, X.num(p.credits) + ' credits at ' + p.age + ' game days in']));
            });
        }
        X.legend(document.getElementById('race-legend'), (mine.length ? [{name: 'Yours', colour: C[0]}] : [])
            .concat([{name: 'Other startups', colour: '#6b7283'}])
            .concat(RACE.starters.map((s, i) => ({name: 'Usual ' + s.name + ' (' + s.days[0] + ' to ' + s.days[1] + ' game days)', colour: C[i + 1]})))
            .concat([{name: 'Matures at', colour: '#d6d8e0'}]));
        draw();
        X.onResize(box, draw);
    });
    </script>
@endsection
