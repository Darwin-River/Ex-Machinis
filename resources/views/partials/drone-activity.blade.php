{{--
    A drone's last 100 actions on a time axis (#699): one row a kind of
    action, failures in red, gaps left empty so a stuck or idle drone shows,
    and its fuel after each action below on the same axis. From
    /api/v1/drones/{id}/timeline. Takes $droneId.
--}}
@include('partials.ex-charts')
@php($epoch = (int) (\Illuminate\Support\Facades\DB::table('game_clock')->where('id', 1)->value('epoch') ?? 0))
<div class="mh" id="drone-activity">
    <div class="mh-card">
        <h3>What has it been doing?</h3>
        <p class="mh-note">Its last 100 actions in time order, one row for each kind; a red mark failed. A long empty stretch is a
            drone waiting, idle or stuck. Below, the fuel in its tank after each action, on the same time axis.</p>
        <div class="ex-legend" id="da-legend"></div>
        <div class="ex-chart" id="da-strip" aria-label="Its last actions by kind over time"></div>
        <div class="ex-chart" id="da-fuel" aria-label="Its fuel over time"></div>
        <p class="ex-src">Data: <code>GET /api/v1/drones/{{ (int) $droneId }}/timeline?limit=100</code></p>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const X = window.ExCharts, C = X.COLOURS, EPOCH = @json($epoch), $ = (id) => document.getElementById(id);
    let rows = [];
    const day = (t) => (t - EPOCH) / 86400;
    function axis(svg, x, t0, t1, y, W) {
        const span = (t1 - t0) / 86400, every = span > 20 ? 5 : span > 6 ? 1 : span > 2 ? 0.5 : 0.25;
        let clear = -1e9;
        for (let d = Math.ceil(day(t0) / every) * every; d <= day(t1); d += every) {
            const px = x(EPOCH + d * 86400);
            X.el('line', {x1: px, x2: px, y1: 0, y2: y - 14, stroke: '#2a2f3d'}, svg);
            const lab = X.el('text', {x: px, y: y, 'text-anchor': 'middle'}, svg);
            const whole = Math.abs(d - Math.round(d)) < 1e-6;
            lab.textContent = whole ? 'day ' + Math.round(d) : String(Math.floor(d)) + ' ' + String(Math.round((d % 1) * 24)).padStart(2, '0') + 'h';
            const w = lab.getComputedTextLength();
            if (px - w / 2 < clear || px + w / 2 > W) { lab.remove(); continue; }
            clear = px + w / 2 + 8;
        }
    }
    function draw() {
        const strip = $('da-strip'), fuelBox = $('da-fuel');
        if (!rows.length) { X.empty(strip, 'It has no actions yet.'); fuelBox.replaceChildren(); return; }
        const kinds = [...new Set(rows.map((a) => a.what))];
        const t0 = rows[0].game_t, t1 = Math.max(rows[rows.length - 1].game_t + 3600, t0 + 3600);
        // The strip
        strip.replaceChildren();
        const W = Math.max(260, strip.clientWidth), rh = 22, H = kinds.length * rh + 24;
        const svg = X.el('svg', {viewBox: `0 0 ${W} ${H}`, role: 'img', 'aria-label': strip.getAttribute('aria-label')}, strip);
        const probe = X.el('text', {}, svg); let lw = 40;
        kinds.forEach((k) => { probe.textContent = k; lw = Math.max(lw, probe.getComputedTextLength()); }); probe.remove();
        lw = Math.min(lw + 10, W * 0.35);
        const x = (t) => lw + (t - t0) / (t1 - t0) * (W - lw - 10);
        axis(svg, x, t0, t1, H - 4, W);
        kinds.forEach((k, i) => {
            const y = i * rh + 4;
            const lab = X.el('text', {x: lw - 8, y: y + 11, 'text-anchor': 'end', class: 'ex-strong'}, svg);
            let name = k; lab.textContent = name;
            while (lab.getComputedTextLength() > lw - 12 && name.length > 3) { name = name.slice(0, -2); lab.textContent = name + '…'; }
            X.el('line', {x1: lw, x2: W - 10, y1: y + 8, y2: y + 8, stroke: '#2a2f3d'}, svg);
        });
        const colour = Object.fromEntries(kinds.map((k, i) => [k, C[i % C.length]]));
        rows.forEach((a) => {
            const i = kinds.indexOf(a.what), y = i * rh + 4, failed = a.result.startsWith('failed');
            const m = X.el('rect', {x: x(a.game_t) - 2, y: y + 1, width: 4, height: 14, rx: 1, fill: failed ? X.NEG : colour[a.what],
                stroke: failed ? '#ffd0cc' : 'none', 'stroke-width': failed ? 1 : 0}, svg);
            X.tip(m, [a.when + ': ' + a.what, (failed ? '✗ ' : '') + a.result].concat(a.fuel !== null ? ['Fuel after: ' + X.num(a.fuel)] : []));
            // a wider hit target than the mark
            X.tip(X.el('rect', {x: x(a.game_t) - 5, y: y - 2, width: 10, height: 20, fill: 'transparent'}, svg),
                [a.when + ': ' + a.what, (failed ? '✗ ' : '') + a.result].concat(a.fuel !== null ? ['Fuel after: ' + X.num(a.fuel)] : []));
        });
        X.legend($('da-legend'), kinds.map((k) => ({name: k, colour: colour[k]})).concat([{name: 'Failed', colour: X.NEG}]));

        // Fuel after each action, a step line on the same axis
        fuelBox.replaceChildren();
        const pts = rows.filter((a) => a.fuel !== null);
        if (!pts.length) return;
        const FH = 120, top = X.ticks(0, Math.max(1, ...pts.map((p) => p.fuel)), 3, 1), hi = top[top.length - 1];
        const fs = X.el('svg', {viewBox: `0 0 ${W} ${FH}`, role: 'img', 'aria-label': fuelBox.getAttribute('aria-label')}, fuelBox);
        const y = (v) => 20 + (hi - v) / hi * (FH - 44);
        axis(fs, x, t0, t1, FH - 4, W);
        top.forEach((t) => {
            X.el('line', {x1: lw, x2: W - 10, y1: y(t), y2: y(t), stroke: t ? '#34353f' : '#828590'}, fs);
            X.el('text', {x: lw - 8, y: y(t), dy: '0.32em', 'text-anchor': 'end'}, fs).textContent = X.short(t);
        });
        X.el('text', {x: lw - 8, y: 8, 'text-anchor': 'end'}, fs).textContent = 'fuel';
        let d = '';
        pts.forEach((p, i) => { d += (i ? 'H' + x(p.game_t) + 'V' : 'M' + x(p.game_t) + ',') + y(p.fuel); });
        d += 'H' + x(t1);
        X.el('path', {d, fill: 'none', stroke: C[3], 'stroke-width': 2}, fs);
        pts.forEach((p) => X.tip(X.el('circle', {cx: x(p.game_t), cy: y(p.fuel), r: 3, fill: C[3], stroke: '#24242c', 'stroke-width': 1}, fs),
            [p.when, 'Fuel after ' + p.what.toLowerCase() + ': ' + X.num(p.fuel)]));
    }
    fetch(@json(url('api/v1/drones/' . (int) $droneId . '/timeline')) + '?limit=100').then((r) => r.ok ? r.json() : Promise.reject(r.status))
        .then((j) => { rows = j.timeline.slice().reverse(); draw(); X.onResize($('da-strip'), draw); })
        .catch(() => X.empty($('da-strip'), 'Its actions did not load. Try again in a minute.'));
});
</script>
