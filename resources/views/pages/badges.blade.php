@extends('layouts.app')
@php $title = 'Badges' ; @endphp
@section('pageTitle', $title)
@push('styles')
<style>
    /* Every badge described (#1055): each at its own anchor, which every badge shown on the site links to */
    .bd-jump { display: flex; flex-wrap: wrap; gap: 8px 16px; margin: 0 0 1.5rem; }
    .bd-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 300px), 1fr)); gap: 12px; margin: 0 0 2rem; padding: 0; list-style: none; }
    .bd-card { background: #111729; border: 1px solid #263052; border-radius: 10px; padding: 14px 16px; display: grid; gap: 6px; align-content: start;
        scroll-margin-top: 90px; white-space: normal; }
    .bd-card:target { border-color: #ffb547; box-shadow: 0 0 0 2px #ffb547 inset; }
    .bd-card h3 { display: flex; align-items: center; gap: 10px; font-size: 1.1rem; margin: 0; }
    .bd-card h3 a { color: inherit; text-decoration: none; }
    .bd-card h3 a:hover, .bd-card h3 a:focus-visible { text-decoration: underline; }
    .bd-medal { width: 26px; height: 26px; border-radius: 50%; flex: none; display: inline-block;
        background: radial-gradient(circle at 35% 35%, #ffd27a, #c98500); box-shadow: inset 0 0 0 2px rgba(0,0,0,.25); }
    .bd-medal.tier-bronze { background: radial-gradient(circle at 35% 35%, #f0b07a, #9c5a2a); }
    .bd-medal.tier-silver { background: radial-gradient(circle at 35% 35%, #f2f4f8, #8a93ad); }
    .bd-medal.tier-gold { background: radial-gradient(circle at 35% 35%, #ffe9a3, #d9a400); }
    .bd-card p { margin: 0; color: #c3c9d8; font-size: 15px; }
    .bd-tiers { display: grid; gap: 4px; margin: 2px 0 0; padding: 0; list-style: none; font-size: 14px; color: #c3c9d8; }
    .bd-tiers li { display: flex; align-items: center; gap: 8px; }
    .bd-tiers .bd-medal { width: 16px; height: 16px; }
    .bd-count { color: #9aa3b8; font-size: 13.5px; font-variant-numeric: tabular-nums; }
</style>
@endpush
@section('content')
    <x-page-header :title="$title" :subtitle="'Recognition for what drones, companies and players achieve.'" />
    <section class="section section-xs section-first text-md-left">
        <div class="container">
            <p class="lead" style="white-space: normal; max-width: 46em">Badges recognize what your drones, your companies and you achieve. They give no
                advantage in the game: no credits, no discounts, nothing any rule reads. Every few real minutes the game looks at its own records and
                awards any badge that has been earned since, and an award is kept for good. Some come in tiers, <b>bronze</b>, <b>silver</b> and
                <b>gold</b>, at the numbers below.</p>
            <p style="white-space: normal; max-width: 46em; color: #c3c9d8">Everyone earns them by the same rules. Buying your own company's shares or
                lending to it earns none, and only trades between different players count for the trading badges. In a class, pupils' badges show to
                the pupil and their teacher only. Your badges are on <a href="{{ url('account') }}">your account page</a>, a company's on its page and
                a drone's on its page. More in the manual: <a href="{{ url('manual#badges') }}">Badges</a>.</p>
            <nav class="bd-jump" aria-label="Badges by what earns them">
                @foreach(\App\Services\Badges::GROUPS as $kind => $heading)
                    <a href="#{{ $kind }}-badges">{{ $heading }} ({{ count($catalog[$kind]) }})</a>
                @endforeach
            </nav>
            @foreach(\App\Services\Badges::GROUPS as $kind => $heading)
                <h2 class="h4" id="{{ $kind }}-badges" style="scroll-margin-top: 90px">{{ $heading }}</h2>
                <ul class="bd-grid">
                    @foreach($catalog[$kind] as $b)
                        <li class="bd-card" id="{{ $b['key'] }}">
                            <h3><span class="bd-medal {{ $b['tiers'] ? 'tier-gold' : '' }}" aria-hidden="true"></span>
                                <a href="#{{ $b['key'] }}">{{ $b['name'] }}</a></h3>
                            <p>{{ $b['text'] }}</p>
                            @if($b['tiers'])
                                <ul class="bd-tiers" aria-label="Its tiers">
                                    @foreach($b['tiers'] as $t)
                                        <li><span class="bd-medal tier-{{ $t['name'] }}" aria-hidden="true"></span>
                                            <span><b>{{ ucfirst($t['name']) }}</b> at {{ number_format($t['threshold']) }} {{ $b['unit'] }}</span>
                                            <span class="bd-count">&middot; {{ number_format($t['earned']) }} earned</span></li>
                                    @endforeach
                                </ul>
                            @else
                                <span class="bd-count">{{ number_format($b['earned']) }} {{ $b['earned'] === 1 ? 'has' : 'have' }} earned it</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endforeach
        </div>
    </section>
@endsection
