{{--
    A company's earnings by game day (#600): what its sales brought in after
    tax (bars up), what it paid for goods and equipment (bars down), and the
    day's net (a line). From /api/v1/companies/{id}/earnings. Takes:
      $earningsCompany  the company's ID
      $earningsDays     the period shown first
--}}
@include('partials.chart-styles')
<div class="mh">
    <div class="mh-card">
        <h3>Earnings</h3>
        <p class="mh-note">Each game day: the bars above the line are what the company's sales brought in after sales tax; the
            bars below it are what it paid for goods and equipment. The line is the difference: its profit, from which,
            once any earlier losses are made up, other holders of its shares are paid their dividends each day. Processor
            tax and code push fees aren't trades and aren't shown.</p>
        <div class="mh-controls">
            <label for="ce-days">Period
                <select id="ce-days">
                    @foreach([10, 30, 60, 365] as $d)
                        <option value="{{ $d }}" @selected($earningsDays === $d)>Last {{ $d }} game days</option>
                    @endforeach
                </select>
            </label>
        </div>
        <div class="mh-legend" id="ce-legend">
            <span><i class="mh-bar" style="background: #3987e5; opacity: .85"></i>Sales after tax</span>
            <span><i class="mh-bar" style="background: #d95926; opacity: .85"></i>Spent on goods</span>
            <span><i style="background: var(--mh-text)"></i>Net</span>
        </div>
        <div class="mh-chart" id="ce-chart" tabindex="0" aria-label="Earnings by game day"></div>
        <p class="mh-note" id="ce-total"></p>
    </div>
</div>

