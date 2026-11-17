@extends('layouts.app')
@section('pageTitle', 'Tech map')
@php
    $res = collect($map['resources'])->keyBy('id');
    $byStep = collect($map['resources'])->groupBy(fn ($r) => $r['step'] ?? 'none');
    $kinds = ['mine' => 'Mining', 'refine' => 'Refining', 'make' => 'Making', 'unpack' => 'Unpacking'];
    // One side of a formula, as text: "2 × Alloys (A1) + 1 × Gears (A1)"
    $side = fn ($pairs) => collect($pairs)->map(fn ($p) => $p[1] . ' × ' . ($res[$p[0]]['name'] ?? $p[0]))->implode(' + ');
@endphp
@push('styles')
    <style>
        /* The tech map (#804): resources in steps, from ores on the left to ships on the right */
        #tech-page { --ink: #e6e6e6; --muted: #9aa0ac; --rule: #3a3a48; --sheet: #23232e; --card: #1b1b24;
            --up: #f06a43; --down: #4fc3f7; --tool: #d7a845; }
        #tech-page .intro { white-space: normal; max-width: 60em; }
        #tech-page .controls { display: flex; flex-wrap: wrap; gap: 8px 18px; align-items: center; margin: .8em 0 .4em; white-space: normal; }
        #tech-page .controls input[type=search] { min-width: 0; width: 18em; max-width: 100%; }
        #tech-page .legend { display: flex; flex-wrap: wrap; gap: 4px 18px; font-size: .85em; color: var(--muted); white-space: normal; margin-bottom: .6em; }
        #tech-page .legend i { display: inline-block; width: 22px; border-top: 2px solid; vertical-align: middle; margin-right: 6px; }
        #tech-page .main { display: grid; grid-template-columns: minmax(0, 1fr) 360px; gap: 16px; align-items: start; }
        @media (max-width: 960px) { #tech-page .main { grid-template-columns: minmax(0, 1fr); } }
        #tech-page .plate { border: 1px solid var(--rule); background: var(--sheet); overflow: auto; max-height: 80vh; }
        #tech-page .cols { position: relative; display: flex; gap: 34px; padding: 10px 14px 14px; width: max-content; }
        #tech-page .cols > svg { position: absolute; inset: 0; pointer-events: none; overflow: visible; }
        #tech-page .col { position: relative; z-index: 1; display: flex; flex-direction: column; gap: 6px; width: 172px; }
        #tech-page .col h2 { font-size: .72rem; letter-spacing: .08em; text-transform: uppercase; color: var(--muted); margin: 0 0 4px; font-family: inherit; }
        #tech-page .card { display: flex; flex-direction: row; text-align: left; align-items: center; gap: 8px; padding: 4px 8px 4px 4px; border: 1px solid var(--rule); border-radius: 8px;
            background: var(--card); color: var(--ink); text-decoration: none; line-height: 1.15; font-size: .82rem; transition: opacity .15s; }
        #tech-page .card:hover, #tech-page .card:focus-visible { border-color: var(--ink); color: var(--ink); }
        #tech-page .card img, #tech-page .card .noimg { display: block; margin: 0; width: 38px; height: 38px; flex: none; border-radius: 6px; background: #000; object-fit: cover; }
        #tech-page .card small { display: block; color: var(--muted); font-size: .68rem; }
        #tech-page .card.sel { border-color: var(--ink); box-shadow: 0 0 0 2px var(--ink); }
        #tech-page .card.up { border-color: var(--up); }
        #tech-page .card.down { border-color: var(--down); }
        #tech-page .card.tool { border-color: var(--tool); border-style: dashed; }
        #tech-page.focus .card:not(.sel):not(.up):not(.down):not(.tool), #tech-page .card.miss { opacity: .22; }
        #tech-page .edge { fill: none; stroke-width: 1.6; opacity: .75; }
        #tech-page .edge.up { stroke: var(--up); }
        #tech-page .edge.down { stroke: var(--down); }
        #tech-page .edge.tool { stroke: var(--tool); stroke-dasharray: 4 4; }
        #tech-page .panel { border: 1px solid var(--rule); background: var(--sheet); padding: 12px 14px; white-space: normal; position: sticky; top: 0; max-height: 80vh; overflow: auto; }
        #tech-page .panel h3 { margin: 0; font-size: 1.25em; }
        #tech-page .panel h4 { margin: 1em 0 .3em; font-size: .78em; letter-spacing: .08em; text-transform: uppercase; color: var(--muted); }
        #tech-page .panel .head { display: flex; gap: 12px; align-items: center; }
        #tech-page .panel .head img { width: 96px; height: 96px; border-radius: 8px; background: #000; flex: none; }
        #tech-page .recipe { border: 1px solid var(--rule); border-radius: 8px; padding: 8px; margin-bottom: 8px; background: var(--card); }
        #tech-page .formula { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 6px; }
        #tech-page .formula .op { color: var(--muted); }
        #tech-page .chip { display: inline-flex; align-items: center; gap: 4px; padding: 1px 6px 1px 1px; border-radius: 6px; background: #2c2c3a;
            color: var(--ink); text-decoration: none; font-size: .8rem; white-space: nowrap; }
        #tech-page .chip img { width: 22px; height: 22px; border-radius: 4px; background: #000; }
        #tech-page .chip b { font-variant-numeric: tabular-nums; }
        #tech-page .chip.self { box-shadow: 0 0 0 1px var(--ink); }
        #tech-page .meta { font-size: .8rem; color: var(--muted); margin-top: 6px; }
        #tech-page .meta code { color: var(--ink); }
        #tech-page .panel ul { list-style: none; margin: 0; padding: 0; }
        #tech-page .panel li { display: flex; justify-content: space-between; align-items: center; gap: 8px; margin: 2px 0; font-size: .85rem; }
        #tech-page .panel .num { font-variant-numeric: tabular-nums; white-space: nowrap; }
        #tech-page table.list td, #tech-page table.list th { padding: 4px 8px; vertical-align: top; font-size: .85rem; }
        #tech-page table.list code { white-space: nowrap; }
    </style>
