{{-- Explore the world (#1059): every company, good and place as a table a visitor sorts, filters and searches, a few
     rows at a time, so the world's size shows without a wall of numbers. $explore: Explorer::data() --}}
@php($n = fn ($v) => number_format((int) $v))
@once
@push('styles')
<style>
    .explore { margin-top: 28px; }
    .explore h2 { font-size: 1.25rem; margin: 0 0 4px; }
    .explore-lead { color: #9aa3b8; margin: 0 0 12px; white-space: normal; }
    .explore-tabs { display: flex; gap: 4px; border-bottom: 1px solid #263052; margin-bottom: 10px; overflow-x: auto; }
    .explore-tabs button { background: none; border: 0; border-bottom: 2px solid transparent; color: #9aa3b8; padding: 8px 12px; font: inherit; cursor: pointer; white-space: nowrap; }
    .explore-tabs button[aria-selected="true"] { color: #e6e9f2; border-bottom-color: #4cc9f0; }
    .explore-tabs b { font-variant-numeric: tabular-nums; color: #ffb547; font-weight: 600; margin-left: 4px; }
    .explore-bar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 8px; }
    .explore-bar input, .explore-bar select { background: #111729; color: #e6e9f2; border: 1px solid #263052; border-radius: 8px; padding: 5px 10px; font: inherit; font-size: 14px; }
    .explore-bar input { flex: 1 1 180px; min-width: 0; }
    .explore-wrap { overflow-x: auto; }
    .explore table { width: 100%; border-collapse: collapse; font-size: 14px; font-variant-numeric: tabular-nums; }
    .explore th, .explore td { padding: 6px 8px; border-bottom: 1px solid #1b2240; text-align: right; white-space: nowrap; }
    .explore th:first-child, .explore td:first-child { text-align: left; white-space: normal; }
    .explore th button { background: none; border: 0; color: #9aa3b8; font: inherit; font-weight: 600; cursor: pointer; padding: 0; }
    .explore th[aria-sort] button { color: #e6e9f2; }
    .explore th[aria-sort="descending"] button::after { content: " ↓"; }
    .explore th[aria-sort="ascending"] button::after { content: " ↑"; }
    .explore td.up { color: #6fdc8c; } .explore td.down { color: #ff7a7a; }
    .explore-more { display: flex; gap: 10px; align-items: center; margin-top: 8px; color: #9aa3b8; font-size: 13.5px; flex-wrap: wrap; }
    .explore-more button { background: #111729; color: #c3c9d8; border: 1px solid #263052; border-radius: 8px; padding: 5px 12px; font: inherit; cursor: pointer; }
    @media (max-width: 575px) { .explore .opt { display: none; } }
</style>
@endpush
@endonce
<section class="explore" id="explore" aria-labelledby="explore-title">
    <h2 id="explore-title">Explore the world</h2>
    <p class="explore-lead">{{ $n($explore['company_count']) }} companies, {{ $n(count($explore['goods'])) }} goods on the market and
        {{ $n(count($explore['places'])) }} places with drones at work. Sort, filter and search them; every row opens its own page.</p>
    <div class="explore-tabs" role="tablist" aria-label="What to explore">
        <button type="button" role="tab" data-tab="companies" aria-selected="true">Companies<b>{{ $n($explore['company_count']) }}</b></button>
        <button type="button" role="tab" data-tab="goods" aria-selected="false">Goods<b>{{ $n(count($explore['goods'])) }}</b></button>
        <button type="button" role="tab" data-tab="places" aria-selected="false">Places<b>{{ $n(count($explore['places'])) }}</b></button>
    </div>
    <div class="explore-bar">
        <input type="search" id="explore-search" placeholder="Search by name" aria-label="Search by name">
        <select id="explore-filter" aria-label="Filter"></select>
    </div>
    <div class="explore-wrap"><table id="explore-table"><thead></thead><tbody></tbody></table></div>
    <div class="explore-more" id="explore-more"></div>
    <noscript><p><a href="{{ url('leaderboard') }}">The leaderboard</a>, <a href="{{ url('market') }}">the market</a> and <a href="{{ url('map') }}">the map</a>.</p></noscript>
</section>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const D = @json($explore), PAGE = 10, MORE = 50;
    const table = document.getElementById('explore-table'), search = document.getElementById('explore-search'),
        filter = document.getElementById('explore-filter'), more = document.getElementById('explore-more'), tabs = document.querySelector('.explore-tabs');
    if (!table) return;
    const n = (v) => v === null || v === undefined ? '–' : Number(v).toLocaleString('en-US');
    const tiers = [...new Set(D.goods.map((g) => g.tier))];
    // Each table: its rows, columns (key, label, how to show it, sorts first high or low, hidden on a phone), filters
    const VIEWS = {
        companies: {rows: D.companies, link: (r) => '{{ url('companies') }}/' + r.id, sort: 'wealth',
            cols: [['name', 'Company'], ['wealth', 'Net worth', (v) => n(v) + ' cr'], ['drones', 'Drones'], ['age', 'Age (game days)', (v) => n(Math.round(v)), 'opt']],
            filters: [['all', 'All companies'], ['startup', 'Startups'], ['grown', 'Matured and others']], by: (r, f) => f === 'all' || r.status === f,
            note: D.company_count > D.companies.length ? `The ${n(D.companies.length)} richest of ${n(D.company_count)}. <a href="{{ url('leaderboard') }}">The leaderboard</a> ranks them all.` : ''},
        goods: {rows: D.goods, link: (r) => '{{ url('resources') }}/' + r.id, sort: 'credits',
            cols: [['name', 'Good'], ['average', 'Average price', (v) => n(v) + ' cr'], ['change', 'Change', (v) => v === null ? '–' : (v > 0 ? '+' : '') + v + '%', 'opt'],
                ['credits', 'Credits traded', (v) => n(v)], ['places', 'Places quoting', null, 'opt']],
            filters: [['all', 'Every tier']].concat(tiers.map((t) => [t, t.charAt(0).toUpperCase() + t.slice(1)])), by: (r, f) => f === 'all' || r.tier === f,
            note: 'Prices and trades over the last game days. <a href="{{ url('market') }}">The market</a> has every order.'},
        places: {rows: D.places, link: (r) => '{{ url('locations') }}/' + r.id, sort: 'working',
            cols: [['name', 'Place'], ['working', 'Drones working'], ['wrecks', 'Wrecks', null, 'opt'], ['exchange', 'Market', (v) => v ? 'Yes' : '–']],
            filters: [['all', 'Every place'], ['market', 'With a market']], by: (r, f) => f === 'all' || r.exchange,
            note: '<a href="{{ url('map') }}">The map</a> shows where they are.'},
    };
    const state = {tab: 'companies', q: '', f: 'all', sort: null, dir: -1, shown: PAGE};
    const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'})[c]);

    function render() {
        const v = VIEWS[state.tab], key = state.sort || v.sort, q = state.q.trim().toLowerCase();
        let rows = v.rows.filter((r) => v.by(r, state.f) && (!q || String(r.name).toLowerCase().includes(q)));
        rows = rows.slice().sort((a, b) => {
            const x = a[key], y = b[key];
            if (typeof x === 'string' || typeof y === 'string') return state.dir * String(x).localeCompare(String(y));
            return state.dir * (((x ?? -Infinity) > (y ?? -Infinity)) - ((x ?? -Infinity) < (y ?? -Infinity)));
        });
        table.tHead.innerHTML = '<tr>' + v.cols.map(([k, label, , opt]) => `<th scope="col"${opt ? ' class="opt"' : ''}${k === key ? ` aria-sort="${state.dir < 0 ? 'descending' : 'ascending'}"` : ''}><button type="button" data-sort="${k}">${label}</button></th>`).join('') + '</tr>';
        table.tBodies[0].innerHTML = rows.slice(0, state.shown).map((r) => '<tr>' + v.cols.map(([k, , show, opt], i) => {
            const text = show ? show(r[k]) : (typeof r[k] === 'number' ? n(r[k]) : esc(r[k]));
            const cls = [opt ? 'opt' : '', k === 'change' && r[k] ? (r[k] > 0 ? 'up' : 'down') : ''].filter(Boolean).join(' ');
            return `<td${cls ? ` class="${cls}"` : ''}>` + (i === 0 ? `<a href="${v.link(r)}">${text}</a>` : text) + '</td>';
        }).join('') + '</tr>').join('') || `<tr><td colspan="${v.cols.length}">Nothing matches. Try another search or filter.</td></tr>`;
        const left = rows.length - state.shown;
        more.innerHTML = `<span>Showing ${n(Math.min(state.shown, rows.length))} of ${n(rows.length)}.</span>`
            + (left > 0 ? `<button type="button" data-more>Show ${n(Math.min(left, MORE))} more</button>` + (left > MORE ? `<button type="button" data-all>Show all ${n(rows.length)}</button>` : '') : '')
            + (state.shown > PAGE && rows.length > PAGE ? '<button type="button" data-less>Show fewer</button>' : '')
            + (v.note ? `<span>${v.note}</span>` : '');
    }
    function setTab(tab) {
        state.tab = tab; state.f = 'all'; state.sort = null; state.dir = -1; state.shown = PAGE;
        tabs.querySelectorAll('[data-tab]').forEach((b) => b.setAttribute('aria-selected', b.dataset.tab === tab));
        filter.innerHTML = VIEWS[tab].filters.map(([k, label]) => `<option value="${k}">${esc(label)}</option>`).join('');
        render();
    }
    tabs.addEventListener('click', (e) => { const b = e.target.closest('[data-tab]'); if (b) setTab(b.dataset.tab); });
    search.addEventListener('input', () => { state.q = search.value; state.shown = PAGE; render(); });
    filter.addEventListener('change', () => { state.f = filter.value; state.shown = PAGE; render(); });
    table.addEventListener('click', (e) => {
        const b = e.target.closest('[data-sort]'); if (!b) return;
        const k = b.dataset.sort, cur = state.sort || VIEWS[state.tab].sort;
        // A name sorts A to Z first; a number, highest first
        state.dir = k === cur ? -state.dir : (k === 'name' ? 1 : -1);
        state.sort = k; render();
    });
    more.addEventListener('click', (e) => {
        if (e.target.closest('[data-more]')) state.shown += MORE;
        else if (e.target.closest('[data-all]')) state.shown = Infinity;
        else if (e.target.closest('[data-less]')) { state.shown = PAGE; document.getElementById('explore').scrollIntoView({block: 'start'}); }
        else return;
        render();
    });
    setTab('companies');
});
</script>
