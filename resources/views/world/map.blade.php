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
            {{-- A resource's heat map over the map (#952) --}}
            <form method="get" action="{{ url('map') }}" id="resource-map-form" class="mb-3" style="display: flex; flex-wrap: wrap; gap: .5em; align-items: center">
                <label for="map-resource">Show where it's found:</label>
                <select name="resource" id="map-resource">
                    <option value="0">Nothing (drones only)</option>
                    <optgroup label="Refined goods (from the minerals that make them)">
                        @foreach(collect($options)->where('kind', 'refined') as $o)
                            <option value="{{ $o['resource'] }}" @selected($heat && $heat['resource']['resource'] === $o['resource'])>{{ $o['name'] }}</option>
                        @endforeach
                    </optgroup>
                    <optgroup label="Minerals">
                        @foreach(collect($options)->where('kind', 'mineral') as $o)
                            <option value="{{ $o['resource'] }}" @selected($heat && $heat['resource']['resource'] === $o['resource'])>{{ $o['name'] }}</option>
                        @endforeach
                    </optgroup>
                </select>
                <select name="season" id="map-season" aria-label="Season">
                    <option value="now">this season</option>
                    @if(!empty($farMap['next']['published']))<option value="next" @selected($heat && $heat['next'])>next season</option>@endif
                </select>
                <button type="submit" class="button button-sm button-primary">Show</button>
            </form>
            <svg viewBox="-90 -15 780 635" width="100%" style="max-width: 760px" role="img" aria-label="solar system map{{ $heat ? ', showing where ' . $heat['resource']['name'] . ' is found' : '' }}">
                <defs>
                    <radialGradient id="map-heat">
                        <stop offset="0%" stop-color="#ff8c42" stop-opacity="0.95"/>
                        <stop offset="55%" stop-color="#ff8c42" stop-opacity="0.45"/>
                        <stop offset="100%" stop-color="#ff8c42" stop-opacity="0"/>
                    </radialGradient>
                </defs>
                <circle cx="300" cy="300" r="8" fill="#f5c542"/>
                @foreach($planets as $p)
                    <circle cx="300" cy="300" r="{{ $p['r'] }}" fill="none" stroke="#3a3a48" stroke-width="1"/>
                @endforeach
                @if($heat)
                    {{-- The glow: brighter and wider where the resource is richer and on more bodies --}}
                    @foreach($heat['bodies'] as $b)
                        @if($b['heat'] > 0)
                            <a href="{{ url('locations/' . $b['places'][0]['object_id']) }}" class="map-heat">
                                <circle cx="{{ $b['x'] }}" cy="{{ $b['y'] }}" r="{{ round(10 + 26 * sqrt($b['heat'] / max(1, $heat['max_heat'])), 1) }}" fill="url(#map-heat)">
                                    <title>{{ $b['name'] }}: {{ collect($b['places'])->map(fn ($p) => $p['name'] . ' (' . $p['mineral_name'] . ', ' . $p['multiplier'] . ')')->implode('; ') }}</title>
                                </circle>
                            </a>
                        @endif
                    @endforeach
                    {{-- The asteroids and comets: labelled where the resource is --}}
                    @foreach($heat['bodies'] as $b)
                        @if($b['type'] !== 'Planet')
                            <a href="{{ url('locations/' . $b['id']) }}"><circle cx="{{ $b['x'] }}" cy="{{ $b['y'] }}" r="2.5" fill="{{ $b['heat'] > 0 ? '#ffd29e' : '#8a8a99' }}"><title>{{ $b['name'] }} ({{ strtolower($b['type']) }})</title></circle>
                                @if($b['heat'] > 0)<text x="{{ $b['x'] + 6 }}" y="{{ $b['y'] - 6 }}" fill="#ffd29e" font-size="11">{{ $b['name'] }}</text>@endif</a>
                        @endif
                    @endforeach
                @endif
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

            @if($heat)
                <h3 class="mt-4" id="resource-map">Where {{ $heat['resource']['name'] }} {{ $heat['resource']['kind'] === 'refined' ? 'comes from' : 'is found' }}{{ $heat['next'] ? ', next season' : '' }}</h3>
                <p>@if($heat['resource']['kind'] === 'refined')Refined from {{ collect($heat['minerals'])->pluck('name')->implode(', ') }}. @endif
                    The glow is brighter where it is richer and on more of a planet's moons and rings (the number is its abundance: the units a mining run brings in).
                    @if(collect($heat['minerals'])->contains('moves', true)) Some of these move each season (see below).@endif</p>
                @php($found = collect($heat['bodies'])->where('heat', '>', 0)->sortByDesc('heat'))
                <div class="table-custom-responsive">
                    <table class="table-ticket" id="resource-places">
                        <tr><th>Around</th><th>Found at</th></tr>
                        @forelse($found as $b)
                            <tr><td>{{ $b['name'] }}</td>
                                {{-- The richest six; a mineral found on every moon would list dozens --}}
                                <td>@foreach(array_slice($b['places'], 0, 6) as $p)<a href="{{ url('locations/' . $p['object_id']) }}">{{ $p['name'] }}</a> ({{ $p['mineral_name'] }}, {{ $p['multiplier'] }})@if(!$loop->last), @endif @endforeach
                                    @if(count($b['places']) > 6) and {{ count($b['places']) - 6 }} more @endif</td></tr>
                        @empty
                            <tr><td colspan="2">Not found anywhere {{ $heat['next'] ? 'next season' : 'now' }}.</td></tr>
                        @endforelse
                    </table>
                </div>
                <p class="ex-src">Data: <code>GET /api/v1/resource-map?resource={{ $heat['resource']['resource'] }}{{ $heat['next'] ? '&season=next' : '' }}</code></p>
            @endif

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

            {{-- The wreck field (#929): what is left to salvage, and who took from each wreck --}}
            <h3 class="mt-4" id="wreck-field">The wreck field</h3>
            <p>Wrecks keep their modules and hull for salvage: a drone with a Salvager (A1) recovers a module a run, then scrap from the
                hull, which every exchange buys. A wreck decays by 2% of what is left each game day, so salvage is a race. A startup's
                wrecks can't be plundered or salvaged.</p>
            <div class="table-custom-responsive">
                <table class="table-ticket" id="wrecks">
                    <tr><th>Wreck</th><th>Where</th><th>Wrecked</th><th class="num">Condition</th><th class="num">Modules</th>
                        <th class="num">Scrap</th><th>Taken by</th></tr>
                    @forelse($wrecks as $w)
                        <tr>
                            <td><a href="{{ url('spacecraft/' . $w['drone']) }}">{{ $w['name'] }}</a> <small>({{ $w['company'] }}{{ $w['startup'] ? ', a startup' : '' }})</small></td>
                            <td>@if($w['place'])<a href="{{ url('locations/' . $w['place']) }}">{{ $w['place_name'] }}</a>@endif</td>
                            <td>{{ $w['wrecked'] }}</td>
                            <td class="num">{{ $w['condition'] }}%</td><td class="num">{{ $w['modules'] }}</td><td class="num">{{ $w['scrap'] }}</td>
                            <td>@if($w['plundered_by'])plundered by <a href="{{ url('spacecraft/' . $w['plundered_by']) }}">{{ $w['plundered_by'] }}</a>@endif
                                @if($w['salvaged_by']) salvaged by <a href="{{ url('spacecraft/' . $w['salvaged_by']) }}">{{ $w['salvaged_by'] }}</a>@endif
                                @if($w['same_player']) <span class="badge" title="The same player is behind the wreck and the drone that took from it">same player</span>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="7">No wrecks with anything left to salvage.</td></tr>
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
