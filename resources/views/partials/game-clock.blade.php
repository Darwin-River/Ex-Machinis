{{-- The simulation's date and time on every page (#753): game time is
     Unix time, so it is a calendar date (the orbits run on it). It ticks
     here at the clock's pace, and checks /api/v1/clock each real minute. --}}
@php($gc = \Illuminate\Support\Facades\DB::table('game_clock')->where('id', 1)->first(['scale']))
@php($gcNow = \App\Services\World::gameNow())
@php($gcScale = (float) ($gc->scale ?? 1))
<div id="sim-clock" role="region" aria-label="Game clock" style="background: #12141c; color: #c9cbd6; font-size: 0.85rem; padding: 0.2rem 1rem; text-align: center; white-space: normal">
    <span id="sim-clock-when">{{ \App\Services\GameDate::at($gcNow) }}</span> UTC in the simulation
    <span style="color: #8a8fa0">· a game day every <span id="sim-clock-pace">{{ round(86400 / max(1, $gcScale)) }}</span> real seconds</span>
</div>
<script>
(function () {
    const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    let gameT = @json($gcNow), scale = @json($gcScale), at = Date.now();
    const show = () => {
        const x = new Date((gameT + (Date.now() - at) / 1000 * scale) * 1000);
        document.getElementById('sim-clock-when').textContent = x.getUTCDate() + ' ' + MONTHS[x.getUTCMonth()] + ' ' + x.getUTCFullYear()
            + ', ' + String(x.getUTCHours()).padStart(2, '0') + ':' + String(x.getUTCMinutes()).padStart(2, '0');
    };
    const sync = () => fetch(@json(url('api/v1/clock'))).then((r) => r.ok ? r.json() : null).then((c) => {
        if (!c || !c.game_time) return;
        gameT = c.game_time; scale = c.scale || scale; at = Date.now();
        if (c.real_seconds_per_game_day) document.getElementById('sim-clock-pace').textContent = c.real_seconds_per_game_day;
        show();
    }).catch(() => {});
    setInterval(show, 1000);
    setInterval(sync, 60000);
})();
</script>
