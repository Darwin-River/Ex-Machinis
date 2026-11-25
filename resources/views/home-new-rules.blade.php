@extends('layouts.app')
@section('pageTitle', 'Home')
@push('styles')
    <style>
        /* The home page for the new rules (#697) */
        .home-hero { padding: 2.5rem 0 2rem; }
        .home-hero h1 { font-size: 2.6rem; line-height: 1.1; margin: 0 0 1rem; }
        .home-lead { font-size: 1.15rem; color: #c3c9d8; max-width: 34em; white-space: normal; }
        .home-code { background: #0f1424; border: 1px solid #263052; border-radius: 10px; padding: 16px 18px; margin: 0;
              font: 14px/1.6 "JetBrains Mono", ui-monospace, monospace; color: #cfe3ff; white-space: pre; overflow-x: auto; }



        .home-clock { color: #c3c9d8; font-size: 15px; margin: 0 0 1.5rem; white-space: normal; }
        #feed { width: 100%; }
        #feed td { white-space: normal; vertical-align: top; }
        #feed td:first-child { white-space: nowrap; color: #9aa3b8; padding-right: 1em; }
        @media (max-width: 991px) {
        @media (max-width: 480px) {
        /* A smaller hero on a phone (#905: 962 px, more than a screen, before the steps) */
        @media (max-width: 575px) {
            .home-hero { padding: 1.25rem 0 1rem; }
            .home-hero h1 { font-size: 1.65rem; margin-bottom: .6rem; }
            .home-hero .home-lead { font-size: .95rem; }
            .home-hero .hero-actions .button-lg { padding: .55rem 1rem; font-size: .95rem; }
            .home-hero .home-code { font-size: .74rem; padding: .6rem .75rem; margin-bottom: 0; }
        }
        /* A link inside a sentence is underlined, not told apart by colour alone (#885, axe-core) */
        .container p a:not(.button) { text-decoration: underline; text-underline-offset: 2px; }
    </style>
@endpush
@section('content')
    @php($n = fn ($v) => number_format($v))
    @php($path = \App\Services\Pipeline::for(auth()->user()))
    @php($nextStep = $path['next'] ? \App\Services\Pipeline::step($path, $path['next']) : null)
    <section class="home-hero">
        <div class="container">
            @if(!$path['player'])
                {{-- A visitor (#1049): step 0 is watching. The game in action first, then the call to join --}}
                <p class="hero-kicker">Live now &middot; free &middot; multiplayer &middot; open source</p>
                <h1>A living economy, run by code.</h1>
                <p class="home-lead">Players write short Lua programs that fly drones across the solar system to mine, make and trade.
                    This is the game right now.</p>
                @include('partials.home-live', ['map' => $map, 'trading' => $trading, 'tiles' => $dash['tiles'] ?? [], 'join' => true])
                <p class="mt-3 mb-0">Have an account? <a href="{{ url('login') }}">Log in</a>.</p>
            @else
                <div class="row align-items-center">
                    <div class="col-lg-7 mb-4 mb-lg-0">
                        <p class="hero-kicker">Free &middot; multiplayer &middot; open source</p>
                        <h1>Write the code.<br>Your drones run the company.</h1>
                        <p class="home-lead">A space-trading game you play in Lua. Code drones that mine, make and trade; grow your
                            companies, then invest in other players' and lend to them. A startup costs you nothing if it crashes:
                            <b>break, learn, repeat.</b></p>
                        {{-- The first button follows the path (#987, #1043): the player's next step --}}
                        <div class="hero-actions">
                            <a class="button button-primary button-lg" href="{{ $nextStep['url'] ?? url('release') }}">{{ $nextStep ? 'Next: ' . $nextStep['action'] : 'Found a startup' }}</a>
                            @if($nextStep)<a class="button button-secondary button-lg" href="{{ url('release') }}">Found a startup</a>@endif
                        </div>
                    </div>
                    <div class="col-lg-5">
{!! \App\Services\LuaHighlight::block(<<<'LUA'
-- A sketch of the Miner starter
function launched()
  go(moon)
  while true do
    dig(richer(kunspar, wolfite), 100)
    go(market())
    sell_out(metals())   -- to the House
    refuel()
  end
end
-- press Found startup, then watch its page
LUA, ['class' => 'home-code', 'aria-label' => 'A sketch of the Miner starter script']) !!}
                    </div>
                </div>
            @endif
        </div>
    </section>
    @if($path['player'])
        <section class="section pt-0">
            <div class="container">
                <h2 class="h4">The game now</h2>
                @include('partials.home-live', ['map' => $map, 'trading' => $trading, 'tiles' => $dash['tiles'] ?? [], 'join' => false])
            </div>
        </section>
    @endif

    <section class="section pt-0">
        <div class="container">
            {{-- What changed for you since you last looked (#771) --}}
            @include('partials.since-looked')
            @if (session('status'))
                <div class="alert alert-success">{{ session('status') }}</div>
            @endif
            {{-- The path through the game (#987), in eight steps (#1043) --}}
            <h2 class="h4">{{ $path['player'] ? 'Your path' : 'How you play: seven small steps' }}</h2>
            {{-- Step 0 is watching, which the visitor is doing here (#1058) --}}
            @include('partials.pipeline', ['path' => $path, 'skip' => ['watch']])
            @if(!$path['player'])
                <p class="mb-4" style="margin-top: -1rem"><a class="button button-primary" href="{{ url('try') }}">Join the Economy: fly your first drone</a></p>
            @endif

            {{-- The game clock in one plain line, honest when it is slowed (#697) --}}
            @php($s = $status)
            <p class="home-clock" id="game-clock"><b>Game clock:</b>
                @if(!$clock)
                    a game day takes about 48 real seconds.
                @elseif($s['slowed'])
                    slowed: a game day takes {{ \App\Services\LaunchQueue::dayPace($clock) }} now, not the usual
                    {{ str_replace('about ', '', \App\Services\LaunchQueue::dayPace(['scale' => $clock['target']])) }}, because the engines are behind{{ $s['engine']['behind_seconds'] !== null ? ' (' . $n($s['engine']['behind_seconds']) . ' real seconds)' : '' }}.
                    Give a startup a while.
                @else
                    a game day takes {{ \App\Services\LaunchQueue::dayPace($clock) }}, so you see your code at work within minutes.
                @endif
                @if($clock && $clock['full'])
                    <b>The world is full:</b> new releases wait their turn ({{ $clock['waiting'] }} waiting).
                @endif
            </p>

            {{-- The economy at a glance (#698) --}}
            <h2 class="h4">The economy</h2>
            @include('partials.dashboard-strip', ['dash' => $dash, 'dashLink' => true])

            {{-- Your own latest, above the world's (#771) --}}
            @php($mine = auth()->check() ? \App\Services\SinceLooked::feed(auth()->user()) : [])
            @if(count($mine))
                <h2 class="h4 mt-4">Yours, just now</h2>
                <table class="table-ticket" id="my-feed" v-pre>
                    @foreach($mine as $item)
                        <tr><td style="white-space: nowrap; color: #9aa3b8; padding-right: 1em">{{ $item['when'] }}</td><td style="white-space: normal">{{ $item['text'] }}</td></tr>
                    @endforeach
                </table>
            @endif

            <h2 class="h4 mt-4">Just now</h2>
            @if(empty($feed))
                <p>Nothing has happened yet.</p>
            @else
                <table class="table-ticket" id="feed" v-pre>
                    @foreach($feed as $item)
                        <tr><td>{{ $item['when'] }}</td><td>{!! $item['html'] !!}</td></tr>
                    @endforeach
                </table>
                <p class="mt-2"><a href="{{ url('market') }}">The market</a> &middot; <a href="{{ url('leaderboard') }}">Leaderboard</a>
                    &middot; <a href="{{ url('blog') }}">Strategy blog</a></p>
            @endif
        </div>
    </section>
@endsection
