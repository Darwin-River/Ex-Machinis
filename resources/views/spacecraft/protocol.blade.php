@extends('layouts.app')
@php $title = $protocol->name; @endphp
@section('pageTitle', $title)
@section('content')
    <x-page-header :title="$title" />
    <section class="section section-xs section-first text-md-left">
        <div class="container" id="container">
            <p><a href="{{ url('/protocols') }}">Protocols</a> / {{ $protocol->id }}</p>

            <p>{{ $protocol->description }}</p>

            <h4 class="mt-4">Performing it</h4>
            <ul>
                <li><b>Protocol ID:</b> {{ $protocol->id }}</li>
                <li><b>Parameters:</b> {{ $protocol->parameters }}@if($protocol->parameters > 0) (the Lua call at the start of the description above shows their order)@endif</li>
                @if($details['travel'])
                    <li><b>Destination:</b> a Location ID (see <a href="{{ url('/locations') }}">Locations</a>), for example <code>travel(moon)</code> or <code>perform({{ $protocol->id }}, 10)</code> to go to the Moon.</li>
                @endif
                @if($protocol->multiplier)
                    <li><b>Quantity:</b> the first parameter, N, is how many units: for example <code>push(5, kunspar, 52)</code> moves five.</li>
                @endif
                <li><b>Result:</b> the call waits until the action is finished, then returns 0 if it worked or a negative code saying why it failed (see the players' manual).</li>
            </ul>

            <h4 class="mt-4">How long it takes</h4>
            @if($details['travel'])
                <p>It depends on the distance: drones accelerate to the halfway point and slow down after, so a trip takes 2 × √(distance ÷ acceleration), with the distance measured when the drone leaves. The players' manual lists example trip times.</p>
            @else
                <p>{{ \App\Protocol::humanDuration($details['duration']) }} of game time, which is {{ $details['duration'] / $scale < 1 ? 'under a second' : \App\Protocol::humanDuration($details['duration'] / $scale) }} in real time.</p>
            @endif
            <p class="small">Game time runs {{ $scale + 0 }} times faster than real time.</p>

            @if($details['modules'])
                <h4 class="mt-4">Modules needed</h4>
                <p>The drone must carry these. They are {{ collect($details['modules'])->contains('locked', true) ? 'locked (in use) while the action runs and' : '' }} kept by the drone.</p>
                <ul>
                    @foreach($details['modules'] as $item)
                        <li><a href="{{ url('/resources?keyword=' . urlencode($item['name'])) }}">{{ $item['name'] }}</a> ({{ $item['id'] }})</li>
                    @endforeach
                </ul>
            @endif

            @if($details['consumed'])
                <h4 class="mt-4">Uses up</h4>
                <ul>
                    @foreach($details['consumed'] as $item)
                        <li>{{ $item['quantity'] }} × <a href="{{ url('/resources?keyword=' . urlencode($item['name'])) }}">{{ $item['name'] }}</a> ({{ $item['id'] }})</li>
                    @endforeach
                </ul>
            @endif

            @if($details['produced'])
                <h4 class="mt-4">Produces</h4>
                <ul>
                    @foreach($details['produced'] as $item)
                        <li>{{ $item['quantity'] }} × <a href="{{ url('/resources?keyword=' . urlencode($item['name'])) }}">{{ $item['name'] }}</a> ({{ $item['id'] }})@if($item['mined']), times how abundant it is at the drone's location (see <a href="{{ url('/locations') }}">Locations</a>)@endif</li>
                    @endforeach
                </ul>
            @endif

            @if($details['steps'])
                <h4 class="mt-4">Step by step</h4>
                <div class="mb-4 table-responsive">
                    <table class="table-custom table-custom-bordered">
                        <tr><th>Game time</th><th>What happens</th></tr>
                        @foreach($details['steps'] as $step)
                            <tr><td>{{ \App\Protocol::humanDuration($step['time']) }}</td><td>{{ $step['what'] }}</td></tr>
                        @endforeach
                    </table>
                </div>
                <p class="small">If any step can't happen (for example, a module is missing or the cargo hold is full), the action stops there and the call returns the reason.</p>
            @endif
        </div>
    </section>
@endsection
