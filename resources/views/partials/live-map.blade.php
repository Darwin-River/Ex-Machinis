{{-- The live map (#1049): the solar system now, with each body's working drones as dots and the flights under way as
     moving dashes, drawn on the server from LiveMap::now(). It zooms to where the drones are. $map: LiveMap::now() --}}
@php($bodies = collect($map['bodies'])->keyBy('id'))
@php($colours = \App\Services\World::PLACE_COLOURS)
@php($focus = $bodies->filter(fn ($b) => $b['working'] > 0 || in_array($b['id'], [3, 10, 4, 191], true)))
@php($focus = $focus->isEmpty() ? $bodies : $focus)
@php($pad = 46)
@php([$x0, $x1, $y0, $y1] = [$focus->min('x') - $pad, $focus->max('x') + $pad, $focus->min('y') - $pad, $focus->max('y') + $pad])
@php($side = max($x1 - $x0, $y1 - $y0, 120))
@php([$cx, $cy] = [($x0 + $x1) / 2, ($y0 + $y1) / 2])
@php($view = round($cx - $side / 2, 1) . ' ' . round($cy - $side / 2, 1) . ' ' . round($side, 1) . ' ' . round($side, 1))
@php($k = $side / 600)
@once
@push('styles')
<style>
    .live-map { width: 100%; height: auto; display: block; background: radial-gradient(circle at 50% 50%, #131a30 0%, #0a0e1a 70%); border: 1px solid #263052; border-radius: 12px; }
    .live-map .orbit { fill: none; stroke: #263052; }
    .live-map .flight { fill: none; stroke: #4cc9f0; stroke-linecap: round; opacity: .8; stroke-dasharray: 2 6; }
    .live-map .drone { fill: #ffb547; }
    .live-map text { fill: #c3c9d8; font-family: inherit; }
    .live-map .count { fill: #ffb547; font-weight: 700; }
    .live-map:not(.whole) text.far { display: none; }
    .live-map.no-flights .flights { display: none; }
    .live-map .body { cursor: pointer; }
    .live-map .body:hover > circle:first-of-type, .live-map .body.picked > circle:first-of-type { stroke: #e6e9f2; stroke-width: calc(var(--k, 1) * 1.2px); }
    @media (prefers-reduced-motion: no-preference) {
        .live-map .flight { animation: live-flight 1.6s linear infinite; }
        .live-map .drone { animation: live-twinkle 3s ease-in-out infinite; }
        @keyframes live-flight { to { stroke-dashoffset: -16; } }
        @keyframes live-twinkle { 50% { opacity: .55; } }
    }
</style>
@endpush
@endonce
<svg class="live-map" id="live-map" viewBox="{{ $view }}" data-near="{{ $view }}" data-whole="-20 -20 640 640" data-k="{{ round($k, 4) }}" role="img"
     aria-label="The solar system now: {{ number_format($bodies->sum('working')) }} drones working, {{ number_format($map['flying']) }} in flight">
    <title>Drones at work across the solar system, and the flights under way</title>
    {{-- Orbits, then the Sun --}}
    @foreach($bodies->where('kind', 'planet')->merge($bodies->where('kind', 'dwarf')) as $b)
        <circle class="orbit" cx="300" cy="300" r="{{ round(sqrt(($b['x'] - 300) ** 2 + ($b['y'] - 300) ** 2), 1) }}" stroke-width="{{ round(0.8 * $k, 2) }}"/>
    @endforeach
    <circle cx="300" cy="300" r="{{ round(9 * $k, 1) }}" fill="#ffd27a"/>
    {{-- Flights under way, bowed a little so two ways between a pair don't overlap --}}
    <g class="flights">
    @foreach($map['flights'] as $f)
        @php([$a, $b] = [$bodies[$f['from']], $bodies[$f['to']]])
        @php([$mx, $my] = [($a['x'] + $b['x']) / 2, ($a['y'] + $b['y']) / 2])
        @php([$dx, $dy] = [$b['x'] - $a['x'], $b['y'] - $a['y']])
        <path class="flight" d="M{{ $a['x'] }},{{ $a['y'] }} Q{{ round($mx - $dy * 0.18, 1) }},{{ round($my + $dx * 0.18, 1) }} {{ $b['x'] }},{{ $b['y'] }}"
              stroke-width="{{ round((1 + log($f['drones'] + 1, 2)) * $k, 2) }}"><title>{{ $f['drones'] }} {{ $f['drones'] === 1 ? 'drone' : 'drones' }} flying from {{ $a['name'] }} to {{ $b['name'] }}</title></path>
    @endforeach
    </g>
    {{-- Bodies, each with its working drones as dots around it --}}
    @foreach($bodies as $b)
        @php($r = ($b['kind'] === 'moon' ? 3.2 : ($b['kind'] === 'dwarf' ? 3.6 : 5.5)) * $k * 1.4)
        @php($colour = $colours[$b['name']] ?? '#8a93ad')
        <g class="body" data-body="{{ $b['id'] }}"><title>{{ $b['name'] }}: {{ number_format($b['working']) }} {{ $b['working'] === 1 ? 'drone' : 'drones' }} working</title>
            <circle cx="{{ $b['x'] }}" cy="{{ $b['y'] }}" r="{{ round($r, 2) }}" fill="{{ $colour }}"/>
            @for($i = 0; $i < min($b['working'], 36); $i++)
                @php($ring = $r + (4.5 + 3.6 * intdiv($i, 12)) * $k)
                @php($t = 2 * M_PI * ($i % 12) / 12 + intdiv($i, 12) * 0.26)
                <circle class="drone" cx="{{ round($b['x'] + $ring * cos($t), 2) }}" cy="{{ round($b['y'] + $ring * sin($t), 2) }}"
                        r="{{ round(1.5 * $k, 2) }}" style="animation-delay: {{ ($i % 7) * 0.4 }}s"/>
            @endfor
            @if($b['working'] > 0 || in_array($b['id'], [3, 10, 4, 191], true) || $b['kind'] === 'planet')
                {{-- Earth's label below it and the Moon's above, as they sit close; big enough to read on a phone --}}
                <text x="{{ round($b['x'] + $r + 6 * $k, 1) }}" y="{{ round($b['id'] === 3 ? $b['y'] + $r + 18 * $k : $b['y'] - $r - 5 * $k, 1) }}" font-size="{{ round(16 * $k, 2) }}" @if(!($b['working'] > 0 || in_array($b['id'], [3, 10, 4, 191], true))) class="far" @endif>{{ $b['name'] }}@if($b['working'] > 0) <tspan class="count">{{ number_format($b['working']) }}</tspan>@endif</text>
            @endif
        </g>
    @endforeach
</svg>
