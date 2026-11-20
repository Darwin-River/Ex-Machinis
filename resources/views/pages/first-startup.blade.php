@extends('layouts.app')
@section('pageTitle', 'Your first startup')
@push('styles')
    <style>
        /* The first-startup guide (#719): five steps on one screen each, no sideways scroll at 390 px */
        .fs > * { max-width: 60rem; }
        .fs-lead { font-size: 1.1rem; color: #c3c9d8; max-width: 44em; margin: 0 0 1.25rem; }
        .fs-tiles { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin: 0 0 .5rem; }
        .fs-tile { background: #111729; border: 1px solid rgba(255,255,255,.1); border-radius: 10px; padding: 12px 14px; min-width: 0; }
        .fs-tile small { display: block; color: #9aa3b8; font-size: 12px; letter-spacing: .06em; text-transform: uppercase; }
        .fs-tile b { display: block; font-family: "Space Grotesk", system-ui, sans-serif; font-size: 1.6rem; line-height: 1.3; font-variant-numeric: tabular-nums; }
        .fs-tile span { display: block; color: #c3c9d8; font-size: 13px; }
        .fs-src { color: #9aa3b8; font-size: 12px; margin: .25rem 0 0; }
        .fs-src code { color: inherit; }
        .fs-steps { list-style: none; padding: 0; margin: 2rem 0 0; counter-reset: fs; }
        .fs-step { counter-increment: fs; position: relative; padding: 0 0 2rem 3.25rem; border-left: 2px solid #263052; margin-left: 1rem; }
        .fs-step:last-child { border-left-color: transparent; padding-bottom: .5rem; }
        .fs-step::before { content: counter(fs); position: absolute; left: -1.05rem; top: -.1rem; width: 2rem; height: 2rem; border-radius: 50%;
              display: flex; align-items: center; justify-content: center; background: #19213a; border: 1px solid #ffb547;
              color: #ffb547; font-family: "Space Grotesk", system-ui, sans-serif; font-weight: 700; }
        .fs-step h2 { font-size: 1.35rem; margin: 0 0 .4rem; }
        .fs-step p { max-width: 44em; }
        .fs-time { color: #9aa3b8; font-size: 13px; font-weight: 400; font-family: system-ui, sans-serif; margin-left: .4rem; }
        .fs-cards { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin: .75rem 0; }
        .fs-card { display: flex; flex-direction: column; background: #111729; border: 1px solid rgba(255,255,255,.1); border-radius: 10px;
              padding: 14px 16px; color: inherit; text-decoration: none !important; min-width: 0; }
        .fs-card:hover, .fs-card:focus { border-color: #ffb547; }
        .fs-card .fs-role { color: #ffb547; font-size: 12px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
        .fs-card h3 { font-size: 1.15rem; margin: .2rem 0 .3rem; }
        .fs-card p { color: #c3c9d8; font-size: 14px; margin: 0 0 .6rem; flex: 1; }
        .fs-card dl { display: grid; grid-template-columns: auto 1fr; gap: 0 .6rem; font-size: 13px; margin: 0 0 .6rem; }
        .fs-card dt { color: #9aa3b8; font-weight: 400; }
        .fs-card dd { margin: 0; }
        .fs-card .fs-go { color: #ffb547; font-weight: 600; font-size: 14px; }
        .fs-note { color: #9aa3b8; font-size: 14px; }
        .fs-road { background: #111729; border: 1px solid rgba(255,255,255,.1); border-radius: 10px; padding: 14px 16px 10px; margin: .75rem 0; max-width: 40rem; }
        .fs-road h3 { font-size: 1rem; margin: 0; }
        .fs-bar { position: relative; height: 14px; background: #263052; border-radius: 99px; margin: 30px 6px 34px; }
        .fs-bar i { position: absolute; left: 0; top: 0; bottom: 0; border-radius: 99px; background: #ffb547; }
        .fs-mk { position: absolute; top: -6px; bottom: -6px; width: 2px; background: #e6e9f2; }
        .fs-mk em { position: absolute; font-style: normal; font-size: 12px; color: #c3c9d8; white-space: nowrap; transform: translateX(-50%); }
        .fs-mk.fs-up em { top: -20px; } .fs-mk.fs-down em { bottom: -20px; }
        .fs-mk.fs-end em { transform: translateX(-100%); }
        .fs-code { background: #0f1424; border: 1px solid #263052; border-radius: 10px; padding: 12px 14px; margin: .5rem 0 .75rem;
              font: 13px/1.6 "JetBrains Mono", ui-monospace, monospace; color: #cfe3ff; white-space: pre; overflow-x: auto; max-width: 44rem; }
        .fs-code del { color: #ff8a7a; text-decoration: none; } .fs-code ins { color: #5ad19a; text-decoration: none; }
        .fs-actions { display: flex; flex-wrap: wrap; gap: .6rem; margin: .75rem 0 0; }
        .fs-next { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin: .75rem 0 0; padding: 0; list-style: none; }
        .fs-next a { display: block; background: #111729; border: 1px solid rgba(255,255,255,.1); border-radius: 10px; padding: 12px 14px; height: 100%;
              color: inherit; text-decoration: none; }
        .fs-next a:hover, .fs-next a:focus { border-color: #ffb547; }
        .fs-next b { display: block; color: #ffb547; }
        .fs-next span { color: #c3c9d8; font-size: 14px; }
        @media (max-width: 860px) { .fs-tiles { grid-template-columns: 1fr 1fr; } .fs-cards { grid-template-columns: 1fr; } }
        @media (max-width: 420px) { .fs-tile b { font-size: 1.3rem; } .fs-step { padding-left: 1.9rem; margin-left: .9rem; } }
    </style>
@endpush
@section('content')
    @php($n = fn ($v) => number_format($v))
    {{-- The time to mature comes from the starter cards (#870), so the two never disagree --}}
    @php($cardDays = collect(\App\Services\Release::STARTER_CARDS)->pluck('days')->filter())
    @php($realMin = fn ($d) => (int) round($d * 0.8))
    <x-page-header :title="'Your first startup'" :subtitle="'Five minutes to found a startup from a starter script, and about ' . $realMin($cardDays->min(fn ($d) => $d[0])) . ' to ' . $realMin($cardDays->max(fn ($d) => $d[1])) . ' real minutes, at the normal pace, until it matures into a company of your own: pick a starter, start it, read its page, push a fix, mature it.'">
        <li class="active">Getting started</li>
    </x-page-header>
    <section class="section section-xs section-first">
        <div class="container fs" id="first-startup">
            <p class="fs-lead">A <b>startup</b> is a company the bank lends you for free. Your Lua code runs its drone on its
                own; if it fails, close it and it costs you nothing. Here is the whole way from nothing to its
                {{ $startup['shares'] }} shares, with the numbers in force today.</p>

            <div class="fs-tiles" id="startup-numbers">
                <div class="fs-tile"><small>The bank lends</small><b>{{ $n($startup['grant']) }}</b>
                    <span>drone {{ $n($startup['hull']) }}, credits {{ $n($startup['credits']) }}, fee {{ $n($startup['fee']) }}</span></div>
                <div class="fs-tile"><small>It matures at</small><b>{{ $n($startup['mature_at']) }}</b>
                    <span>credits: its debt and {{ $n($startup['margin']) }} to work with</span></div>
                <div class="fs-tile"><small>Then you own</small><b>{{ $startup['shares'] }} shares</b>
                    <span>each paid a share of its profit every game day</span></div>
                <div class="fs-tile"><small>A game day takes</small><b>{{ str_replace(['about ', ' real seconds', ' real minutes', ' real hours'], ['', ' s', ' min', ' h'], \App\Services\LaunchQueue::dayPace($clock)) }}</b>
                    <span>real time, right now</span></div>
            </div>
            <p class="fs-src">Data: <code>GET /api/v1/clock</code> for the pace; the rest are the rules in force (the <a href="{{ url('manual#releasing-a-startup') }}">manual</a> has them all).</p>

            <ol class="fs-steps">
                <li class="fs-step" id="pick">
                    <h2>Which starter should I pick? <span class="fs-time">1 minute</span></h2>
                    <p>Each starter is a whole working script, explained in its comments, that buys its own equipment at
                        Earth and sells to the House. Pick one; you can change everything later.</p>
                    <div class="fs-cards">
                        @foreach($starters as $name => $what)
                            @php($card = \App\Services\Release::STARTER_CARDS[$name])
                            <a class="fs-card" href="{{ url('release?starter=' . $name) }}" id="starter-{{ $name }}">
                                <span class="fs-role">{{ $card['role'] }}</span>
                                <h3>{{ ucfirst($name) }}</h3>
                                <p>{{ $card['line'] }}</p>
                                <dl><dt>Buys</dt><dd>a {{ $card['kit'] }} (2,000)</dd><dt>Matures in</dt><dd>{{ $card['mature'] }}</dd></dl>
                                <span class="fs-go">Start with the {{ ucfirst($name) }} &rarr;</span>
                            </a>
                        @endforeach
                    </div>
                    <p class="fs-note">Not sure? The <b>Miner</b> is the simplest: dig, refine, sell, repeat. The Trader starter
                        isn't offered to startups: it buys from other drones, and a startup trades only with the House.
                        Times are from a playtest at the normal pace (48 real seconds a game day), unchanged code; a crowded world takes longer.</p>
                </li>

                <li class="fs-step" id="start">
                    <h2>How do I start it? <span class="fs-time">1 minute</span></h2>
                    <p><a href="{{ url('signup') }}">Make an account</a> or <a href="{{ url('login') }}">log in</a> first: every
                        startup belongs to a player, and the account is free. On <a href="{{ url('release') }}">Found a startup</a>,
                        your starter's code is already in the box: give the company a name and press <b>Found startup</b>.
                        The bank lends it {{ $n($startup['grant']) }}: a drone ({{ $n($startup['hull']) }}),
                        {{ $n($startup['credits']) }} credits to spend and a {{ $n($startup['fee']) }} fee. Its first act is to buy its
                        kit for 2,000, so it starts with about 1,000 credits.</p>
                    <div class="fs-actions"><a class="button button-primary" href="{{ url('release') }}">Found a startup</a></div>
                </li>

                <li class="fs-step" id="read">
                    <h2>Is it working? <span class="fs-time">2 minutes</span></h2>
                    <p>You land on its drone's page: what it does and says, its fuel and its trades. Its <b>company page</b>
                        (the company's name, at the top) has the <b>Road to maturity</b>: its credits against its debt, and the
                        mark it matures at. Watch the bar move. At this pace a few minutes show you days of its life.</p>
                    @php($w = fn ($v) => round(100 * $v / $startup['cap'], 1))
                    <figure class="fs-road" aria-label="Road to maturity, a fresh startup after buying its kit">
                        <h3>Road to maturity <span class="fs-time">a fresh startup, after its kit</span></h3>
                        <div class="fs-bar">
                            <i style="width: {{ $w(1000) }}%"></i>
                            <span class="fs-mk fs-up" style="left: {{ $w(1000) }}%"><em>credits 1,000</em></span>
                            <span class="fs-mk fs-down" style="left: {{ $w($startup['grant']) }}%"><em>debt {{ $n($startup['grant']) }}</em></span>
                            <span class="fs-mk fs-up" style="left: {{ $w($startup['mature_at']) }}%"><em>matures {{ $n($startup['mature_at']) }}</em></span>
                            <span class="fs-mk fs-down fs-end" style="left: 100%"><em>cap {{ $n($startup['cap']) }}</em></span>
                        </div>
                        <figcaption class="fs-note">It holds at most {{ $n($startup['cap']) }}: what it earns above that goes to the bank,
                            so mature it once it can.</figcaption>
                    </figure>
                    <p>Stuck? Its page says what it is waiting for, and a drone that dies gets a <b>post-mortem</b> that says why.</p>
                </li>

                <li class="fs-step" id="fix">
                    <h2>What if it goes wrong? <span class="fs-time">1 minute</span></h2>
                    <p>Push a fix. On its company page, <b>Push new code</b> takes your edited script to its drones at the speed
                        of light; it starts over on it, keeping its hold and its place. The first push is free, each after it adds
                        {{ $n($startup['push_fee']) }} to the debt. Treat a change as an experiment: note its credits before, push, and
                        compare a few game days later. For example, the Miner sells once it has 40 metals aboard; with more aboard it
                        makes fewer trips, each worth more, if the <a href="{{ url('market') }}">Market board</a> shows the House
                        still wants them:</p>
<pre class="fs-code lua" v-pre aria-label="A one-line change to the Miner"><span class="c">-- metals it keeps on offer before it goes to sell</span>
<del>- hold_cap = 40</del>
<ins>+ hold_cap = 60</ins></pre>
                    <p>It may help or not: in one playtest, a Miner told its time was worth less (<code>hour_value</code> 2) flew to the
                        lowest bids and matured later than an unchanged one. A change that doesn't
                        work is fine: push another, or <b>close</b> the startup. Its debt is cancelled, and a game day later its slot is
                        free (you run up to {{ $startup['max'] }} at a time). Break, learn, repeat.</p>
                </li>

                <li class="fs-step" id="mature">
                    <h2>When is it mine? <span class="fs-time">when it gets there</span></h2>
                    <p>Once it holds {{ $n($startup['mature_at']) }} credits (its debt and {{ $n($startup['margin']) }} more; each paid push
                        adds {{ $n($startup['push_fee']) }}), a <b>Mature</b> button appears on its Road to maturity, on your account page, and in the strip under the menu on every page, which also says when it should mature at its pace.
                        Maturing repays the bank and locks its code for good. From then on it trades with everyone, and its
                        {{ $startup['shares'] }} shares are yours: each game day it pays half its profit to its shareholders.
                        Keep them, sell them to the bank, or trade them with other players.</p>
                </li>
            </ol>

            <h2 class="h4 mt-4" id="next">What next?</h2>
            <ul class="fs-next">
                <li><a href="{{ url('manual') }}"><b>The manual</b><span>The whole game, chapter by chapter.</span></a></li>
                <li><a href="https://github.com/Darwin-River/Ex-Machinis/blob/main/documents/lua-functions.md"><b>Lua functions</b><span>Every function a drone can call.</span></a></li>
                <li><a href="{{ url('market') }}"><b>The market</b><span>Where each good is cheapest and where it pays most.</span></a></li>
                <li><a href="{{ url('blog') }}"><b>Strategy blog</b><span>How the best companies were written.</span></a></li>
                @if(config('forge.new_rules'))<li><a href="{{ url('market/trade') }}"><b>Trade shares</b><span>The Trading desk: buy into matured companies, earn their dividends, or write a trading script.</span></a></li>@endif
            </ul>
        </div>
    </section>
    <script>
        // The manual lived here until #719: an old /getting-started#anchor link goes on to /manual#anchor
        (function () {
            var anchors = @json($anchors);
            var id = decodeURIComponent(location.hash.slice(1));
            if (id && !document.getElementById(id) && anchors.indexOf(id) !== -1) {
                location.replace('{{ url('manual') }}#' + encodeURIComponent(id));
            }
        })();
    </script>
@endsection