@endpush
@section('content')
    <x-page-header :title="'Tech map'">
        <li><a href="{{ url('resources') }}">Resources</a></li>
        <li class="active">Tech map</li>
    </x-page-header>
    <section class="section section-xs section-first text-md-left">
        <div class="container" id="tech-page">
            <p class="intro">
                Every manufacturing pathway in the game, worked out from the protocols themselves. Ores are dug on the left;
                each column to the right is one more step of making, up to ships. Pick a resource to see what it is made
                from, in what ratio, with what tool and which Lua call, and what it goes into. Lines show the whole chain:
                <span style="color: var(--up)">into it</span>, <span style="color: var(--down)">out of it</span>, and
                <span style="color: var(--tool)">the tools it needs or is</span>. A drone can read the same data with
                <code>recipe(r)</code>, <code>producers(r)</code> and <code>consumers(r)</code>, and a program with
                <a href="https://github.com/Darwin-River/Ex-Machinis/blob/main/documents/api.md">the API</a>'s <code>GET /recipes</code>.
            </p>
            <div class="controls">
                <input type="search" id="tm-find" list="tm-names" placeholder="Find a resource" aria-label="Find a resource">
                <datalist id="tm-names">
                    @foreach($map['resources'] as $r)<option value="{{ $r['name'] }}">@endforeach
                </datalist>
                <button type="button" class="button button-sm" id="tm-clear" hidden>Show everything</button>
            </div>
            <div class="legend">
                <span><i style="border-color: var(--up)"></i>goes into the chosen resource</span>
                <span><i style="border-color: var(--down)"></i>is made from it</span>
                <span><i style="border-color: var(--tool); border-top-style: dashed"></i>a tool (a module that is kept, not used up)</span>
            </div>
            <div class="main">
                <div class="plate" id="tm-plate">
                    <div class="cols" id="tm-cols">
                        @foreach($map['columns'] as $col)
                            <div class="col">
                                <h2>{{ $col['label'] }}</h2>
                                @foreach($byStep[$col['step'] ?? 'none'] as $r)
                                    <a class="card" href="{{ $r['url'] }}" data-id="{{ $r['id'] }}">
                                        @if($r['thumb'])<img src="{{ $r['thumb'] }}" alt="" width="38" height="38" loading="lazy">@else<span class="noimg"></span>@endif
                                        <span>{{ $r['name'] }}<small>{{ $r['family'] }} · {{ $r['id'] }}</small></span>
                                    </a>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                </div>
                <aside class="panel" id="tm-panel" aria-live="polite">
                    <p>Pick a resource on the map to see how it is made and what it is used for.</p>
                    <p class="meta">Try <a href="#r{{ \App\Services\Release::FUEL }}">Fuel (A1)</a>, or a ship on the right.</p>
                </aside>
            </div>

            <details class="mt-4" id="tm-list">
                <summary><b>Every process as a list</b> ({{ count($map['processes']) }})</summary>
                @foreach($kinds as $kind => $heading)
                    @php($group = collect($map['processes'])->where('kind', $kind))
                    @if($group->isNotEmpty())
                        <h3 class="h5 mt-3">{{ $heading }}</h3>
                        <div class="table-responsive">
                            <table class="table-custom table-custom-bordered list">
                                <tr><th>Lua call</th><th>Uses</th><th>Makes</th><th>Tool</th><th>Time, fuel</th></tr>
                                @foreach($group as $p)
                                    <tr>
                                        <td><a href="{{ url('protocols/' . $p['protocol']) }}"><code>{{ $p['call'] }}</code></a></td>
                                        <td>{{ $p['uses'] ? $side($p['uses']) : '–' }}</td>
                                        <td>{{ $side($p['makes']) }}@if($kind === 'mine') × abundance @endif</td>
                                        <td>@if($p['tool'])<a href="{{ url('resources/' . $p['tool']) }}">{{ $res[$p['tool']]['name'] ?? $p['tool'] }}</a>@else – @endif</td>
                                        <td class="num">{{ $p['hours'] + 0 }} h, {{ $p['fuel'] }} fuel</td>
                                    </tr>
                                @endforeach
                            </table>
                        </div>
                    @endif
                @endforeach
            </details>
        </div>
    </section>
    <script>
    // Started once the page has loaded: Vue mounts the page first and would replace what this draws
    document.addEventListener('DOMContentLoaded', () => {
        const MAP = @json($map);
        const R = Object.fromEntries(MAP.resources.map(r => [r.id, r])), P = MAP.processes;
        const PROTOCOL_URL = @json(url('protocols')) + '/';
        const page = document.getElementById('tech-page'), cols = document.getElementById('tm-cols'),
            panel = document.getElementById('tm-panel'), plate = document.getElementById('tm-plate');
        const cards = Object.fromEntries([...cols.querySelectorAll('.card')].map(c => [c.dataset.id, c]));
        const esc = s => String(s).replace(/[&<>"]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]));
        const num = n => (Math.round(n * 100) / 100).toLocaleString();
        const NS = 'http://www.w3.org/2000/svg';
        const svg = document.createElementNS(NS, 'svg');
        svg.setAttribute('aria-hidden', 'true');
        cols.prepend(svg);

        // A resource as a chip: picture, units, name; it picks that resource on the map
        const chip = (id, units, self) => {
            const r = R[id] || { name: 'Resource ' + id };
            return `<a class="chip${self ? ' self' : ''}" href="${esc(r.url || '#')}" data-pick="${id}" title="${esc(r.name)}">` +
                (r.thumb ? `<img src="${esc(r.thumb)}" alt="">` : '') +
                (units !== undefined ? `<b>${num(units)}</b> ×` : '') + ` ${esc(r.name)}</a>`;
        };
        const call = p => `<a href="${PROTOCOL_URL}${p.protocol}"><code>${esc(p.call)}</code></a>` +
            (p.perform !== p.call ? ` or <code>${esc(p.perform)}</code>` : '');
        const hours = h => h >= 24 ? num(h / 24) + ' game day' + (h === 24 ? '' : 's') : num(h) + ' game hour' + (h === 1 ? '' : 's');
        // One process as a formula: inputs → outputs, then its tool, call, time and fuel
        const formula = (p, self) => {
            const left = p.kind === 'mine' ? '<span class="op">dig</span>'
                : p.uses.map(([id, u]) => chip(id, u, id === self)).join('<span class="op">+</span>');
            const right = p.makes.map(([id, u]) => chip(id, u, id === self)).join('<span class="op">+</span>') +
                (p.kind === 'mine' ? '<span class="op">× how abundant it is there</span>' : '');
            return `<div class="recipe"><div class="formula">${left}<span class="op">→</span>${right}</div>` +
                `<div class="meta">${p.tool ? 'Tool: ' + chip(p.tool, undefined, p.tool === self) + ' · ' : ''}${call(p)}<br>` +
                `${esc(p.name)} · ${hours(p.hours)}${p.fuel ? ' · burns ' + p.fuel + ' fuel' : ''}</div></div>`;
        };

        // Everything upstream (the processes that make it, and what those use, all the way down) and downstream
        const walk = (start, next) => {
            const seen = new Set([start]), procs = new Set(), todo = [start];
            while (todo.length) {
                const id = todo.pop();
                next(id).forEach(pid => {
                    procs.add(pid);
                    const p = P[pid];
                    (next === upFrom ? p.uses : p.makes).forEach(([r]) => { if (!seen.has(r)) { seen.add(r); todo.push(r); } });
                });
            }
            seen.delete(start);
            return { res: seen, procs };
        };
        // Unpacking a kit only frees modules already made: it isn't a pathway, so the chain skips it
        const upFrom = id => (R[id]?.made_by || []).filter(pid => P[pid].kind !== 'unpack');
        const downFrom = id => (R[id]?.used_in || []).filter(pid => P[pid].kind !== 'unpack');

        let selected = null, lines = [];
        const pos = (el, side) => {
            const c = cols.getBoundingClientRect(), b = el.getBoundingClientRect();
            return [(side === 'r' ? b.right : b.left) - c.left, b.top + b.height / 2 - c.top];
        };
        const draw = () => {
            svg.replaceChildren();
            svg.setAttribute('width', cols.scrollWidth); svg.setAttribute('height', cols.scrollHeight);
            lines.forEach(([from, to, cls]) => {
                const a = cards[from], b = cards[to];
                if (!a || !b) return;
                let [x1, y1] = pos(a, 'r'), [x2, y2] = pos(b, 'l');
                if (x2 < x1) { [x1, y1] = pos(a, 'l'); [x2, y2] = pos(b, 'r'); }   // a tool drawn backwards
                const mx = (x1 + x2) / 2, path = document.createElementNS(NS, 'path');
                path.setAttribute('d', `M${x1},${y1} C${mx},${y1} ${mx},${y2} ${x2},${y2}`);
                path.setAttribute('class', 'edge ' + cls);
                svg.appendChild(path);
            });
        };

        const select = (id, scroll) => {
            id = +id;
            const r = R[id];
            Object.values(cards).forEach(c => c.classList.remove('sel', 'up', 'down', 'tool'));
            lines = [];
            if (!r) {
                selected = null; page.classList.remove('focus'); draw();
                document.getElementById('tm-clear').hidden = true;
                return;
            }
            selected = id;
            page.classList.add('focus');
            document.getElementById('tm-clear').hidden = false;
            const up = walk(id, upFrom), down = walk(id, downFrom), tools = new Set();
            up.res.forEach(x => cards[x]?.classList.add('up'));
            down.res.forEach(x => cards[x]?.classList.add('down'));
            up.procs.forEach(pid => P[pid].uses.forEach(([u]) => P[pid].makes.forEach(([m]) => {
                if (m === id || up.res.has(m)) lines.push([u, m, 'up']);
            })));
            down.procs.forEach(pid => P[pid].uses.forEach(([u]) => P[pid].makes.forEach(([m]) => {
                if (u === id || down.res.has(u)) lines.push([u, m, 'down']);
            })));
            // The tools: this resource's own recipes', and what it is a tool for
            (r.made_by || []).forEach(pid => { const t = P[pid].tool; if (t) { tools.add(t); lines.push([t, id, 'tool']); } });
            (r.tool_for || []).forEach(pid => P[pid].makes.forEach(([m]) => { lines.push([id, m, 'tool']); cards[m]?.classList.add('tool'); }));
            tools.forEach(t => cards[t]?.classList.add('tool'));
            cards[id]?.classList.add('sel');
            draw();
            // Bring the card into view inside the map only: scrolling the page would hide the panel
            if (scroll && cards[id]) {
                const b = cards[id].getBoundingClientRect(), p = plate.getBoundingClientRect();
                const left = plate.scrollLeft + b.left - p.left - (p.width - b.width) / 2, top = plate.scrollTop + b.top - p.top - (p.height - b.height) / 2;
                plate.scrollTo({ left: Math.max(0, left), top: Math.max(0, top), behavior: 'smooth' });
            }

            // The panel
            const made = (r.made_by || []).map(pid => P[pid]);
            const used = (r.used_in || []).map(pid => P[pid]);
            const toolFor = (r.tool_for || []).map(pid => P[pid]);
            let html = `<div class="head">${r.thumb ? `<img src="${esc(r.thumb)}" alt="">` : ''}<div>` +
                `<h3><a href="${esc(r.url)}">${esc(r.name)}</a></h3>` +
                `<div class="meta">${esc(r.family)} · resource ${r.id} · ${num(r.mass)} kg a unit` +
                `${r.step !== null ? ' · step ' + r.step : ''}</div>` +
                (r.description ? `<div class="meta">${esc(r.description)}</div>` : '') + `</div></div>`;
            html += `<h4>How it's ${made.some(p => p.kind === 'mine') ? 'dug' : 'made'}</h4>` +
                (made.length ? made.map(p => formula(p, id)).join('') : '<p class="meta">Nothing in the game makes it: buy it from the House or another company.</p>');
            if (r.tree) {
                html += `<h4>One unit takes, all the way down</h4><ul>` +
                    r.tree.goods.map(([g, u]) => `<li>${chip(g)}<span class="num">${num(u)}</span></li>`).join('') +
                    `<li><span>Fuel burned</span><span class="num">${num(r.tree.fuel)}</span></li>` +
                    r.tree.tools.map(([t, h]) => `<li><span>${chip(t)}</span><span class="num">${num(h)} h</span></li>`).join('') +
                    `</ul><p class="meta">Down to refined goods, by the first recipe for each, in ${r.tree.steps.length} step${r.tree.steps.length === 1 ? '' : 's'}: ` +
                    r.tree.steps.map(([pid, runs]) => `<a href="${PROTOCOL_URL}${pid}">${esc(P[pid]?.name || pid)}</a> × ${num(runs)}`).join(', ') + `.</p>`;
            }
            if (used.length) html += `<h4>Goes into (${used.length})</h4>` + used.map(p => formula(p, id)).join('');
            if (toolFor.length) html += `<h4>Tool for (${toolFor.length})</h4>` + toolFor.map(p => formula(p, id)).join('');
            panel.innerHTML = html;
            panel.scrollTop = 0;
        };

        const fromHash = scroll => {
            const m = location.hash.match(/^#r(\d+)$/);
            select(m ? m[1] : null, scroll);
        };
        // A card picks its resource here (its link goes to the resource page without JavaScript, or in a new tab)
        cols.addEventListener('click', e => {
            const card = e.target.closest('.card');
            if (!card || e.ctrlKey || e.metaKey || e.shiftKey || e.button) return;
            e.preventDefault();
            const id = +card.dataset.id;
            history.replaceState(null, '', id === selected ? location.pathname : '#r' + id);
            select(id === selected ? null : id);
        });
        // A chip in the panel picks its resource too
        panel.addEventListener('click', e => {
            const pick = e.target.closest('[data-pick]');
            if (!pick || e.ctrlKey || e.metaKey || e.shiftKey || e.button || !R[pick.dataset.pick]) return;
            e.preventDefault();
            history.replaceState(null, '', '#r' + pick.dataset.pick);
            select(pick.dataset.pick, true);
        });
        window.addEventListener('hashchange', () => fromHash(true));
        document.getElementById('tm-clear').addEventListener('click', () => { history.replaceState(null, '', location.pathname); select(null); });
        document.getElementById('tm-find').addEventListener('change', e => {
            const want = e.target.value.trim().toLowerCase();
            const hit = MAP.resources.find(r => r.name.toLowerCase() === want) || MAP.resources.find(r => r.name.toLowerCase().includes(want));
            if (hit) { history.replaceState(null, '', '#r' + hit.id); select(hit.id, true); }
        });
        document.addEventListener('keydown', e => { if (e.key === 'Escape' && selected) { history.replaceState(null, '', location.pathname); select(null); } });
        let t;
        window.addEventListener('resize', () => { clearTimeout(t); t = setTimeout(draw, 100); });
        // Pictures load lazily: lines move with them only if the layout changes, which it doesn't (fixed sizes)
        fromHash(true);
    });
    </script>
@endsection
