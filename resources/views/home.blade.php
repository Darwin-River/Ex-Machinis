@extends('layouts.app')
@section('pageTitle', 'Home')
@section('content')
    {{-- Home (#524) --}}
    <section class="hero">
        <div class="container">
          <div class="row align-items-center">
           <div class="col-lg-7">
            <p class="hero-kicker">Free &middot; multiplayer &middot; open source</p>
            <h1 class="hero-title">Program drones. Mine the solar system.</h1>
            <p class="hero-lead">Ex Machinis is a space game you play by writing code. Your Lua scripts fly
                self-replicating spacecraft that mine, build and trade across our real solar system.
                <b>Break, learn, repeat.</b></p>
            <div class="hero-actions">
                <a class="button button-primary button-lg" href="{{ url('release') }}">Release a drone</a>
                <a class="button button-secondary button-lg" href="{{ url('getting-started') }}">Getting started</a>
            </div>
           </div>
           <div class="col-lg-5 d-none d-lg-block">
            {{-- Earth, the Moon and a drone's dotted transfer orbit (drawn here, no image to license) --}}
            <svg class="hero-art" viewBox="0 0 440 320" role="img" aria-label="A drone on its way from Earth to the Moon">
                <defs>
                    <radialGradient id="hero-earth" cx="35%" cy="35%" r="70%">
                        <stop offset="0" stop-color="#7fe0ff"/><stop offset="0.55" stop-color="#2a7fc4"/><stop offset="1" stop-color="#123a66"/>
                    </radialGradient>
                    <radialGradient id="hero-moon" cx="35%" cy="35%" r="70%">
                        <stop offset="0" stop-color="#f1f1f4"/><stop offset="1" stop-color="#8a8e9c"/>
                    </radialGradient>
                </defs>
                <g transform="translate(45 0)">
                <ellipse class="orbit" cx="150" cy="170" rx="185" ry="110" transform="rotate(-12 150 170)"/>
                <ellipse class="orbit" cx="150" cy="170" rx="95" ry="58" transform="rotate(-12 150 170)"/>
                <path class="orbit-path" d="M 205 128 C 260 60, 330 60, 330 78"/>
                <circle cx="150" cy="170" r="42" fill="url(#hero-earth)"/>
                <circle cx="330" cy="92" r="14" fill="url(#hero-moon)"/>
                <g transform="translate(262 72) rotate(20)">
                    <rect x="-7" y="-4" width="14" height="8" rx="2" fill="#ffb547"/>
                    <rect x="-17" y="-2" width="8" height="4" fill="#4cc9f0"/>
                    <rect x="9" y="-2" width="8" height="4" fill="#4cc9f0"/>
                </g>
                </g>
            </svg>
           </div>
          </div>
        </div>
    </section>

    <section class="section">
        <div class="container">
            @if (session('status'))
                <div class="alert alert-success">{{ session('status') }}</div>
            @endif
            <div class="row">
                <div class="col-lg-4 mb-5">
                    <h2>How it works</h2>
                    <ol class="steps">
                        <li><b>Write a script.</b> Start from a starter script or the <a href="{{ url('getting-started') }}">manual</a>. No account needed.</li>
                        <li><b>Release it.</b> It becomes a new company with one drone in orbit around Earth.</li>
                        <li><b>Watch it.</b> Its page shows where it goes, what it does, and what goes wrong.</li>
                        <li><b>Release a better one.</b> A game day passes in under a minute, so ten versions make a good evening.</li>
                    </ol>
                    <p><a href="{{ url('blog') }}">Read the strategy blog</a> for drones that worked.</p>
                </div>
                <div class="col-lg-8">
                    {{-- What's happening (#324 step 17) --}}
                    <h2>What's happening</h2>
                    <div style="white-space: normal; margin-bottom: 1em">
                        Drones run on their own: they mine, refine, trade, clone and die.
                        See the <a href="{{ url('market') }}">Market</a> and the <a href="{{ url('map') }}">Map</a>,
                        or <a href="{{ url('release') }}">release a script</a> of your own.
                    </div>
                    @if($clock)
                        {{-- The game clock, live (#324 step 20) --}}
                        <div id="game-clock" style="white-space: normal; margin-bottom: 1em">
                            <b>Game clock:</b> one real hour is {{ rtrim(rtrim(number_format($clock['scale'], 1), '0'), '.') }} game hours
                            @if($clock['floor'])
                                (it aims for {{ rtrim(rtrim(number_format($clock['target'], 1), '0'), '.') }}, and slows when the engines fall behind, never below
                                {{ rtrim(rtrim(number_format($clock['floor'], 1), '0'), '.') }}).
                            @else
                                .
                            @endif
                            @if($clock['lag'] !== null)
                                The engines are {{ number_format($clock['lag']) }} real seconds behind.
                            @endif
                            @if($clock['full'])
                                <b>The world is full:</b> new releases wait in the launch queue ({{ $clock['waiting'] }} waiting).
                            @endif
                        </div>
                    @endif
                    @if(empty($feed))
                        <div>Nothing has happened yet.</div>
                    @else
                        <table class="table-ticket" id="feed">
                            @foreach($feed as $item)
                                <tr>
                                    <td style="white-space: nowrap">{{ $item['when'] }}</td>
                                    <td style="white-space: normal">
                                        @if($item['link'])
                                            <a href="{{ url($item['link']) }}">{{ $item['text'] }}</a>
                                        @else
                                            {{ $item['text'] }}
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </table>
                    @endif
                </div>
            </div>
        </div>
    </section>
@endsection