<script>
// Started once the page has loaded, like the price charts: Vue mounts first
document.addEventListener('DOMContentLoaded', function () {
    const SVG = 'http://www.w3.org/2000/svg', IN = '#3987e5', OUT = '#d95926';
    const $ = (id) => document.getElementById(id);
    const box = $('ce-chart'), num = (v) => Math.round(v).toLocaleString('en');
    let rows = [];

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
    function empty(text) {
        box.replaceChildren();
        const d = document.createElement('div'); d.className = 'mh-empty'; d.textContent = text; box.appendChild(d);
    }

    function draw() {
        if (!rows.length || !rows.some((r) => r.sales || r.purchases)) {
            empty('No sales or purchases in this period.'); $('ce-total').textContent = ''; return;
        }
        box.replaceChildren();
        const W = Math.max(280, box.clientWidth), H = 300, T = 12, B = 24, R = 10;
        const svg = el('svg', {width: '100%', viewBox: `0 0 ${W} ${H}`, role: 'img'}, box);
        // Up to the biggest day's sales, down to its biggest spending, on one scale
        const up = niceMax(Math.max(...rows.map((r) => Math.max(r.sales, r.net)))),
              down = niceMax(Math.max(1, ...rows.map((r) => Math.max(r.purchases, -r.net))));
        const probe = el('text', {}, svg); probe.textContent = '-' + num(Math.max(up, down));
        const L = Math.ceil(probe.getComputedTextLength()) + 10; probe.remove();
        const span = rows.length, first = rows[0].day;
        const x = (i) => L + (span > 1 ? i / (span - 1) : 0.5) * (W - L - R);
        const y = (v) => T + (up - v) / (up + down) * (H - T - B);
        [up, up / 2, 0, -down / 2, -down].forEach((t) => {
            el('line', {x1: L, x2: W - R, y1: y(t), y2: y(t), stroke: t ? 'var(--mh-grid)' : 'var(--mh-axis)'}, svg);
            el('text', {x: L - 6, y: y(t), dy: '0.32em', 'text-anchor': 'end'}, svg).textContent = num(t);
        });
        el('text', {x: L - 6, y: 4, 'text-anchor': 'end'}, svg).textContent = 'cr';
        const step = Math.max(1, Math.ceil(span / (W < 480 ? 5 : 10)));
        let clear = 0;
        for (let i = 0; i < span; i += step) {
            const lab = el('text', {x: x(i), y: H - 6, 'text-anchor': i === 0 ? 'start' : 'middle'}, svg);
            lab.textContent = i === 0 ? 'Day ' + rows[i].day : rows[i].day;
            const w = lab.getComputedTextLength(), left = i === 0 ? x(i) : x(i) - w / 2;
            if (left < clear) { lab.remove(); continue; }
            clear = left + w + 8;
        }
        const bw = Math.max(1, Math.min(28, (W - L - R) / span * 0.7));
        rows.forEach((r, i) => {
            if (r.sales > 0) el('rect', {x: x(i) - bw / 2, width: bw, y: y(r.sales), height: y(0) - y(r.sales),
                rx: Math.min(2, bw / 2), fill: IN, 'fill-opacity': 0.85}, svg);
            if (r.purchases > 0) el('rect', {x: x(i) - bw / 2, width: bw, y: y(0), height: y(-r.purchases) - y(0),
                rx: Math.min(2, bw / 2), fill: OUT, 'fill-opacity': 0.85}, svg);
        });
        el('path', {d: rows.map((r, i) => (i ? 'L' : 'M') + x(i) + ',' + y(r.net)).join(''), fill: 'none',
            stroke: 'var(--mh-text)', 'stroke-width': 2, 'stroke-linejoin': 'round'}, svg);

        // Hover and arrow keys: the day's figures
        const tip = document.createElement('div'); tip.className = 'mh-tip'; tip.hidden = true; box.appendChild(tip);
        const rule = el('line', {y1: T, y2: H - B, stroke: 'var(--mh-axis)', visibility: 'hidden'}, svg);
        const mark = el('circle', {r: 4, fill: 'var(--mh-text)', stroke: 'var(--mh-panel)', 'stroke-width': 2, visibility: 'hidden'}, svg);
        const hit = el('rect', {x: L, y: 0, width: W - L - R, height: H, fill: 'transparent'}, svg);
        const show = (i) => {
            i = Math.max(0, Math.min(span - 1, i));
            const r = rows[i];
            rule.setAttribute('x1', x(i)); rule.setAttribute('x2', x(i)); rule.setAttribute('visibility', 'visible');
            mark.setAttribute('cx', x(i)); mark.setAttribute('cy', y(r.net)); mark.setAttribute('visibility', 'visible');
            tip.replaceChildren();
            [[null, 'Game day ' + r.day], [IN, `Sales after tax: ${num(r.sales)} (tax ${num(r.tax)})`],
             [OUT, `Spent on goods: ${num(r.purchases)}`], [null, `Net: ${num(r.net)} · ${r.trades} trades`],
             [null, `Dividends paid: ${num(r.dividends || 0)}`]].forEach(([c, t]) => {
                const d = document.createElement('div');
                if (c) { const sw = document.createElement('i'); sw.style.background = c; d.appendChild(sw); }
                d.appendChild(document.createTextNode(t)); tip.appendChild(d);
            });
            tip.hidden = false;
            const px = x(i) / W * box.clientWidth, w = tip.offsetWidth, room = box.clientWidth;
            tip.style.left = Math.max(0, Math.min(room - w, px + 12 + w > room ? px - w - 12 : px + 12)) + 'px';
            box.dataset.i = i;
        };
        hit.addEventListener('pointermove', (ev) => {
            const b = svg.getBoundingClientRect(), px = (ev.clientX - b.left) / b.width * W;
            show(Math.round((px - L) / (W - L - R) * (span - 1)));
        });
        hit.addEventListener('pointerleave', () => { tip.hidden = true; rule.setAttribute('visibility', 'hidden'); mark.setAttribute('visibility', 'hidden'); });
        box.onkeydown = (ev) => {
            if (ev.key !== 'ArrowLeft' && ev.key !== 'ArrowRight') return;
            ev.preventDefault();
            show((box.dataset.i === undefined ? span - 1 : Number(box.dataset.i)) + (ev.key === 'ArrowLeft' ? -1 : 1));
        };

        const s = rows.reduce((a, r) => ({sales: a.sales + r.sales, purchases: a.purchases + r.purchases,
            dividends: a.dividends + (r.dividends || 0)}), {sales: 0, purchases: 0, dividends: 0});
        $('ce-total').textContent = `Over these ${span} game days: sales after tax ${num(s.sales)}, spent on goods ${num(s.purchases)}, `
            + `net ${num(s.sales - s.purchases)} credits (${num((s.sales - s.purchases) / span)} a day); dividends paid to other holders ${num(s.dividends)}.`;
    }

    async function load() {
        box.setAttribute('aria-busy', 'true');
        try {
            const res = await fetch(`{{ url('api/v1/companies/' . (int) $earningsCompany . '/earnings') }}?days=${encodeURIComponent($('ce-days').value)}`);
            if (!res.ok) throw new Error(res.status);
            rows = await res.json();
        } catch (e) {
            rows = []; empty('The earnings did not load. Try again in a minute.'); return;
        } finally { box.removeAttribute('aria-busy'); }
        draw();
    }

    $('ce-days').addEventListener('change', load);
    let width = 0;
    new ResizeObserver(() => { const w = box.clientWidth; if (w !== width) { width = w; if (rows.length) draw(); } }).observe(box);
    load();
});
</script>
