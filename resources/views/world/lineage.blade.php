@extends('layouts.app')
@section('pageTitle', 'Lineage')
@push('styles')
    <style>
        /* The lineage map (#618): lanes are companies in family order, time runs left to right */
        #lineage-page { --ink: #e6e6e6; --muted: #9aa0ac; --rule: #3a3a48; --sheet: #23232e; --lane: rgba(255,255,255,0.035);
            --signal: #f06a43; --cool: #5b8fd6; --cyan: #4fc3f7; --ochre: #d7a845; }
        #lineage-page .controls { display: flex; flex-wrap: wrap; gap: 8px 18px; align-items: center; margin: .6em 0; white-space: normal; }
        #lineage-page .controls input[type=search] { min-width: 0; width: 16em; max-width: 100%; }
        #lineage-page .controls label { display: inline-flex; gap: 6px; align-items: center; margin: 0; cursor: pointer; }
        #lineage-page .legend { display: flex; flex-wrap: wrap; gap: 4px 18px; font-size: .85em; color: var(--muted); white-space: normal; margin-bottom: .6em; }
        #lineage-page .legend i { display: inline-block; width: 22px; border-top: 2px solid; vertical-align: middle; margin-right: 6px; }
        #lineage-page .ramp { display: inline-block; width: 64px; height: 10px; vertical-align: -1px; margin-right: 6px; border: 1px solid var(--rule);
            background: linear-gradient(90deg, var(--cool), var(--sheet), var(--signal)); }
        #lineage-page .dot { display: inline-block; width: 10px; height: 10px; border-radius: 50%; border: 1.5px solid var(--ink); vertical-align: -1px; margin-right: 6px; }
        #lineage-page .main { display: grid; grid-template-columns: minmax(0, 1fr) 280px; gap: 16px; align-items: start; }
        @media (max-width: 860px) { #lineage-page .main { grid-template-columns: minmax(0, 1fr); } }
        #lineage-page .plate { border: 1px solid var(--rule); background: var(--sheet); overflow: auto; max-height: 76vh; }
        #lineage-page .rows { display: flex; width: max-content; }
        #lineage-page svg { display: block; flex: none; }
        #lineage-page .names { position: sticky; left: 0; z-index: 1; background: var(--sheet); border-right: 1px solid var(--rule); }
        #lineage-page svg text { fill: var(--ink); }
        #lineage-page .lanelabel { font: 11px ui-monospace, monospace; }
        #lineage-page .lanelabel.ref { fill: var(--ochre); }
        #lineage-page .names a text { text-decoration: underline; text-decoration-color: var(--rule); }
        #lineage-page .names a:hover text, #lineage-page .names a:focus text { fill: var(--signal); }
        #lineage-page .tick { font: 10px ui-monospace, monospace; fill: var(--muted) !important; }
        #lineage-page .edge { fill: none; }
        #lineage-page .node { cursor: pointer; }
        #lineage-page .node circle { stroke: var(--ink); stroke-width: 1.3; }
        #lineage-page .node:focus-visible circle, #lineage-page .sel circle { stroke: var(--signal); stroke-width: 3; }
        #lineage-page .dim { opacity: .12; }
        #lineage-page .panel { border: 1px solid var(--rule); background: var(--sheet); padding: 12px 14px; white-space: normal; position: sticky; top: 0; }
        #lineage-page .panel h3 { margin: 0 0 .2em; font-size: 1.3em; }
        #lineage-page .panel h4 { margin: .8em 0 .2em; font-size: .8em; letter-spacing: .08em; text-transform: uppercase; color: var(--muted); }
        #lineage-page .panel ul { list-style: none; margin: 0; padding: 0; }
        #lineage-page .panel li { display: flex; justify-content: space-between; gap: 8px; }
        #lineage-page .panel .num { font-variant-numeric: tabular-nums; white-space: nowrap; }
        #lineage-page .panel button.link { background: none; border: 0; padding: 0; color: var(--cyan); text-align: left; cursor: pointer; text-decoration: underline; }
        #lineage-page .bar { height: 8px; border: 1px solid var(--rule); position: relative; margin: 3px 0; }
        #lineage-page .bar span { position: absolute; inset: 0 auto 0 0; background: var(--signal); }
    </style>
@endpush
@section('content')
    {{-- The lineage map (#618, from #613 and #616): every release and push, with the earlier code each carries --}}
    <x-page-header :title="'Lineage'" />
    <section class="section section-xs section-first text-md-left">
        <div class="container" id="lineage-page">
            <div style="white-space: normal">
                Every release and code push, with the starter and example scripts as roots. An edge runs from older code to
                newer code that carries it, as wide as the share of the newer code it explains; a revision can have several
                parents when it borrows from more than one. Each company is a lane, next to the family its release came from,
                and columns run in time order. Colour each company by its net wealth rank when it was a given age
                (<a href="{{ url('leaderboard') }}">as the leaderboard's At age board</a>), or each revision by how much of its code is new.
                How it's measured is under <b>Lineage</b> on each company's page.
            </div>
            <div class="controls">
                <input type="search" id="find" placeholder="Find a company" aria-label="Find a company">
                <label for="onlymulti"><input type="checkbox" id="onlymulti"> Only revisions with several parents</label>
                <label for="hideown"><input type="checkbox" id="hideown"> Hide edges within a company</label>
            </div>
            <div class="controls">
                Colour by
                <label for="mode-heat"><input type="radio" name="mode" id="mode-heat" value="heat" checked> Net wealth at age</label>
                <label for="mode-new"><input type="radio" name="mode" id="mode-new" value="new"> New code</label>
                <label for="age" class="agepick">at <input type="range" id="age" min="0" max="0" step="1"> <output id="age-out"></output></label>
            </div>
            <div class="legend">
                <span><i style="border-color: var(--ink); opacity: .5"></i>within a company</span>
                <span><i style="border-color: var(--cyan)"></i>from another company</span>
                <span><i style="border-color: var(--ochre)"></i>from a starter or example</span>
                <span id="legend-heat"><span class="ramp"></span>rank at that age, bottom to top ·
                    <span class="dot" style="border-style: dashed"></span>not that old</span>
                <span id="legend-new" hidden><span class="dot" style="background: var(--signal)"></span>fill: share of new code</span>
            </div>
            <div class="main">
                <div class="plate" id="plate"></div>
                <aside class="panel" id="panel" aria-live="polite"></aside>
            </div>
            @if(!$graph['nodes'])
                <p>The lineage is still being worked out: it fills in within a few minutes of a release or push.</p>
            @endif
        </div>
    </section>
    <script>
    // Started once the page has loaded, like the charts: Vue mounts the page first and would replace what this draws
    document.addEventListener('DOMContentLoaded', () => {
        const DATA = @json($graph);
        const nodes = DATA.nodes, by = Object.fromEntries(nodes.map(n => [n.id, n])), AGES = DATA.ages;
        if (!nodes.length) return;
        const kids = {};
        nodes.forEach(n => n.parents.forEach(([p, w]) => (kids[p] ||= []).push([n.id, w])));
        const esc = s => String(s).replace(/[&<>"]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]));

        // Columns: roots first, then revisions in time order (equal times share a column)
        const cols = [];
        nodes.filter(n => n.day !== null).sort((a, b) => a.day - b.day).forEach(n => {
            const last = cols[cols.length - 1];
            if (!last || n.day - last.day > 0.004) cols.push({ day: n.day });
            n.col = cols.length;
        });
        nodes.filter(n => n.day === null).forEach(n => n.col = 0);
        const NAMES_W = 150, COL_W = 13, ROW_H = 13, TOP = 34, PAD = 18;
        const W = (cols.length + 2) * COL_W + PAD, H = TOP + DATA.lanes.length * ROW_H + PAD;
        const X = n => (n.col + (n.col ? 1.5 : 0.5)) * COL_W, Y = n => TOP + (n.lane + 0.5) * ROW_H;

        const NS = 'http://www.w3.org/2000/svg';
        const el = (t, a, p) => { const e = document.createElementNS(NS, t); for (const k in a) e.setAttribute(k, a[k]); p && p.appendChild(e); return e; };
        const rows = document.createElement('div'); rows.className = 'rows'; document.getElementById('plate').appendChild(rows);
        const names = el('svg', { width: NAMES_W, height: H, class: 'names' }, rows);
        const svg = el('svg', { width: W, height: H, role: 'img', 'aria-label': 'Code lineage of every company' }, rows);
        const gLanes = el('g', {}, svg), gTicks = el('g', {}, svg), gEdges = el('g', {}, svg), gNodes = el('g', {}, svg);
        el('text', { x: 8, y: TOP - 12, class: 'tick' }, names).textContent = 'company';
        DATA.lanes.forEach((lane, i) => {
            if (i % 2 === 0) {
                el('rect', { x: 0, y: TOP + i * ROW_H, width: W, height: ROW_H, fill: 'var(--lane)' }, gLanes);
                el('rect', { x: 0, y: TOP + i * ROW_H, width: NAMES_W, height: ROW_H, fill: 'var(--lane)' }, names);
            }
            const a = el('a', { href: lane.url }, names);
            el('title', {}, a).textContent = lane.example ? 'The ' + lane.example + ' script' : 'Open ' + lane.name;
            el('text', { x: 8, y: TOP + (i + 0.5) * ROW_H + 3.5, class: 'lanelabel' + (lane.example ? ' ref' : '') }, a)
                .textContent = lane.name.length > 20 ? lane.name.slice(0, 19) + '…' : lane.name;
        });
        let lastBin = null;
        cols.forEach((c, i) => {
            const bin = Math.floor(c.day / 50) * 50;
            if (bin === lastBin) return;
            const x = (i + 2) * COL_W;
            el('line', { x1: x, x2: x, y1: TOP - 8, y2: H - PAD + 4, stroke: 'var(--rule)', 'stroke-dasharray': '2 3' }, gTicks);
            el('text', { x: x + 3, y: TOP - 12, class: 'tick' }, gTicks).textContent = 'day ' + Math.round(c.day);
            lastBin = bin;
        });
        el('text', { x: 2, y: TOP - 12, class: 'tick' }, gTicks).textContent = 'roots';

        const edges = [];
        nodes.forEach(n => n.parents.forEach(([pid, w]) => {
            const p = by[pid], x1 = X(p), y1 = Y(p), x2 = X(n), y2 = Y(n), mx = (x1 + x2) / 2;
            const kind = p.example ? 'ref' : p.company_id === n.company_id ? 'own' : 'cross';
            const e = el('path', { class: 'edge', d: y1 === y2 ? `M${x1},${y1} L${x2},${y2}` : `M${x1},${y1} C${mx},${y1} ${mx},${y2} ${x2},${y2}`,
                stroke: kind === 'ref' ? 'var(--ochre)' : kind === 'cross' ? 'var(--cyan)' : 'var(--ink)',
                'stroke-width': (0.6 + 2.6 * w).toFixed(2), 'stroke-opacity': kind === 'own' ? 0.45 : 0.75 }, gEdges);
            edges.push({ e, from: pid, to: n.id, kind, multi: n.parents.length > 1 });
        }));
        const nodeEls = {};
        nodes.forEach(n => {
            const g = el('g', { class: 'node', tabindex: 0, role: 'button', 'aria-label': n.label }, gNodes);
            const r = 2.6 + Math.min(3.2, Math.sqrt(n.size) / 9);
            n.ring = el('circle', { cx: X(n), cy: Y(n), r, fill: 'var(--sheet)' }, g);
            n.core = el('circle', { cx: X(n), cy: Y(n), r: Math.max(1.2, r * Math.sqrt(n.new)), fill: 'var(--signal)', stroke: 'none' }, g);
            n.tip = el('title', {}, g);
            g.addEventListener('click', () => select(n.id));
            g.addEventListener('keydown', ev => { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); select(n.id); } });
            nodeEls[n.id] = g;
        });

        // Colour: net wealth rank at an age (Leaderboard::atAges), or new code
        const ageInput = document.getElementById('age');
        ageInput.max = AGES.length - 1; ageInput.value = Math.max(0, AGES.indexOf(30));
        const css = v => getComputedStyle(document.getElementById('lineage-page')).getPropertyValue(v).trim();
        const hex = v => { const c = css(v).replace('#', ''); return [0, 2, 4].map(i => parseInt(c.slice(i, i + 2), 16)); };
        const mix = (a, b, f) => `rgb(${a.map((x, i) => Math.round(x + (b[i] - x) * f)).join(',')})`;
        const heat = p => p < 0.5 ? mix(hex('--cool'), hex('--sheet'), p / 0.5) : mix(hex('--sheet'), hex('--signal'), (p - 0.5) / 0.5);
        const mode = () => document.querySelector('#lineage-page input[name=mode]:checked').value;
        const at = () => +ageInput.value;
        const co = n => n.company_id === null ? null : DATA.companies[n.company_id] || null;
        const fmt = v => (v < 0 ? '−' : '') + Math.abs(v).toLocaleString('en-US');
        const wealthAt = (c, i) => c.wealth[i] === null ? null : `${c.gap[i] > 1.01 ? '~' : ''}${fmt(c.wealth[i])}, ${c.rank[i][0]} of ${c.rank[i][1]}`;
        function paint() {
            const isHeat = mode() === 'heat', i = at();
            document.getElementById('age-out').textContent = AGES[i] + ' game days';
            ageInput.disabled = !isHeat;
            document.getElementById('legend-heat').hidden = !isHeat; document.getElementById('legend-new').hidden = isHeat;
            nodes.forEach(n => {
                const c = co(n), base = `${n.label}${n.day !== null ? ' · day ' + n.day.toFixed(1) : ''} · ${Math.round(n.new * 100)}% new`;
                n.ring.removeAttribute('stroke-dasharray');
                if (!isHeat) {
                    n.ring.setAttribute('fill', 'var(--sheet)'); n.core.setAttribute('display', n.new > 0.02 ? '' : 'none'); n.tip.textContent = base; return;
                }
                n.core.setAttribute('display', 'none');
                if (!c || c.rank[i] === null) {
                    n.ring.setAttribute('fill', c ? 'none' : 'var(--sheet)');
                    if (c) n.ring.setAttribute('stroke-dasharray', '1.5 1.5');
                    n.tip.textContent = base + (c ? ` · not ${AGES[i]} days old` : '');
                } else {
                    const [rank, of] = c.rank[i];
                    n.ring.setAttribute('fill', heat(of > 1 ? (of - rank) / (of - 1) : 0.5));
                    n.tip.textContent = base + ` · at ${AGES[i]} days: ${wealthAt(c, i)}`;
                }
            });
            selected ? select(selected) : overview();
        }

        const walk = (start, next) => { const seen = new Set([start]), q = [start]; while (q.length) for (const [k] of next(q.pop()) || []) if (!seen.has(k)) { seen.add(k); q.push(k); } return seen; };
        let selected = null;
        const laneLabels = () => names.querySelectorAll('text.lanelabel');
        const row = ([id, w]) => `<li><button class="link" data-id="${id}">${esc(by[id].label)}</button><span class="num">${Math.round(w * 100)}%</span></li>`;
        function bind() {
            document.querySelectorAll('#panel button[data-id]').forEach(b => b.addEventListener('click', () => { select(+b.dataset.id); reveal(+b.dataset.id); }));
            const clear = document.getElementById('clear');
            clear && clear.addEventListener('click', () => { selected = null; overview(); });
        }
        function select(id) {
            selected = id;
            const n = by[id], up = walk(id, x => by[x].parents), down = walk(id, x => kids[x]), lit = new Set([...up, ...down]);
            for (const k in nodeEls) { nodeEls[k].classList.toggle('dim', !lit.has(+k)); nodeEls[k].classList.toggle('sel', +k === id); }
            edges.forEach(o => o.e.classList.toggle('dim', !(lit.has(o.from) && lit.has(o.to) && (up.has(o.to) || down.has(o.from)))));
            const lanes = new Set([...lit].map(x => by[x].lane));
            laneLabels().forEach((t, i) => t.classList.toggle('dim', !lanes.has(i)));
            filter();
            const c = co(n), i = at(), ch = (kids[id] || []).slice().sort((a, b) => b[1] - a[1]);
            document.getElementById('panel').innerHTML =
                `<div class="text-muted">${n.example ? 'Starter or example' : n.push ? 'Code push' : 'Release'}</div>
                 <h3>${esc(n.label)}</h3>
                 <div class="text-muted">${n.day !== null ? 'game day ' + n.day.toFixed(2) : 'a root'} ·
                   ${n.company_id ? `<a href="{{ url('companies') }}/${n.company_id}">company page</a>` : `<a href="${esc(DATA.lanes[n.lane].url)}">the script</a>`}</div>
                 <h4>New code</h4><div class="bar"><span style="width:${(n.new * 100).toFixed(1)}%"></span></div>
                 <div>${Math.round(n.new * 100)}% not explained by any earlier code</div>
                 <h4>Parents · share of its code</h4><ul>${n.parents.length ? n.parents.map(row).join('') : '<li>None: a root</li>'}</ul>
                 <h4>Children · ${ch.length}</h4><ul>${ch.slice(0, 12).map(row).join('') || '<li>None yet</li>'}${ch.length > 12 ? `<li>and ${ch.length - 12} more</li>` : ''}</ul>
                 ${c ? `<h4>Company's net wealth at ${AGES[i]} game days</h4><div class="num">${wealthAt(c, i) || 'not that old ' + (c.wealth.some(w => w !== null) ? 'yet' : '')}</div>` : ''}
                 <h4>Lineage</h4><div class="num">${up.size - 1} ancestors · ${down.size - 1} descendants</div>
                 <p style="margin-top: .8em"><button class="btn btn-sm btn-secondary" id="clear">Clear selection</button></p>`;
            bind();
        }
        function overview() {
            for (const k in nodeEls) nodeEls[k].classList.remove('dim', 'sel');
            edges.forEach(o => o.e.classList.remove('dim'));
            laneLabels().forEach(t => t.classList.remove('dim'));
            filter();
            const i = at(), release = cid => nodes.find(n => n.company_id === +cid && n.push === 0);
            const top = Object.entries(DATA.companies).filter(([cid, c]) => c.rank[i] !== null && release(cid))
                .sort((a, b) => a[1].rank[i][0] - b[1].rank[i][0]).slice(0, 10);
            document.getElementById('panel').innerHTML =
                `<div class="text-muted">Nothing selected</div><h3>Richest at ${AGES[i]} game days</h3>
                 <div class="text-muted">Select a revision to trace its lineage.</div>
                 <ul style="margin-top: .5em">${top.map(([cid, c]) => `<li><button class="link" data-id="${release(cid).id}">${c.rank[i][0]}. ${esc(release(cid).label)}</button><span class="num">${c.gap[i] > 1.01 ? '~' : ''}${fmt(c.wealth[i])}</span></li>`).join('')}</ul>`;
            bind();
        }
        function reveal(id, smooth = true) {
            const plate = document.getElementById('plate'), n = by[id];
            const left = Math.max(0, X(n) - (plate.clientWidth - NAMES_W) / 2), top = Math.max(0, Y(n) - plate.clientHeight / 2);
            if (smooth) plate.scrollTo({ left, top, behavior: 'smooth' }); else { plate.scrollLeft = left; plate.scrollTop = top; }
        }
        function filter() {
            const multi = document.getElementById('onlymulti').checked, own = document.getElementById('hideown').checked;
            edges.forEach(o => { o.e.style.display = (multi && !o.multi) || (own && o.kind === 'own') ? 'none' : ''; });
        }
        ageInput.addEventListener('input', paint);
        document.querySelectorAll('#lineage-page input[name=mode]').forEach(r => r.addEventListener('change', paint));
        document.getElementById('onlymulti').addEventListener('change', filter);
        document.getElementById('hideown').addEventListener('change', filter);
        document.getElementById('find').addEventListener('input', ev => {
            const q = ev.target.value.trim().toLowerCase();
            if (!q) return;
            const hit = nodes.find(n => n.label.toLowerCase() === q) || nodes.find(n => n.label.toLowerCase().startsWith(q)) || nodes.find(n => n.label.toLowerCase().includes(q));
            if (hit) { select(hit.id); reveal(hit.id); }
        });
        document.addEventListener('keydown', ev => { if (ev.key === 'Escape' && selected) { selected = null; overview(); } });
        // ?company=N opens on that company's release
        const start = new URLSearchParams(location.search).get('company');
        paint();
        const first = start && nodes.find(n => n.company_id === +start && n.push === 0);
        if (first) { select(first.id); reveal(first.id, false); }
        else { const p = document.getElementById('plate'); p.scrollLeft = p.scrollWidth; }
    });
    </script>
@endsection
