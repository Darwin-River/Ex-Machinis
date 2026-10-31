@extends('layouts.app')
@section('pageTitle', 'Market history')
@push('styles')
    <style>
        .mh { --mh-text: #e9e9ef; --mh-muted: #a3a6b2; --mh-grid: #34353f; --mh-axis: #828590; --mh-panel: #24242c;
              --mh-bars: #8b93a7; color: var(--mh-text); }
        .mh-intro { white-space: normal; margin-bottom: 1em; max-width: 70ch; }
        .mh-controls { display: flex; flex-wrap: wrap; gap: 1em; margin: 1em 0; }
        .mh-controls label { display: grid; gap: .25em; font-size: 14px; color: var(--mh-muted); }
        .mh-controls select { background: var(--mh-panel); color: var(--mh-text); border: 1px solid var(--mh-axis);
              border-radius: 4px; padding: .3em .5em; font: inherit; font-size: 15px; max-width: 100%; }
        .mh-card { background: var(--mh-panel); border: 1px solid var(--mh-grid); border-radius: 6px; padding: 1em; margin-bottom: 1.25em; }
        .mh-card h3 { font-size: 18px; margin: 0; }
        .mh-note { color: var(--mh-muted); font-size: 14px; margin: .25em 0 .75em; white-space: normal; max-width: 70ch; }
        .mh-legend { display: flex; flex-wrap: wrap; gap: 1em; font-size: 13px; color: var(--mh-muted); margin-bottom: .5em; }
        .mh-legend i { display: inline-block; width: 12px; height: 4px; border-radius: 2px; vertical-align: middle; margin-right: 6px; }
        .mh-chart { position: relative; min-height: 260px; }
        .mh-chart.mh-short { min-height: 160px; }
        .mh-chart svg { display: block; overflow: visible; }
        .mh-chart text { fill: var(--mh-muted); font-size: 12px; font-family: inherit; }
        .mh-tip { position: absolute; top: 0; pointer-events: none; background: #111116; border: 1px solid var(--mh-axis);
              border-radius: 4px; padding: .3em .6em; font-size: 13px; line-height: 1.5; white-space: nowrap; z-index: 2; color: var(--mh-text); }
        .mh-tip div:first-child { color: var(--mh-muted); }
        .mh-tip i { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 6px; }
        .mh-empty { display: flex; align-items: center; justify-content: center; min-height: inherit; color: var(--mh-muted); font-size: 14px; }
        .mh-chart:focus-visible { outline: 2px solid var(--mh-axis); outline-offset: 2px; }
    </style>
@endpush
@section('content')
    {{-- Market history (#380): daily prices and volumes, from /api/v1/history --}}
    <section class="section section-xs section-first bg-default text-md-left">
        <div class="container mh" id="container">
            <h2>Market history</h2>
            <div class="mh-intro">
                What every resource traded for, game day by game day, at each place. Each day's price is the average a
                unit fetched over all of that day's trades; the shaded band runs from the cheapest unit to the dearest.
                The same numbers come from the API's <code>/api/v1/history</code>. Open orders are on the
                <a href="{{ url('market') }}">Market</a> page.
            </div>

            @if($resources->isEmpty())
                <p>No trades yet.</p>
            @else
                <div class="mh-controls">
                    <label for="mh-resource">Resource
                        <select id="mh-resource">
                            @foreach($resources as $r)
                                <option value="{{ $r->id }}">{{ $r->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label for="mh-place">Place
                        <select id="mh-place"><option value="">All places</option></select>
                    </label>
                    <label for="mh-days">Period
                        <select id="mh-days">
                            <option value="10">Last 10 game days</option>
                            <option value="30">Last 30 game days</option>
                            <option value="60" selected>Last 60 game days</option>
                            <option value="365">Last 365 game days</option>
                        </select>
                    </label>
                </div>

                <div class="mh-card">
                    <h3>Price a unit</h3>
                    <p class="mh-note">The average price each game day. Choose one place to see its range too. A gap is a day with no trades there.</p>
                    <div class="mh-legend" id="mh-legend"></div>
                    <div class="mh-chart" id="mh-price" tabindex="0" aria-label="Price a unit by game day"></div>
                </div>

                <div class="mh-card">
                    <h3>Units traded a day</h3>
                    <p class="mh-note">All the units that changed hands each game day, at the places chosen.</p>
                    <div class="mh-chart mh-short" id="mh-units" tabindex="0" aria-label="Units traded by game day"></div>
                </div>
            @endif
        </div>
    </section>

    @if($resources->isNotEmpty())
    <script>
    // Started once the page has loaded: the app bundle (a module script) mounts
    // Vue on #app first, which replaces these elements and their listeners
    document.addEventListener('DOMContentLoaded', function () {
        // A place's colour stays the same whatever else is shown: picked by its place ID
        const COLOURS = ['#3987e5', '#d95926', '#199e70', '#c98500', '#d55181', '#9085e9'];
        const SVG = 'http://www.w3.org/2000/svg';
        const $ = (id) => document.getElementById(id);
        const fmt = (v) => v >= 100 ? Math.round(v).toLocaleString('en') : (Math.round(v * 10) / 10).toLocaleString('en');
        const TODAY = @json($today);
        let rows = [], placeColour = {};

        function el(name, attrs, parent) {
            const e = document.createElementNS(SVG, name);
            for (const k in attrs) e.setAttribute(k, attrs[k]);
            if (parent) parent.appendChild(e);
            return e;
        }
        function niceMax(v) {
            if (v <= 0) return 1;
            const p = Math.pow(10, Math.floor(Math.log10(v))), n = v / p;
            return (n <= 1 ? 1 : n <= 2 ? 2 : n <= 2.5 ? 2.5 : n <= 5 ? 5 : 10) * p;
        }
        function empty(box, text) {
            box.replaceChildren();
            const d = document.createElement('div'); d.className = 'mh-empty'; d.textContent = text; box.appendChild(d);
        }
        function placeTip(tip, box, px, lines) {
            tip.replaceChildren();
            lines.forEach((l, i) => {
                const d = document.createElement('div');
                if (l.colour) { const sw = document.createElement('i'); sw.style.background = l.colour; d.appendChild(sw); }
                d.appendChild(document.createTextNode(l.text)); tip.appendChild(d);
            });
            tip.hidden = false;
            const w = tip.offsetWidth, room = box.clientWidth;
            tip.style.left = Math.max(0, Math.min(room - w, px + 12 + w > room ? px - w - 12 : px + 12)) + 'px';
        }

        // Shared frame: y ticks from 0 to a round maximum, x across the days shown
        function frame(box, H, yMax, days, first, yFmt) {
            box.replaceChildren();
            const W = Math.max(280, box.clientWidth);
            const svg = el('svg', {width: '100%', viewBox: `0 0 ${W} ${H}`, role: 'img'}, box);
            const top = niceMax(yMax), ticks = [0, top / 4, top / 2, top * 3 / 4, top];
            const probe = el('text', {}, svg); probe.textContent = yFmt(top);
            const L = Math.ceil(probe.getComputedTextLength()) + 10; probe.remove();
            const R = 10, B = 24, T = 8;
            const x = (d) => L + (days > 1 ? (d - first) / (days - 1) : 0.5) * (W - L - R);
            const y = (v) => H - B - v / top * (H - B - T);
            ticks.forEach((t) => {
                el('line', {x1: L, x2: W - R, y1: y(t), y2: y(t), stroke: t ? 'var(--mh-grid)' : 'var(--mh-axis)'}, svg);
                const lab = el('text', {x: L - 6, y: y(t), dy: '0.32em', 'text-anchor': 'end'}, svg); lab.textContent = yFmt(t);
            });
            const step = Math.max(1, Math.ceil(days / (W < 480 ? 5 : 10)));
            for (let d = first; d < first + days; d += step) {
                const lab = el('text', {x: x(d), y: H - 6, 'text-anchor': d === first ? 'start' : 'middle'}, svg);
                lab.textContent = d === first ? 'Day ' + d : d;
            }
            return {svg, W, H, L, R, B, T, x, y, top};
        }

        function hover(box, f, first, days, onDay) {
            const tip = document.createElement('div'); tip.className = 'mh-tip'; tip.hidden = true; box.appendChild(tip);
            const rule = el('line', {y1: f.T, y2: f.H - f.B, stroke: 'var(--mh-axis)', visibility: 'hidden'}, f.svg);
            const marks = el('g', {}, f.svg);
            const hit = el('rect', {x: f.L, y: 0, width: f.W - f.L - f.R, height: f.H, fill: 'transparent'}, f.svg);
            const show = (day) => {
                day = Math.max(first, Math.min(first + days - 1, day));
                rule.setAttribute('x1', f.x(day)); rule.setAttribute('x2', f.x(day)); rule.setAttribute('visibility', 'visible');
                marks.replaceChildren();
                const lines = onDay(day, marks);
                placeTip(tip, box, f.x(day) / f.W * box.clientWidth, lines);
                box.dataset.day = day;
            };
            const hide = () => { tip.hidden = true; rule.setAttribute('visibility', 'hidden'); marks.replaceChildren(); };
            hit.addEventListener('pointermove', (ev) => {
                const r = f.svg.getBoundingClientRect(), px = (ev.clientX - r.left) / r.width * f.W;
                show(Math.round(first + (px - f.L) / (f.W - f.L - f.R) * (days - 1)));
            });
            hit.addEventListener('pointerleave', hide);
            box.onkeydown = (ev) => {
                if (ev.key !== 'ArrowLeft' && ev.key !== 'ArrowRight') return;
                ev.preventDefault();
                const now = box.dataset.day === undefined ? first + days - 1 : Number(box.dataset.day);
                show(now + (ev.key === 'ArrowLeft' ? -1 : 1));
            };
            box.onblur = hide;
        }

        function draw() {
            const place = $('mh-place').value, days = Number($('mh-days').value);
            const shown = rows.filter((r) => !place || String(r.place) === place);
            const legend = $('mh-legend'); legend.replaceChildren();
            if (!shown.length) { empty($('mh-price'), 'No trades in this period.'); empty($('mh-units'), 'No trades in this period.'); return; }
            const last = Math.max(TODAY, ...rows.map((r) => r.day)), first = Math.max(0, last - days + 1), span = last - first + 1;
            const places = [...new Set(shown.map((r) => r.place))].sort((a, b) => a - b);
            places.forEach((p) => {
                const s = document.createElement('span'), i = document.createElement('i');
                i.style.background = placeColour[p]; s.appendChild(i);
                s.appendChild(document.createTextNode(shown.find((r) => r.place === p).place_name)); legend.appendChild(s);
            });

            // Price: an average line per place, and the low-high band for one place
            const box = $('mh-price');
            const f = frame(box, 260, Math.max(...shown.map((r) => place ? r.high : r.average)), span, first, fmt);
            const at = (p, d) => shown.find((r) => r.place === p && r.day === d);
            places.forEach((p) => {
                const c = placeColour[p];
                if (place) {
                    let seg = [];
                    const band = () => {
                        if (!seg.length) return;
                        const pts = seg.map((r) => `${f.x(r.day)},${f.y(r.high)}`).concat(seg.slice().reverse().map((r) => `${f.x(r.day)},${f.y(r.low)}`));
                        el('polygon', {points: pts.join(' '), fill: c, 'fill-opacity': 0.18, stroke: 'none'}, f.svg);
                        seg = [];
                    };
                    for (let d = first; d <= last; d++) { const r = at(p, d); if (r) seg.push(r); else band(); }
                    band();
                }
                let path = '', prev = false;
                for (let d = first; d <= last; d++) {
                    const r = at(p, d);
                    if (r) { path += (prev ? 'L' : 'M') + f.x(d) + ',' + f.y(r.average); prev = true; } else prev = false;
                }
                el('path', {d: path, fill: 'none', stroke: c, 'stroke-width': 2, 'stroke-linejoin': 'round', 'stroke-linecap': 'round'}, f.svg);
                for (let d = first; d <= last; d++) {   // a lone day shows as a dot
                    if (at(p, d) && !at(p, d - 1) && !at(p, d + 1)) el('circle', {cx: f.x(d), cy: f.y(at(p, d).average), r: 2.5, fill: c}, f.svg);
                }
            });
            hover(box, f, first, span, (day, marks) => {
                const lines = [{text: 'Game day ' + day}];
                places.forEach((p) => {
                    const r = at(p, day), name = shown.find((q) => q.place === p).place_name;
                    if (r) el('circle', {cx: f.x(day), cy: f.y(r.average), r: 4, fill: placeColour[p], stroke: 'var(--mh-panel)', 'stroke-width': 2}, marks);
                    lines.push({colour: placeColour[p], text: r
                        ? `${name}: ${fmt(r.average)} a unit (${fmt(r.low)} to ${fmt(r.high)}), ${r.units.toLocaleString('en')} units`
                        : `${name}: no trades`});
                });
                return lines;
            });

            // Units: one bar a day, all places chosen together
            const ubox = $('mh-units');
            const units = []; for (let d = first; d <= last; d++) units.push(shown.filter((r) => r.day === d).reduce((s, r) => s + r.units, 0));
            const g = frame(ubox, 160, Math.max(...units), span, first, (v) => Math.round(v).toLocaleString('en'));
            const bw = Math.max(1, Math.min(32, (g.W - g.L - g.R) / span * 0.7));
            units.forEach((u, i) => {
                if (!u) return;
                const h = g.y(0) - g.y(u);
                el('rect', {x: g.x(first + i) - bw / 2, width: bw, y: g.y(u), height: h, rx: Math.min(2, bw / 2), fill: 'var(--mh-bars)'}, g.svg);
            });
            hover(ubox, g, first, span, (day) => [{text: 'Game day ' + day}, {text: units[day - first].toLocaleString('en') + ' units'}]);
        }

        async function load() {
            const resource = $('mh-resource').value, days = $('mh-days').value;
            $('mh-price').setAttribute('aria-busy', 'true');
            try {
                const res = await fetch(`{{ url('api/v1/history') }}?resource=${encodeURIComponent(resource)}&days=${encodeURIComponent(days)}`);
                if (!res.ok) throw new Error(res.status);
                rows = await res.json();
            } catch (e) {
                rows = [];
                empty($('mh-price'), 'The history did not load. Try again in a minute.'); empty($('mh-units'), '');
                return;
            } finally { $('mh-price').removeAttribute('aria-busy'); }
            const sel = $('mh-place'), keep = sel.value;
            const places = [...new Map(rows.map((r) => [r.place, r.place_name])).entries()].sort((a, b) => a[0] - b[0]);
            sel.replaceChildren(new Option('All places', ''), ...places.map(([id, name]) => new Option(name, id)));
            sel.value = places.some(([id]) => String(id) === keep) ? keep : '';
            places.forEach(([id]) => { if (!(id in placeColour)) placeColour[id] = COLOURS[Object.keys(placeColour).length % COLOURS.length]; });
            draw();
        }

        // Every place known to the game gets its colour up front, so it never changes
        @json($places).forEach((id, i) => { placeColour[id] = COLOURS[i % COLOURS.length]; });
        $('mh-resource').addEventListener('change', load);
        $('mh-days').addEventListener('change', load);
        $('mh-place').addEventListener('change', draw);
        let width = 0;
        new ResizeObserver(() => { const w = $('mh-price').clientWidth; if (w !== width) { width = w; if (rows.length) draw(); } }).observe($('mh-price'));
        load();
    });
    </script>
    @endif
@endsection
