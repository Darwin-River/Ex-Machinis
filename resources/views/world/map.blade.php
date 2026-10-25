@extends('layouts.app')
@section('pageTitle', 'Map')
@section('content')
    {{-- The Map (#324 step 17): the planets where they are now, and the drones around each --}}
    <section class="section section-xs section-first bg-default text-md-left">
        <div class="container" id="container">
            <h2>Map</h2>
            <div style="white-space: normal; margin-bottom: 1em">
                The planets where they are now, seen from above (distances on a log scale). Each shows the working
                drones and the wrecks around it, its moons included; a square marks a House station.
            </div>
            <svg viewBox="-80 0 760 600" width="100%" style="max-width: 760px" role="img" aria-label="solar system map">
                <circle cx="300" cy="300" r="8" fill="#f5c542"/>
                @foreach($planets as $p)
                    <circle cx="300" cy="300" r="{{ $p['r'] }}" fill="none" stroke="#3a3a48" stroke-width="1"/>
                @endforeach
                @foreach($planets as $p)
                    <a href="{{ url('locations/' . $p['id']) }}">
                        <circle cx="{{ $p['x'] }}" cy="{{ $p['y'] }}" r="{{ $p['working'] ? 6 : 4 }}"
                                fill="{{ $p['working'] ? '#4fc3f7' : '#8a8a99' }}"/>
                        @if($p['stations'])
                            <rect x="{{ $p['x'] - 9 }}" y="{{ $p['y'] - 9 }}" width="18" height="18" fill="none" stroke="#f5a623" stroke-width="1.5"/>
                        @endif
                        @php
                            $label = $p['name'];
                            if ($p['working'] || $p['wrecks']) {
                                $label .= ' (' . $p['working'] . ($p['wrecks'] ? ', ' . $p['wrecks'] . ' wrecks' : '') . ')';
                            }
                        @endphp
                        <text x="{{ $p['x'] + 10 }}" y="{{ $p['y'] + 4 }}" fill="#e6e6e6" font-size="12">{{ $label }}</text>
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
        </div>
    </section>
@endsection
