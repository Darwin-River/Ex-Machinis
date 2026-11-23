@extends('layouts.app')
@section('pageTitle', 'Map')
@section('content')
    {{-- The Map (#324 step 17): the planets where they are now, and the drones around each --}}
    <x-page-header :title="'Map'" />
    <section class="section section-xs section-first text-md-left">
        <div class="container" id="container">
            <div style="white-space: normal; margin-bottom: 1em">
                The planets where they are now, seen from above (distances on a log scale). Each shows the working
                drones and the wrecks around it, its moons included.
            </div>
            <svg viewBox="-90 -15 780 635" width="100%" style="max-width: 760px" role="img" aria-label="solar system map">
                <circle cx="300" cy="300" r="8" fill="#f5c542"/>
                @foreach($planets as $p)
                    <circle cx="300" cy="300" r="{{ $p['r'] }}" fill="none" stroke="#3a3a48" stroke-width="1"/>
                @endforeach
                {{-- Each label outward from the Sun, nudged clear of the labels and dots already placed (#716: Mars's ran over the Sun
                     and Mercury). Planets with drones are placed first, so theirs keep the best spot --}}
                @php
                    $labels = [];
                    $boxes = [[292, 292, 308, 308]];   // the Sun
                    foreach ($planets as $q) {
                        $boxes[] = [$q['x'] - 6, $q['y'] - 6, $q['x'] + 6, $q['y'] + 6];
                    }
                    $order = $planets;
                    usort($order, fn ($a, $b) => [$b['working'] + $b['wrecks'], $a['r']] <=> [$a['working'] + $a['wrecks'], $b['r']]);
                    foreach ($order as $q) {
                        $text = $q['name'] . ($q['working'] || $q['wrecks'] ? ' (' . $q['working'] . ($q['wrecks'] ? ', ' . $q['wrecks'] . ' wrecks' : '') . ')' : '');
                        $dx = $q['x'] - 300; $dy = $q['y'] - 300; $len = max(1, sqrt($dx * $dx + $dy * $dy));
                        $ux = $dx / $len; $uy = $dy / $len;
                        $anchor = $ux > 0.35 ? 'start' : ($ux < -0.35 ? 'end' : 'middle');
                        $w = 6.6 * mb_strlen($text);
                        $x = $q['x'] + $ux * 10;
                        $base = $q['y'] + $uy * 12 + 4;
                        $box = null;
                        foreach ([0, 14, -14, 28, -28, 42, -42] as $nudge) {
                            $y = $base + $nudge;
                            $x0 = $anchor === 'start' ? $x : ($anchor === 'end' ? $x - $w : $x - $w / 2);
                            $box = [$x0, $y - 10, $x0 + $w, $y + 3];
                            $clear = true;
                            foreach ($boxes as $b) {
                                if ($box[0] < $b[2] && $box[2] > $b[0] && $box[1] < $b[3] && $box[3] > $b[1]) { $clear = false; break; }
                            }
                            if ($clear) break;
                        }
                        $boxes[] = $box;
                        $labels[$q['id']] = ['x' => round($x, 1), 'y' => round($box[3] - 3, 1), 'anchor' => $anchor, 'text' => $text];
                    }
                @endphp
                @foreach($planets as $p)
                    @php($l = $labels[$p['id']])
                    <a href="{{ url('locations/' . $p['id']) }}">
                        <circle cx="{{ $p['x'] }}" cy="{{ $p['y'] }}" r="{{ $p['working'] ? 6 : 4 }}"
                                fill="{{ $p['working'] ? '#4fc3f7' : '#8a8a99' }}"/>
                        <text x="{{ $l['x'] }}" y="{{ $l['y'] }}" fill="#e6e6e6" font-size="12" text-anchor="{{ $l['anchor'] }}">{{ $l['text'] }}</text>
                    </a>
                @endforeach
            </svg>

            <h3 class="mt-4">Where the drones are</h3>
            <div class="table-custom-responsive">
                <table class="table-ticket" id="places">
                    <tr><th>Place</th><th>Working drones</th><th>Wrecks</th></tr>
                    @forelse($places as $place)
                        <tr>
                            <td><a href="{{ url('locations/' . $place['object_id']) }}">{{ $place['object_name'] }}</a></td>
                            <td>{{ $place['working'] }}</td><td>{{ $place['wrecks'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3">No drones yet.</td></tr>
                    @endforelse
                </table>
            </div>

            @if(!empty($farMap))
                <h3 class="mt-4" id="far-map">Where Metals (A3) come from</h3>
                <p>Metals (A3), which computers, lasers, batteries, reactors, most modules and every ship need, are refined only from
                    minerals found far from Earth, the Moon and Mars, and only on a few bodies at a time. The exchanges don't sell them,
                    but buy them. Every {{ $farMap['season_days'] }} real days the homes move; the next season's are shown
                    {{ $farMap['publish_days'] }} real days before it starts.</p>
                @foreach(['current' => 'This season', 'next' => 'Next season'] as $key => $title)
                    @php($s = $farMap[$key])
                    @if($s)
                        <h4>{{ $title }} (season {{ $s['season'] }}, {{ \Carbon\Carbon::parse($s['starts_at'])->format('j M') }} to
                            {{ \Carbon\Carbon::parse($s['ends_at'])->format('j M') }})</h4>
                        @if($s['homes'] === null)
                            <p>Shown from {{ \Carbon\Carbon::parse($s['published_at'])->format('j M') }}.</p>
                        @else
                            <div class="table-custom-responsive">
                                <table class="table-ticket" id="far-{{ $key }}">
                                    <tr><th>Mineral</th><th>Found at</th></tr>
                                    @foreach($s['homes'] as $m)
                                        <tr><td>{{ $m['name'] }}</td>
                                            <td>@foreach($m['places'] as $p)<a href="{{ url('locations/' . $p['object_id']) }}">{{ $p['name'] }}</a>@if(!$loop->last), @endif @endforeach</td></tr>
                                    @endforeach
                                </table>
                            </div>
                        @endif
                    @endif
                @endforeach
            @endif
        </div>
    </section>
@endsection
