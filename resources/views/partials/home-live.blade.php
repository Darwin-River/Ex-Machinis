{{-- The game in action (#1049): the live map beside the numbers and a chart of trade, and the call to join.
     $map: LiveMap::now(); $trading: Dashboard::full()['trading'] with 'today'; $tiles: Dashboard::compact()['tiles'];
     $join: show the Join the Economy button (a visitor) --}}
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
    @media (max-width: 991px) { .home-live { grid-template-columns: 1fr; } }
</style>
@endpush
@endonce
<div class="home-live" id="home-live">
    <figure class="mb-0">
        @include('partials.live-map', ['map' => $map])
        <figcaption class="home-live-caption">Every dot is a players' drone at work, every moving line a flight under way, right now.
            Each is a few lines of Lua a player wrote. <a href="{{ url('map') }}">The whole map</a>.</figcaption>
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
        <div class="ex-legend" id="home-trading-legend"></div>
        <div class="ex-chart" id="home-trading" aria-label="Credits traded each game day, by place, over the last {{ \App\Services\Dashboard::DAYS }} game days"></div>
        @if($join)
            <div class="home-join">
                <a class="button button-primary button-lg" href="{{ url('try') }}" id="join-game">Join the Economy</a>
                <p>Fly your first drone in your browser, in a few lines of code. No account needed to try it.</p>
            </div>
        @endif
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const X = window.ExCharts, T = @json($trading), PLACES = @json(\App\Services\World::PLACE_COLOURS), box = document.getElementById('home-trading');
    if (!X || !box) return;
    const colour = T.places.map((p, i) => p === 'Elsewhere' ? X.GREY : (PLACES[p] || X.COLOURS[(i + 5) % X.COLOURS.length]));
    X.legend(document.getElementById('home-trading-legend'), T.places.map((p, i) => ({name: p, colour: colour[i]})));
    const draw = () => X.stacked(box, T.days.map((d) => ({day: d.day, values: T.places.map((p) => d.by_place[p] || 0)})),
        T.places.map((p, i) => ({name: p, colour: colour[i]})),
        {H: 200, unit: 'cr', unitWord: 'credits', empty: 'No trades in the last 60 game days.', partial: T.today,
         dayNote: (d) => d ? ` · ${X.num(d.trades)} trades` : ''});
    X.onResize(box, draw);
});
</script>
