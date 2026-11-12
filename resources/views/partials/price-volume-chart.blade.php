{{--
    The price and volume chart (#595), shared by Market history and each
    product page (#597), and a company's share price (#600). Takes:
      $chartResource  a resource ID to chart, or null for a resource menu ($resources)
      $places, $today the places' colour order and today's game day (MarketHistory::frame())
      $chartDays      the period shown first; $chartTitle the card's heading
    and, for another series in the same row format, optionally:
      $chartSource    the URL its rows come from (instead of /api/v1/history)
      $chartPer       what a price is for ('unit'), $chartUnits what the bars count ('units')
      $chartNote      the card's note; $chartPlaceMenu false hides the place menu
--}}
@php
    $chartSource = $chartSource ?? null;
    $chartPer = $chartPer ?? 'unit';
    $chartUnits = $chartUnits ?? 'units';
    $chartPlaceMenu = $chartPlaceMenu ?? true;
    $chartNote = $chartNote ?? 'The dots and lines are the average price each game day (left axis); the thin line through each '
        . 'dot runs from the cheapest unit traded that day to the dearest. A gap is a day with no trades there. The bars along '
        . 'the bottom are all the units that changed hands each game day at the places chosen (right axis).';
@endphp
@include('partials.chart-styles')
<div class="mh">
                <div class="mh-controls">
                    @if($chartResource === null)
                    <label for="mh-resource">Resource
                        <select id="mh-resource">
                            @foreach($resources as $r)
                                <option value="{{ $r->id }}">{{ $r->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    @endif
                    <label for="mh-place" @if(!$chartPlaceMenu) hidden @endif>Place
                        <select id="mh-place"><option value="">All places</option></select>
                    </label>
                    <label for="mh-days">Period
                        <select id="mh-days">
                            <option value="10" @selected($chartDays === 10)>Last 10 game days</option>
                            <option value="30" @selected($chartDays === 30)>Last 30 game days</option>
                            <option value="60" @selected($chartDays === 60)>Last 60 game days</option>
                            <option value="365" @selected($chartDays === 365)>Last 365 game days</option>
                        </select>
                    </label>
                </div>

                {{-- Price and volume in one chart (#595): price on the left axis, units on the right --}}
                <div class="mh-card">
                    <h3>{{ $chartTitle }}</h3>
                    <p class="mh-note">{{ $chartNote }}</p>
                    <div class="mh-legend" id="mh-legend"></div>
                    <div class="mh-chart" id="mh-price" tabindex="0" aria-label="{{ $chartTitle }} by game day"></div>
                </div>
</div>

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
        const FIXED = @json($chartResource);   // a product page charts its own resource (#597)
        const SOURCE = @json($chartSource), PER = @json($chartPer), UNITS = @json($chartUnits);   // #600
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
        function frame(box, H, yMax, days, first, yFmt, right) {
            box.replaceChildren();
            const W = Math.max(280, box.clientWidth);
            const svg = el('svg', {width: '100%', viewBox: `0 0 ${W} ${H}`, role: 'img'}, box);
            const top = niceMax(yMax), ticks = [0, top / 4, top / 2, top * 3 / 4, top];
            const probe = el('text', {}, svg); probe.textContent = yFmt(top);
            const L = Math.ceil(probe.getComputedTextLength()) + 10; probe.remove();
            let R = 10;
            const B = 24, T = 20;
            if (right) {   // the right axis's labels set its margin (#595)
                const rp = el('text', {}, svg); rp.textContent = right.fmt(niceMax(right.max));
                R = Math.ceil(rp.getComputedTextLength()) + 10; rp.remove();
            }
            const x = (d) => L + (days > 1 ? (d - first) / (days - 1) : 0.5) * (W - L - R);
            const y = (v) => H - B - v / top * (H - B - T);
            ticks.forEach((t) => {
                el('line', {x1: L, x2: W - R, y1: y(t), y2: y(t), stroke: t ? 'var(--mh-grid)' : 'var(--mh-axis)'}, svg);
                const lab = el('text', {x: L - 6, y: y(t), dy: '0.32em', 'text-anchor': 'end'}, svg); lab.textContent = yFmt(t);
            });
            el('text', {x: L - 6, y: 8, 'text-anchor': 'end'}, svg).textContent = 'cr';
            let yr = null, rtop = 0;
            if (right) {
                // The second measure fills the bottom quarter only, so its bars
                // never cross the lines above: where they would cross means nothing
                rtop = niceMax(right.max);
                const band = (H - B - T) * right.share;
                yr = (v) => H - B - v / rtop * band;
                [rtop / 2, rtop].forEach((t) => {
                    el('line', {x1: W - R, x2: W - R + 4, y1: yr(t), y2: yr(t), stroke: 'var(--mh-axis)'}, svg);
                    const lab = el('text', {x: W - R + 6, y: yr(t), dy: '0.32em', 'text-anchor': 'start'}, svg); lab.textContent = right.fmt(t);
                });
                el('line', {x1: W - R, x2: W - R, y1: yr(rtop), y2: yr(0), stroke: 'var(--mh-axis)'}, svg);
                el('text', {x: W - R + 6, y: yr(rtop) - 12, 'text-anchor': 'start'}, svg).textContent = right.label;
            }
            const step = Math.max(1, Math.ceil(days / (W < 480 ? 5 : 10)));
            let clear = 0;   // a label never overlaps the one before (round 62: "Day 603615" at 390 px)
            for (let d = first; d < first + days; d += step) {
                const lab = el('text', {x: x(d), y: H - 6, 'text-anchor': d === first ? 'start' : 'middle'}, svg);
                lab.textContent = d === first ? 'Day ' + d : d;
                const w = lab.getComputedTextLength(), left = d === first ? x(d) : x(d) - w / 2;
                if (left < clear) { lab.remove(); continue; }
                clear = left + w + 8;
            }
            return {svg, W, H, L, R, B, T, x, y, top, yr, rtop};
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
            const last = Math.max(TODAY, ...rows.map((r) => r.day)), first = Math.max(0, last - days + 1), span = last - first + 1;
            const shown = rows.filter((r) => (!place || String(r.place) === place) && r.day >= first);
            const legend = $('mh-legend'); legend.replaceChildren();
            if (!shown.length) { empty($('mh-price'), 'No trades in this period.'); return; }
            const places = [...new Set(shown.map((r) => r.place))].sort((a, b) => a - b);
            places.forEach((p) => {
                const s = document.createElement('span'), i = document.createElement('i');
                i.style.background = placeColour[p]; s.appendChild(i);
                s.appendChild(document.createTextNode(shown.find((r) => r.place === p).place_name)); legend.appendChild(s);
            });
            const ub = document.createElement('span'), ui = document.createElement('i');
            ui.className = 'mh-bar'; ub.appendChild(ui); ub.appendChild(document.createTextNode(UNITS[0].toUpperCase() + UNITS.slice(1) + ' traded (right axis)'));
            legend.appendChild(ub);

            // Units: one bar a day, all places chosen together, in the bottom band (#595)
            const units = []; for (let d = first; d <= last; d++) units.push(shown.filter((r) => r.day === d).reduce((s, r) => s + r.units, 0));
            const ufmt = (v) => Math.round(v).toLocaleString('en');

            // Price: an average line per place, with each day's cheapest-to-dearest
            // line through its dot (#598)
            const box = $('mh-price');
            const f = frame(box, 320, Math.max(...shown.map((r) => r.high)), span, first, fmt,
                {max: Math.max(...units), fmt: ufmt, share: 0.25, label: UNITS});
            const bw = Math.max(1, Math.min(32, (f.W - f.L - f.R) / span * 0.7));
            units.forEach((u, i) => {
                if (!u) return;
                el('rect', {x: f.x(first + i) - bw / 2, width: bw, y: f.yr(u), height: f.yr(0) - f.yr(u),
                    rx: Math.min(2, bw / 2), fill: 'var(--mh-bars)', 'fill-opacity': 0.55}, f.svg);
            });
            const at = (p, d) => shown.find((r) => r.place === p && r.day === d);
            places.forEach((p) => {
                const c = placeColour[p];
                let path = '', prev = false;
                for (let d = first; d <= last; d++) {
                    const r = at(p, d);
                    if (r) { path += (prev ? 'L' : 'M') + f.x(d) + ',' + f.y(r.average); prev = true; } else prev = false;
                }
                el('path', {d: path, fill: 'none', stroke: c, 'stroke-width': 2, 'stroke-linejoin': 'round', 'stroke-linecap': 'round'}, f.svg);
            });
            // Each day's range and average (#598): places side by side on a shared day
            const slot = (f.W - f.L - f.R) / Math.max(1, span - 1);
            const gap = Math.min(5, slot / (places.length + 1));
            places.forEach((p, i) => {
                const c = placeColour[p], dx = (i - (places.length - 1) / 2) * gap;
                for (let d = first; d <= last; d++) {
                    const r = at(p, d);
                    if (!r) continue;
                    if (r.high > r.low) el('line', {x1: f.x(d) + dx, x2: f.x(d) + dx, y1: f.y(r.high), y2: f.y(r.low),
                        stroke: c, 'stroke-width': 1.5, 'stroke-opacity': 0.6, 'stroke-linecap': 'round'}, f.svg);
                    el('circle', {cx: f.x(d) + dx, cy: f.y(r.average), r: span > 120 ? 2 : 3, fill: c,
                        stroke: 'var(--mh-panel)', 'stroke-width': 1}, f.svg);
                }
            });
            hover(box, f, first, span, (day, marks) => {
                const lines = [{text: 'Game day ' + day}];
                places.forEach((p) => {
                    const r = at(p, day), name = shown.find((q) => q.place === p).place_name;
                    if (r) el('circle', {cx: f.x(day), cy: f.y(r.average), r: 4, fill: placeColour[p], stroke: 'var(--mh-panel)', 'stroke-width': 2}, marks);
                    lines.push({colour: placeColour[p], text: r
                        ? `${name}: ${fmt(r.average)} a ${PER} (${fmt(r.low)} to ${fmt(r.high)}), ${r.units.toLocaleString('en')} ${UNITS}`
                        : `${name}: no trades`});
                });
                lines.push({text: `${UNITS[0].toUpperCase() + UNITS.slice(1)} traded: ${ufmt(units[day - first])}`});
                return lines;
            });
        }

        async function load() {
            const resource = FIXED ?? $('mh-resource').value, days = $('mh-days').value;
            $('mh-price').setAttribute('aria-busy', 'true');
            try {
                const res = await fetch(SOURCE ? `${SOURCE}?days=${encodeURIComponent(days)}`
                    : `{{ url('api/v1/history') }}?resource=${encodeURIComponent(resource)}&days=${encodeURIComponent(days)}`);
                if (!res.ok) throw new Error(res.status);
                rows = await res.json();
            } catch (e) {
                rows = [];
                empty($('mh-price'), 'The history did not load. Try again in a minute.');
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
        if ($('mh-resource')) $('mh-resource').addEventListener('change', load);
        $('mh-days').addEventListener('change', load);
        $('mh-place').addEventListener('change', draw);
        let width = 0;
        new ResizeObserver(() => { const w = $('mh-price').clientWidth; if (w !== width) { width = w; if (rows.length) draw(); } }).observe($('mh-price'));
        load();
    });
    </script>
