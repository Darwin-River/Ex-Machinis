{{--
    The site's chart kit (#698, #699): inline SVG drawn in the page from data
    the server worked out, in the look of the price charts (#595). Pushed
    once however many charts a page has. window.ExCharts draws:
      frame()    axes on game days, with even y ticks
      stacked()  stacked bars a day (credits traded by place)
      diverging() bars up and down from zero a day, with an optional dot
      lines()    lines a day; step() a step line
      hbars()    horizontal bars, stacked by segment
      dots()     a dot plot of two values a row (best ask and best bid)
      spark()    a sparkline, bars up and down
    Every mark has a tooltip (hover, or focus and arrow keys on a day chart).
    The colours pass the colour-blind checks against the dark panel; a place
    or good keeps its colour whatever else is shown.
--}}
@include('partials.chart-styles')
@once
@push('styles')
    <style>
        .ex-tip { position: fixed; z-index: 1000; pointer-events: none; background: #0b0f1a; color: #e9e9ef;
              border: 1px solid #828590; border-radius: 4px; padding: .3em .6em; font-size: 13px; line-height: 1.5;
              max-width: min(320px, calc(100vw - 24px)); }
        .ex-tip div:first-child { font-weight: 600; }
        .ex-tip i { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 6px; }
        .ex-chart { position: relative; width: 100%; }
        .ex-chart svg { display: block; width: 100%; height: auto; overflow: visible; }
        .ex-chart text { fill: var(--mh-muted, #a3a6b2); font-size: 12px; font-family: inherit; }
        .ex-chart text.ex-strong { fill: var(--mh-text, #e9e9ef); }
        .ex-chart:focus-visible { outline: 2px solid #828590; outline-offset: 2px; }
        .ex-none { color: var(--mh-muted, #a3a6b2); font-size: 14px; margin: .25em 0; white-space: normal; }
        .ex-src { color: var(--mh-muted, #a3a6b2); font-size: 12px; margin: .5em 0 0; white-space: normal; }
        .ex-src code { color: inherit; }
        .ex-legend { display: flex; flex-wrap: wrap; gap: .4em 1em; font-size: 13px; color: var(--mh-muted, #a3a6b2); margin-bottom: .4em; }
        .ex-legend i { display: inline-block; width: 10px; height: 10px; border-radius: 2px; vertical-align: -1px; margin-right: 5px; }
        .ex-legend i.ex-dot { border-radius: 50%; }
        .ex-legend i.ex-ring { border-radius: 50%; background: transparent !important; border: 2px solid; }
        .ex-legend i.ex-line { height: 3px; width: 14px; vertical-align: 3px; }
    </style>
@endpush
<script>
window.ExCharts = (function () {
    const SVG = 'http://www.w3.org/2000/svg';
    // Categorical, in a fixed order (checked for colour blindness on the dark panel)
    const COLOURS = ['#3987e5', '#d95926', '#199e70', '#c98500', '#d55181', '#9085e9'];
    const GREY = '#6b7283', NEG = '#e66767', GRID = '#34353f', AXIS = '#828590';
    const num = (v) => v === null || v === undefined ? '–' : Math.round(v).toLocaleString('en');
    const short = (v) => {
        const a = Math.abs(v);
        if (a >= 1e6) return (v / 1e6).toLocaleString('en', {maximumFractionDigits: 1}) + 'M';
        if (a >= 1e4) return (v / 1e3).toLocaleString('en', {maximumFractionDigits: 1}) + 'k';
        if (a < 1e-9) return '0';
        return v.toLocaleString('en', {maximumFractionDigits: a < 10 ? 2 : 0});
    };

    function el(name, attrs, parent) {
        const e = document.createElementNS(SVG, name);
        for (const k in attrs) e.setAttribute(k, attrs[k]);
        if (parent) parent.appendChild(e);
        return e;
    }

    // One tooltip for the page, outside the Vue root; lines are text, never HTML
    let tipBox = null;
    function tipNode() {
        if (!tipBox) { tipBox = document.createElement('div'); tipBox.className = 'ex-tip'; tipBox.hidden = true; document.body.appendChild(tipBox); }
        return tipBox;
    }
    function showTip(lines, x, y) {
        const t = tipNode();
        t.replaceChildren();
        lines.forEach((l) => {
            const d = document.createElement('div');
            if (l && typeof l === 'object') {
                if (l.colour) { const i = document.createElement('i'); i.style.background = l.colour; d.appendChild(i); }
                d.appendChild(document.createTextNode(l.text));
            } else d.textContent = l;
            t.appendChild(d);
        });
        t.hidden = false;
        const w = t.offsetWidth, h = t.offsetHeight;
        t.style.left = Math.max(8, Math.min(window.innerWidth - w - 8, x + 14)) + 'px';
        t.style.top = (y + 14 + h > window.innerHeight ? y - h - 10 : y + 14) + 'px';
    }
    function hideTip() { if (tipBox) tipBox.hidden = true; }
    // A mark's tooltip on hover and on touch
    function tip(node, lines) {
        node.addEventListener('pointermove', (ev) => showTip(typeof lines === 'function' ? lines() : lines, ev.clientX, ev.clientY));
        node.addEventListener('pointerleave', hideTip);
        node.style.cursor = 'default';
        return node;
    }

    // Even ticks from lo to hi (lo may be below 0): a round step, about n of them
    function ticks(lo, hi, n, minStep) {
        if (hi <= lo) hi = lo + 1;
        const raw = (hi - lo) / Math.max(1, n), p = Math.pow(10, Math.floor(Math.log10(raw))), m = raw / p;
        const step = Math.max(minStep || 0, (m <= 1 ? 1 : m <= 2 ? 2 : m <= 2.5 ? 2.5 : m <= 5 ? 5 : 10) * p);
        const a = Math.floor(lo / step) * step, b = Math.ceil(hi / step) * step, out = [];
        for (let v = a; v <= b + step / 2; v += step) out.push(Math.round(v * 1e6) / 1e6);
        return out;
    }

    function svgIn(box, H) {
        box.replaceChildren();
        const W = Math.max(260, box.clientWidth);
        return {W, H, svg: el('svg', {viewBox: `0 0 ${W} ${H}`, role: 'img', 'aria-label': box.getAttribute('aria-label') || ''}, box)};
    }

    // Nothing to draw: one line saying so, not an empty chart (#716: "No dividends" drawn on a 0 to 1 axis)
    function empty(box, text) {
        box.replaceChildren();
        const d = document.createElement('p'); d.className = 'ex-none'; d.textContent = text; box.appendChild(d);
    }

    /**
     * Axes over game days [first, last] and y from lo to hi with even ticks.
     * opts: H, lo, hi, yFmt, L (left margin, so charts stacked on one page
     * line up), unit (shown above the axis), zero (draw 0 stronger)
     */
    function frame(box, first, last, opts) {
        const {W, H, svg} = svgIn(box, opts.H || 220);
        const T = 16, B = 22, R = 10, L = opts.L || 56;
        const tk = ticks(opts.lo ?? 0, Math.max(opts.hi, (opts.lo ?? 0) + (opts.minStep || 1)), opts.n || 4, opts.minStep), lo = tk[0], hi = tk[tk.length - 1];
        const span = Math.max(1, last - first);
        const slot = (W - L - R) / (span + 1);
        const x = (d) => L + slot / 2 + (d - first) * slot;
        const y = (v) => T + (hi - v) / (hi - lo) * (H - T - B);
        const fmt = opts.yFmt || short;
        tk.forEach((t) => {
            el('line', {x1: L, x2: W - R, y1: y(t), y2: y(t), stroke: t === 0 && lo < 0 ? AXIS : GRID}, svg);
            el('text', {x: L - 6, y: y(t), dy: '0.32em', 'text-anchor': 'end'}, svg).textContent = fmt(t);
        });
        if (opts.unit) el('text', {x: L - 6, y: 4, 'text-anchor': 'end'}, svg).textContent = opts.unit;
        const every = Math.max(1, Math.ceil((span + 1) / (W < 480 ? 4 : 9)));
        let clear = Infinity;
        for (let d = last; d >= first; d -= every) {   // from the newest day back, so today is always labelled
            const lab = el('text', {x: x(d), y: H - 5, 'text-anchor': 'middle'}, svg);
            lab.textContent = (d === last || d - every < first ? 'day ' : '') + d;
            const w = lab.getComputedTextLength();
            // Its left and right edges as drawn: a label pushed in from the
            // right edge is end-anchored, so it reaches a whole width left
            // (#716: "2201day 2208", the next label drawn under it)
            let left = x(d) - w / 2, right = x(d) + w / 2;
            if (right > W) { lab.setAttribute('text-anchor', 'end'); lab.setAttribute('x', W); left = W - w; right = W; }
            if (right > clear) { lab.remove(); continue; }
            clear = left - 8;
        }
        return {svg, W, H, T, B, L, R, x, y, slot, lo, hi, first, last};
    }

    // Hover and arrow keys over a day chart: a rule, and the day's lines
    function dayHover(box, f, linesFor) {
        const rule = el('line', {y1: f.T, y2: f.H - f.B, stroke: AXIS, visibility: 'hidden'}, f.svg);
        const hit = el('rect', {x: f.L, y: 0, width: f.W - f.L - f.R, height: f.H - f.B, fill: 'transparent'}, f.svg);
        const at = (d, cx, cy) => {
            d = Math.max(f.first, Math.min(f.last, d));
            rule.setAttribute('x1', f.x(d)); rule.setAttribute('x2', f.x(d)); rule.setAttribute('visibility', 'visible');
            showTip(linesFor(d), cx, cy); box.dataset.day = d;
        };
        hit.addEventListener('pointermove', (ev) => {
            const r = f.svg.getBoundingClientRect(), px = (ev.clientX - r.left) / r.width * f.W;
            at(Math.round(f.first + (px - f.L - f.slot / 2) / f.slot), ev.clientX, ev.clientY);
        });
        const off = () => { hideTip(); rule.setAttribute('visibility', 'hidden'); };
        hit.addEventListener('pointerleave', off);
        box.tabIndex = 0;
        box.onkeydown = (ev) => {
            if (ev.key !== 'ArrowLeft' && ev.key !== 'ArrowRight') return;
            ev.preventDefault();
            const d = (box.dataset.day === undefined ? f.last : Number(box.dataset.day)) + (ev.key === 'ArrowLeft' ? -1 : 1);
            const r = box.getBoundingClientRect();
            at(d, r.left + f.x(Math.max(f.first, Math.min(f.last, d))) / f.W * r.width, r.top + 20);
        };
        box.onblur = off;
    }

    function bar(svg, x, w, y0, y1, fill, extra) {
        const top = Math.min(y0, y1), h = Math.abs(y1 - y0);
        if (h <= 0) return null;
        return el('rect', Object.assign({x: x - w / 2, y: top, width: w, height: Math.max(0.75, h), rx: Math.min(2, w / 3), fill}, extra || {}), svg);
    }

    // Today's game day isn't over (#716: drawn as a full bar, it read as a
    // collapse): its bars are faded and hatched, and its tooltip says so
    let hatchN = 0;
    function partialBar(svg, x, w, y0, y1, fill) {
        const r = bar(svg, x, w, y0, y1, fill, {'fill-opacity': 0.45});
        if (!r) return r;
        let id = svg.dataset.hatch;
        if (!id) {
            id = svg.dataset.hatch = 'ex-hatch-' + (++hatchN);
            const p = el('pattern', {id, width: 5, height: 5, patternUnits: 'userSpaceOnUse', patternTransform: 'rotate(45)'}, el('defs', {}, svg));
            el('line', {x1: 0, y1: 0, x2: 0, y2: 5, stroke: '#e9e9ef', 'stroke-width': 1.5, 'stroke-opacity': 0.55}, p);
        }
        const top = Math.min(y0, y1), h = Math.abs(y1 - y0);
        el('rect', {x: x - w / 2, y: top, width: w, height: Math.max(0.75, h), fill: `url(#${id})`}, svg);
        return r;
    }
    const SO_FAR = ' (so far: the day isn\'t over)';

    /** Stacked bars a day: days [{day, values: [..by series]}], series [{name, colour}] */
    function stacked(box, days, series, opts) {
        if (!days.length || !days.some((d) => d.values.some((v) => v > 0))) return empty(box, opts.empty || 'Nothing in this period.');
        const f = frame(box, days[0].day, days[days.length - 1].day,
            Object.assign({hi: Math.max(...days.map((d) => d.values.reduce((a, b) => a + b, 0)))}, opts));
        const w = Math.max(1, Math.min(24, f.slot - 2));
        days.forEach((d) => {
            let acc = 0;
            d.values.forEach((v, i) => {
                if (v <= 0) return;
                const y0 = f.y(acc), y1 = f.y(acc + v);
                // a 1px gap between segments where there is room
                (d.day === opts.partial ? partialBar : bar)(f.svg, f.x(d.day), w, y0 - (acc > 0 && y0 - y1 > 3 ? 1 : 0), y1, series[i].colour);
                acc += v;
            });
        });
        const byDay = Object.fromEntries(days.map((d) => [d.day, d]));
        dayHover(box, f, (day) => {
            const d = byDay[day];
            const lines = ['Game day ' + day + (day === opts.partial ? SO_FAR : '') + (opts.dayNote ? opts.dayNote(d) : '')];
            series.forEach((s, i) => { if (d && d.values[i]) lines.push({colour: s.colour, text: `${s.name}: ${num(d.values[i])}`}); });
            if (d) lines.push('Total: ' + num(d.values.reduce((a, b) => a + b, 0)) + (opts.unitWord ? ' ' + opts.unitWord : ''));
            return lines;
        });
        return f;
    }

    /** Bars up and down from zero a day: rows [{day, up, down, dot}] (down is a positive number drawn below) */
    function diverging(box, rows, opts) {
        if (!rows.length || !rows.some((r) => r.up || r.down || r.dot)) return empty(box, opts.empty || 'Nothing in this period.');
        const hi = Math.max(0, ...rows.map((r) => Math.max(r.up || 0, r.dot || 0)));
        const lo = Math.min(0, ...rows.map((r) => Math.min(-(r.down || 0), r.dot || 0)));
        const f = frame(box, opts.first ?? rows[0].day, opts.last ?? rows[rows.length - 1].day, Object.assign({lo, hi}, opts));
        const w = Math.max(1, Math.min(22, f.slot - 2));
        rows.forEach((r) => {
            const b = r.day === opts.partial ? partialBar : bar;
            if (r.up) b(f.svg, f.x(r.day), w, f.y(0), f.y(r.up), opts.upColour || COLOURS[0]);
            if (r.down) b(f.svg, f.x(r.day), w, f.y(0), f.y(-r.down), opts.downColour || COLOURS[1]);
        });
        if (opts.dots) rows.forEach((r) => {
            if (r.dot === null || r.dot === undefined || (!r.up && !r.down && !r.dot)) return;
            el('circle', {cx: f.x(r.day), cy: f.y(r.dot), r: f.slot > 9 ? 4 : 2.5, fill: '#e9e9ef', stroke: '#24242c', 'stroke-width': 1.5}, f.svg);
        });
        const byDay = Object.fromEntries(rows.map((r) => [r.day, r]));
        dayHover(box, f, (day) => {
            const lines = opts.tip(byDay[day], day);
            if (day === opts.partial && lines.length && typeof lines[0] === 'string') lines[0] += SO_FAR;
            return lines;
        });
        return f;
    }

    /** Lines a day: series [{name, colour, points: [{day, v}], dots?: true, dash?}] */
    function lines(box, series, opts) {
        const all = series.flatMap((s) => s.points.map((p) => p.v)).filter((v) => v !== null && v !== undefined);
        if (!all.length) return empty(box, opts.empty || 'Nothing in this period.');
        let lo = Math.min(...all), hi = Math.max(...all);
        if (opts.fromZero !== false && lo >= 0) lo = 0;
        if (hi === lo) hi = lo + 1;
        const f = frame(box, opts.first, opts.last, Object.assign({lo, hi}, opts));
        series.forEach((s) => {
            let path = '', prev = false;
            s.points.forEach((p) => {
                if (p.v === null || p.v === undefined) { prev = false; return; }
                path += (prev ? 'L' : 'M') + f.x(p.day) + ',' + f.y(p.v); prev = true;
            });
            if (!s.dotsOnly) el('path', {d: path, fill: 'none', stroke: s.colour, 'stroke-width': 2, 'stroke-linejoin': 'round',
                'stroke-dasharray': s.dash || 'none'}, f.svg);
            if (s.dots || s.dotsOnly) s.points.forEach((p) => {
                if (p.v === null || p.v === undefined) return;
                el('circle', {cx: f.x(p.day), cy: f.y(p.v), r: s.r || 3, fill: s.colour, stroke: '#24242c', 'stroke-width': 1}, f.svg);
            });
        });
        dayHover(box, f, (day) => {
            const out = ['Game day ' + day];
            series.forEach((s) => {
                const ps = s.points.filter((q) => q.day === day && q.v !== null && q.v !== undefined);
                if (ps.length) ps.forEach((p) => out.push({colour: s.colour, text: `${s.name}: ${p.label || num(p.v)}`}));
                else if (!s.dotsOnly) out.push({colour: s.colour, text: `${s.name}: no figure`});
            });
            return out;
        });
        return f;
    }

    /** A step line a day: points [{day, v}] */
    function step(box, points, opts) {
        if (!points.length) return empty(box, opts.empty || 'Nothing in this period.');
        const f = frame(box, opts.first, opts.last, Object.assign({lo: 0, hi: Math.max(1, ...points.map((p) => p.v)), n: 3, minStep: 1}, opts));
        let path = '';
        points.forEach((p, i) => {
            const x0 = f.x(p.day) - f.slot / 2, x1 = f.x(p.day) + f.slot / 2;
            path += (i ? 'L' + x0 + ',' + f.y(p.v) : 'M' + x0 + ',' + f.y(p.v)) + 'L' + x1 + ',' + f.y(p.v);
        });
        el('path', {d: path, fill: 'none', stroke: opts.colour || COLOURS[0], 'stroke-width': 2}, f.svg);
        const byDay = Object.fromEntries(points.map((p) => [p.day, p]));
        dayHover(box, f, (day) => ['Game day ' + day, byDay[day] ? `${opts.name}: ${num(byDay[day].v)}` : 'no figure']);
        return f;
    }

    /** Horizontal bars: rows [{label, href?, segs: [v...]}], segs [{name, colour}] */
    function hbars(box, rows, segs, opts) {
        if (!rows.length) return empty(box, opts.empty || 'Nothing yet.');
        const rh = 26, H = rows.length * rh + 22;
        const {W, svg} = svgIn(box, H);
        const probe = el('text', {}, svg);
        let lw = 40;
        rows.forEach((r) => { probe.textContent = r.label; lw = Math.max(lw, probe.getComputedTextLength()); });
        probe.remove();
        lw = Math.min(lw + 10, W * 0.38);
        const max = Math.max(1, ...rows.map((r) => r.segs.reduce((a, b) => a + b, 0)));
        const tk = ticks(0, max, W < 480 ? 2 : 4), top = tk[tk.length - 1];
        const X = (v) => lw + v / top * (W - lw - 14);
        tk.forEach((t) => {
            el('line', {x1: X(t), x2: X(t), y1: 0, y2: H - 18, stroke: GRID}, svg);
            el('text', {x: X(t), y: H - 4, 'text-anchor': 'middle'}, svg).textContent = short(t);
        });
        rows.forEach((r, i) => {
            const y = i * rh + 4;
            const lab = el('text', {x: lw - 8, y: y + 13, 'text-anchor': 'end', class: 'ex-strong'}, svg);
            let name = r.label;
            lab.textContent = name;
            while (lab.getComputedTextLength() > lw - 12 && name.length > 3) { name = name.slice(0, -2); lab.textContent = name + '…'; }
            let acc = 0;
            r.segs.forEach((v, j) => {
                if (v <= 0) return;
                const x0 = X(acc) + (acc > 0 ? 1 : 0), x1 = X(acc + v);
                el('rect', {x: x0, y: y + 2, width: Math.max(0.75, x1 - x0), height: 16, rx: 2, fill: segs[j].colour}, svg);
                acc += v;
            });
            const hit = el('rect', {x: 0, y: y, width: W, height: rh - 2, fill: 'transparent'}, svg);
            tip(hit, [r.label].concat(r.segs.map((v, j) => ({colour: segs[j].colour, text: `${segs[j].name}: ${num(v)}`})),
                opts.total ? ['Total: ' + num(r.segs.reduce((a, b) => a + b, 0))] : []));
        });
        return svg;
    }

    /** A dot plot a good: rows [{label, a, b}] with a (filled) and b (ring) on one price scale */
    function dots(box, title, rows, opts) {
        const rh = 22, H = rows.length * rh + 30;
        const {W, svg} = svgIn(box, H);
        if (!svg.getAttribute('aria-label')) svg.setAttribute('aria-label', title + ': ' + opts.aName + ' and ' + opts.bName + ' by place');   // #716
        el('text', {x: 0, y: 12, class: 'ex-strong', 'font-weight': 600}, svg).textContent = title;
        const vals = rows.flatMap((r) => [r.a, r.b]).filter((v) => v !== null && v !== undefined);
        const tk = ticks(0, Math.max(1, ...vals), 3), top = tk[tk.length - 1], lw = 70;
        const X = (v) => lw + v / top * (W - lw - 12);
        tk.forEach((t) => el('text', {x: X(t), y: H - 2, 'text-anchor': 'middle'}, svg).textContent = short(t));
        rows.forEach((r, i) => {
            const y = 26 + i * rh + 6;
            el('text', {x: lw - 8, y: y, dy: '0.32em', 'text-anchor': 'end'}, svg).textContent = r.label.length > 10 ? r.label.slice(0, 9) + '…' : r.label;
            el('line', {x1: lw, x2: W - 12, y1: y, y2: y, stroke: GRID}, svg);
            if (r.a != null && r.b != null) el('line', {x1: X(Math.min(r.a, r.b)), x2: X(Math.max(r.a, r.b)), y1: y, y2: y,
                stroke: r.b > r.a ? COLOURS[3] : GREY, 'stroke-width': 3}, svg);
            const lines = [`${title} at ${r.label}`, {colour: opts.aColour, text: `${opts.aName}: ${r.a ?? 'none'}`},
                {colour: opts.bColour, text: `${opts.bName}: ${r.b ?? 'none'}`}];
            if (r.a != null && r.b != null && r.b > r.a) lines.push('Crossed: a buyer bids more than a seller asks');
            if (r.a != null) tip(el('circle', {cx: X(r.a), cy: y, r: 5, fill: opts.aColour, stroke: '#24242c', 'stroke-width': 2}, svg), lines);
            if (r.b != null) tip(el('circle', {cx: X(r.b), cy: y, r: 5, fill: '#24242c', stroke: opts.bColour, 'stroke-width': 2.5}, svg), lines);
            tip(el('rect', {x: 0, y: y - rh / 2, width: lw, height: rh, fill: 'transparent'}, svg), lines);
        });
    }

    /** A sparkline of bars up and down (an SVG element), with a tooltip a bar */
    function spark(values, opts) {
        opts = opts || {};
        const w = opts.width || 120, h = opts.height || 26, n = Math.max(1, values.length), bw = w / n;
        const svg = el('svg', {viewBox: `0 0 ${w} ${h}`, width: w, height: h, role: 'img', 'aria-label': opts.label || ''});
        svg.style.display = 'inline-block'; svg.style.verticalAlign = 'middle'; svg.style.width = w + 'px';
        // Bars from zero; a line (a price) fitted to its own range
        const lo = opts.line ? Math.min(...values) : Math.min(0, ...values), hi = opts.line ? Math.max(...values) : Math.max(0, ...values);
        const range = Math.max(opts.line ? 1e-9 : 1, hi - lo);
        const Y = (v) => hi === lo ? h / 2 : 2 + (hi - v) / range * (h - 4);
        if (!opts.line) el('line', {x1: 0, x2: w, y1: Y(0), y2: Y(0), stroke: GREY}, svg);
        values.forEach((v, i) => {
            if (!opts.line) el('rect', {x: i * bw + 0.5, y: Math.min(Y(0), Y(v)), width: Math.max(1, bw - 1), height: Math.max(0.75, Math.abs(Y(v) - Y(0))),
                fill: opts.line ? 'transparent' : (v >= 0 ? COLOURS[0] : NEG)}, svg);
            if (opts.tip) tip(el('rect', {x: i * bw, y: 0, width: bw, height: h, fill: 'transparent'}, svg), opts.tip(v, i));
        });
        if (opts.line) {
            const pts = values.map((v, i) => (i * bw + bw / 2) + ',' + Y(v)).join(' ');
            svg.insertBefore(el('polyline', {points: pts, fill: 'none', stroke: COLOURS[0], 'stroke-width': 1.5}), svg.firstChild);
            const lastV = values[values.length - 1];
            el('circle', {cx: (values.length - 1) * bw + bw / 2, cy: Y(lastV), r: 2.5, fill: COLOURS[0]}, svg);
        }
        return svg;
    }

    // Charts redraw when their box changes width (a phone turned, a window resized)
    function onResize(box, draw) {
        let width = 0;
        new ResizeObserver(() => { const w = box.clientWidth; if (w && w !== width) { width = w; draw(); } }).observe(box);
    }

    function legend(box, items) {
        box.replaceChildren();
        items.forEach((it) => {
            const s = document.createElement('span'), i = document.createElement('i');
            if (it.kind) i.className = 'ex-' + it.kind;
            if (it.kind === 'ring') i.style.borderColor = it.colour; else i.style.background = it.colour;
            s.appendChild(i); s.appendChild(document.createTextNode(it.name)); box.appendChild(s);
        });
    }

    return {COLOURS, GREY, NEG, num, short, el, tip, showTip, hideTip, ticks, frame, stacked, diverging, lines, step,
        hbars, dots, spark, onResize, legend, empty};
})();
</script>
@endonce
