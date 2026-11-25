{{-- The game in action (#1049): the live map beside the numbers and a chart of trade, and the call to join.
     $map: LiveMap::now(); $trading: Dashboard::full()['trading'] with 'today'; $tiles: Dashboard::compact()['tiles'];
     $join: show the Join the Economy button (a visitor). Every view is something to explore (#1059): the map zooms out
     to the whole system, hides the flights and opens a place's panel; the chart picks its period and places --}}
@php($n = fn ($v) => number_format((int) $v))
@php($working = collect($map['bodies'])->sum('working'))
@once
@push('styles')
<style>
    .home-live { display: grid; grid-template-columns: minmax(0, 7fr) minmax(0, 5fr); gap: 20px; align-items: start; }
    .home-live > * { min-width: 0; }
    .home-live-caption { color: #9aa3b8; font-size: 13.5px; margin: 6px 0 0; white-space: normal; }
    .home-stats { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; margin: 0 0 14px; }
    .home-stat { background: #111729; border: 1px solid #263052; border-radius: 10px; padding: 10px 12px; }
    .home-stat b { display: block; font: 700 1.6rem/1.1 "Space Grotesk", system-ui, sans-serif; color: #e6e9f2; font-variant-numeric: tabular-nums; }
    .home-stat span { color: #9aa3b8; font-size: 13.5px; }
    .home-chart-title { font-size: 1rem; margin: 0 0 4px; color: #e6e9f2; }
    .home-join { margin-top: 16px; display: grid; gap: 6px; justify-items: start; }
    .home-join p { color: #c3c9d8; margin: 0; white-space: normal; }
    .home-tools { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin: 0 0 8px; font-size: 13.5px; color: #9aa3b8; }
    .home-tools .seg { display: inline-flex; border: 1px solid #263052; border-radius: 8px; overflow: hidden; }
    .home-tools button { background: #111729; color: #c3c9d8; border: 0; padding: 5px 10px; font: inherit; cursor: pointer; }
    .home-tools .seg button + button { border-left: 1px solid #263052; }
    .home-tools button[aria-pressed="true"] { background: #263052; color: #fff; }
    .home-tools button:focus-visible { outline: 2px solid #4cc9f0; outline-offset: -2px; }
    .home-tools .chip { border: 1px solid #263052; border-radius: 999px; padding: 3px 10px; }
    .home-tools .chip i { display: inline-block; width: 9px; height: 9px; border-radius: 2px; margin-right: 5px; }
    .home-tools .chip[aria-pressed="false"] { opacity: .45; text-decoration: line-through; }
    .home-place { margin-top: 8px; background: #111729; border: 1px solid #263052; border-radius: 10px; padding: 10px 12px; font-size: 14px; color: #c3c9d8; white-space: normal; }
    .home-place h3 { font-size: 1rem; margin: 0 0 4px; color: #e6e9f2; }
    .home-place ul { margin: 4px 0 0; padding-left: 18px; }
    @media (max-width: 991px) { .home-live { grid-template-columns: 1fr; } }
</style>
@endpush
@endonce
<div class="home-live" id="home-live">
    <figure class="mb-0">
        <div class="home-tools" id="map-tools" role="group" aria-label="Map view">
            <span class="seg"><button type="button" data-view="near" aria-pressed="true">Where the drones are</button><button type="button" data-view="whole" aria-pressed="false">The whole system</button></span>
            <button type="button" class="chip" data-flights aria-pressed="true">Flights</button>
        </div>
        @include('partials.live-map', ['map' => $map])
        <figcaption class="home-live-caption">Every dot is a players' drone at work, every moving line a flight under way, right now.
            Each is a few lines of Lua a player wrote. Pick a place to see what's happening there.</figcaption>
        <div class="home-place" id="map-place" hidden aria-live="polite"></div>
    </figure>
    <div>
        <div class="home-stats" id="home-stats">
            <div class="home-stat"><b>{{ $n($tiles['players']['count'] ?? 0) }}</b><span>players</span></div>
            <div class="home-stat"><b>{{ $n($working) }}</b><span>drones working</span></div>
            <div class="home-stat"><b>{{ $n($map['flying']) }}</b><span>drones in flight now</span></div>
            <div class="home-stat"><b>{{ $n($tiles['trades']['value'] ?? 0) }}</b><span>trades in the last full game day</span></div>
        </div>
        <h2 class="home-chart-title">Credits traded each game day, by place</h2>
        @include('partials.ex-charts')
        <div class="home-tools" id="chart-tools" role="group" aria-label="Chart period">
            <span class="seg">@foreach([7, 30, \App\Services\Dashboard::DAYS] as $d)<button type="button" data-days="{{ $d }}" aria-pressed="{{ $d === 30 ? 'true' : 'false' }}">{{ $d }} days</button>@endforeach</span>
        </div>
        <div class="home-tools" id="home-trading-legend" role="group" aria-label="Places shown: press one to hide or show it"></div>
        <div class="ex-chart" id="home-trading" aria-label="Credits traded each game day, by place, over the last {{ \App\Services\Dashboard::DAYS }} game days"></div>
        @if($join)
            <div class="home-join">
                <a class="button button-primary button-lg" href="{{ url('try') }}" id="join-game">Join the Economy</a>
                <p>Fly your first drone in your browser, in a few lines of code. No account needed to try it.</p>
            </div>
        @endif
    </div>
</div>
@isset($explore)
    @include('partials.home-explore', ['explore' => $explore])
@endisset
<script>
document.addEventListener('DOMContentLoaded', function () {
    // The map (#1059): both views, the flights layer, and a place's panel
    const M = @json($map), svg = document.getElementById('live-map'), tools = document.getElementById('map-tools'), panel = document.getElementById('map-place');
    if (svg && tools && panel) {
        const near = svg.dataset.near.split(' ').map(Number), whole = svg.dataset.whole.split(' ').map(Number), k0 = Number(svg.dataset.k);
        const bodies = {}; M.bodies.forEach((b) => { bodies[b.id] = b; });
        const n = (v) => Number(v).toLocaleString('en-US');
        const setView = (which) => {
            const box = which === 'whole' ? whole : near, k = box[2] / 600;
            svg.setAttribute('viewBox', box.join(' '));
            svg.classList.toggle('whole', which === 'whole');
            svg.style.setProperty('--k', k);
            // Labels keep their size on screen, whichever view
            svg.querySelectorAll('text').forEach((t) => {
                if (!t.dataset.size) t.dataset.size = t.getAttribute('font-size');
                t.setAttribute('font-size', (t.dataset.size * k / k0).toFixed(2));
            });
            tools.querySelectorAll('[data-view]').forEach((b) => b.setAttribute('aria-pressed', b.dataset.view === which));
        };
        svg.style.setProperty('--k', k0);
        tools.addEventListener('click', (e) => {
            const v = e.target.closest('[data-view]'), f = e.target.closest('[data-flights]');
            if (v) setView(v.dataset.view);
            if (f) { const on = f.getAttribute('aria-pressed') !== 'true'; f.setAttribute('aria-pressed', on); svg.classList.toggle('no-flights', !on); }
        });
        const pick = (id) => {
            const b = bodies[id]; if (!b) return;
            svg.querySelectorAll('.body.picked').forEach((g) => g.classList.remove('picked'));
            svg.querySelector('.body[data-body="' + id + '"]')?.classList.add('picked');
            const out = M.flights.filter((f) => f.from === b.id), inn = M.flights.filter((f) => f.to === b.id);
            const line = (f, other, dir) => '<li>' + n(f.drones) + ' ' + (f.drones === 1 ? 'drone' : 'drones') + ' ' + dir + ' ' + bodies[other].name + '</li>';
            panel.innerHTML = '<h3></h3><div>' + n(b.working) + ' players\' ' + (b.working === 1 ? 'drone' : 'drones') + ' working here now.</div>'
                + (out.length + inn.length ? '<ul>' + out.map((f) => line(f, f.to, 'flying to')).join('') + inn.map((f) => line(f, f.from, 'on the way from')).join('') + '</ul>' : '<div>No flights to or from it right now.</div>')
                + '<div style="margin-top:6px"><a href="{{ url('locations') }}/' + b.id + '">Its markets, drones and history</a></div>';
            panel.querySelector('h3').textContent = b.name;
            panel.hidden = false;
        };
        svg.addEventListener('click', (e) => { const g = e.target.closest('.body'); if (g) pick(Number(g.dataset.body)); });
    }

    // The chart (#1059): its period, and which places it stacks
    const X = window.ExCharts, T = @json($trading), PLACES = @json(\App\Services\World::PLACE_COLOURS), box = document.getElementById('home-trading');
    if (!X || !box) return;
    const colour = T.places.map((p, i) => p === 'Elsewhere' ? X.GREY : (PLACES[p] || X.COLOURS[(i + 5) % X.COLOURS.length]));
    const shown = new Set(T.places);
    let days = 30;
    const legend = document.getElementById('home-trading-legend'), period = document.getElementById('chart-tools');
    T.places.forEach((p, i) => {
        const b = document.createElement('button'), sw = document.createElement('i');
        b.type = 'button'; b.className = 'chip'; b.dataset.place = p; b.setAttribute('aria-pressed', 'true');
        sw.style.background = colour[i]; b.append(sw, p); legend.appendChild(b);
    });
    const draw = () => {
        const keep = T.places.map((p, i) => i).filter((i) => shown.has(T.places[i]));
        X.stacked(box, T.days.slice(-days).map((d) => ({day: d.day, trades: d.trades, values: keep.map((i) => d.by_place[T.places[i]] || 0)})),
            keep.map((i) => ({name: T.places[i], colour: colour[i]})),
            {H: 200, unit: 'cr', unitWord: 'credits', empty: 'No trades in these game days at the places shown.', partial: T.today,
             dayNote: (d) => d ? ` · ${X.num(d.trades)} trades` : ''});
    };
    legend.addEventListener('click', (e) => {
        const b = e.target.closest('[data-place]'); if (!b) return;
        const p = b.dataset.place;
        if (shown.has(p) && shown.size > 1) shown.delete(p); else shown.add(p);
        legend.querySelectorAll('[data-place]').forEach((c) => c.setAttribute('aria-pressed', shown.has(c.dataset.place)));
        draw();
    });
    period.addEventListener('click', (e) => {
        const b = e.target.closest('[data-days]'); if (!b) return;
        days = Number(b.dataset.days);
        period.querySelectorAll('[data-days]').forEach((c) => c.setAttribute('aria-pressed', c === b));
        draw();
    });
    X.onResize(box, draw);
});
</script>
