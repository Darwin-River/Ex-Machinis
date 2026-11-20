@extends('layouts.app')
{{-- "Found a startup" is the action's one name under the new rules (#719) --}}
@php $newRules = (bool) config('forge.new_rules'); $title = $newRules ? 'Found a startup' : 'Release a drone' ; @endphp
@section('pageTitle', $title)
@push('styles')
    <style>
        /* The Found a startup page (#719): starter cards, a short dark note with the rules folded, styled controls */
        .rl > * { max-width: 60rem; }
        .rl-lead { color: #c3c9d8; max-width: 46em; white-space: normal; }
        .rl-note { background: #111729; border: 1px solid rgba(255,255,255,.1); border-left: 4px solid #ffb547; border-radius: 10px;
              padding: 12px 16px; margin: 1rem 0; white-space: normal; }
        .rl-note p { margin: 0; }
        .rl-note.rl-warn { border-left-color: #ffd166; }
        .rl-note.rl-bad { border-left-color: #ff6b6b; }
        .rl-note.rl-bad ul { margin: 0; padding-left: 1.2rem; }
        .rl-rules { margin: .5rem 0 0; }
        .rl-rules summary { cursor: pointer; color: #ffb547; font-weight: 600; display: inline-block; padding: 2px 0; }
        .rl-rules summary::before { content: "+ "; } .rl-rules[open] summary::before { content: "\2212  "; }
        .rl-rules summary:focus-visible { outline: 2px solid #4cc9f0; outline-offset: 2px; }
        .rl-rules ul { margin: .5rem 0 0; padding-left: 1.2rem; color: #c3c9d8; font-size: 15px; }
        .rl-rules li { margin: .2rem 0; }
        .rl-legend { font-family: "Space Grotesk", system-ui, sans-serif; font-weight: 700; font-size: 1.15rem; color: #fff; margin: 0 0 .5rem; padding: 0; }
        .rl-cards { display: grid; grid-template-columns: repeat(var(--rl-n, 3), minmax(0, 1fr)); gap: 12px; margin: 0 0 1rem; }
        .rl-card { display: flex; flex-direction: column; background: #111729; border: 1px solid rgba(255,255,255,.12); border-radius: 10px;
              padding: 12px 14px; color: inherit; text-decoration: none !important; min-width: 0; position: relative; }
        .rl-card:hover, .rl-card:focus-visible { border-color: #ffb547; }
        .rl-card[aria-pressed="true"] { border-color: #ffb547; box-shadow: inset 0 0 0 1px #ffb547; background: #19213a; }
        .rl-card .rl-role { color: #9aa3b8; font-size: 12px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
        .rl-card[aria-pressed="true"] .rl-role { color: #ffb547; }
        .rl-card b { font-family: "Space Grotesk", system-ui, sans-serif; font-size: 1.15rem; color: #fff; }
        .rl-card span.rl-line { color: #c3c9d8; font-size: 14px; flex: 1; margin: .2rem 0 .4rem; }
        .rl-card small { color: #9aa3b8; font-size: 13px; }
        .rl-card .rl-tick { position: absolute; top: 10px; right: 12px; color: #ffb547; font-size: 13px; font-weight: 700; display: none; }
        .rl-card[aria-pressed="true"] .rl-tick { display: inline; }
        .rl label { font-weight: 600; color: #e6e9f2; margin-bottom: .3rem; }
        .rl .form-control { background: #0f1424; color: #e6e9f2; border: 1px solid #263052; border-radius: 8px; }
        .rl .form-control:focus { border-color: #4cc9f0; box-shadow: 0 0 0 .2rem rgba(76, 201, 240, .25); background: #0f1424; color: #e6e9f2; }
        .rl textarea.form-control { font: 13px/1.55 "JetBrains Mono", ui-monospace, monospace; color: #cfe3ff; min-height: 22rem; }
        .rl-hint { color: #9aa3b8; font-size: 14px; white-space: normal; }
        .rl-submit { display: flex; flex-wrap: wrap; align-items: center; gap: .75rem 1rem; }
        @media (max-width: 760px) { .rl-cards { grid-template-columns: 1fr; } .rl textarea.form-control { min-height: 16rem; } }
    </style>
@endpush
@section('content')
    @php($n = fn ($v) => number_format($v))
    <x-page-header :title="$title" />
    <section class="section section-xs section-first text-md-left">
        <div class="container rl" id="container">
            @include('_flash_message')

            <p class="rl-lead">Pick a starter or write your own Lua, name the company, and start it.
                @if($newRules)<a href="{{ url('signup') }}">Make a free account</a> or <a href="{{ url('login') }}">log in</a> first: every startup belongs to a player.@else Anyone can, no account needed.@endif
                Each release starts a new company with one drone in orbit around Earth, 100 fuel and
                {{ $n(config('forge.starting_credits')) }} credits. The drone loads your script, then runs your
                <code>launched</code> function on its own; its page shows everything it does and says.</p>

            @if($newRules)
                {{-- The new rules (#656), short (#719): the rest folded under Rules --}}
                <div class="rl-note" id="startup-note">
                    <p><b>Every release is a startup.</b> The bank lends it {{ $n(\App\Services\Startups::grant()) }}: its drone
                        ({{ $n(config('forge.startup_hull_value')) }}), {{ $n(config('forge.starting_credits')) }} credits to spend and a
                        {{ $n(config('forge.startup_fee')) }} fee. Once it holds {{ $n(\App\Services\Startups::grant() + config('forge.startup_mature_margin')) }}
                        credits you can mature it, and its {{ \App\Services\PlayerShares::COUNT }} shares are yours. If it fails, close it: it costs you nothing.</p>
                    <details class="rl-rules" id="startup-rules">
                        <summary>Rules</summary>
                        <ul>
                            <li>Its credits are its to spend (a kit costs 2,000). To mature it must hold its whole debt and
                                {{ $n(config('forge.startup_mature_margin')) }} more to work with.</li>
                            <li>It holds at most {{ \App\Services\Startups::capPermille() / 1000 }} times what it was lent at the start (push fees aside): what it earns above that goes to the bank, so mature it once it can.</li>
                            <li>Until it matures it trades only with the House (anything else fails with -16), buys at most a tenth of what
                                the House has on sale of a good at one exchange in a game day (-17), and plunders nothing.</li>
                            <li>It is yours: at most {{ config('forge.startup_max') }} at a time, and a closed one's slot reopens a game day later.</li>
                            <li>You reprogram it from its page or your <a href="{{ url('/account') }}">account page</a>: the first push is free, each after it adds
                                {{ $n(config('forge.startup_push_fee')) }} to its debt.</li>
                            <li>While the world is full a release is refused: try again in a few minutes.</li>
                            <li>If <code>launched</code> crashes, the drone restarts it, waiting longer after each crash; five crashes in a row make it a wreck.</li>
                        </ul>
                    </details>
                </div>
            @endif
            <p class="rl-lead"><b>Break, learn, repeat.</b> Release early, release often: there is no test world, every release
                is real, and it costs nothing. Watch it on its page, and when you see what goes wrong, send it a fix or start a
                better one.@unless($newRules) Release with a push password and you can also send a running company new
                code later, for a fee.@endunless A game day passes in {{ \App\Services\LaunchQueue::dayPace($clock) }} right now,
                {{ \App\Services\LaunchQueue::watchNote($clock) }}.</p>

            @if($clock && $clock['full'] && !$newRules)
                <div class="rl-note rl-warn">
                    The world is full right now: the engines are behind even with the game clock at its slowest. A
                    script you release now waits in the launch queue ({{ $clock['waiting'] }} waiting) and launches as
                    soon as there is room.
                </div>
            @endif

            @if(count($errors))
                <div class="rl-note rl-bad" role="alert">
                    <ul>
                        @foreach($errors->all() as $error)
                            <li>{{$error}}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ url('release') }}" class="mt-3">
                {{ csrf_field() }}
                @if(!empty($fixing))
                    {{-- A fixed copy of a company that ended (#773) --}}
                    <div class="alert alert-warning" id="fixing" style="white-space: normal">
                        <b>Fixing {{ $fixing['name'] }}.</b> {{ implode(' ', $fixing['lines']) }}
                        @if($fixing['line']) Line {{ $fixing['line']['number'] }} of its code: <code>{{ $fixing['line']['code'] }}</code> ({{ $fixing['error']['text'] }}). @elseif($fixing['error']) Its last error: {{ $fixing['error']['text'] }}. @endif
                        Its code is loaded below: change what went wrong, then start it.
                    </div>
                @endif
                @if($copiedFrom)
                    {{-- Where this code comes from (#324 step 16) --}}
                    <input type="hidden" name="copied_from" value="{{ $copiedFrom }}">
                    @if($copiedWord)<input type="hidden" name="copied_word" value="{{ $copiedWord }}">@endif
                    <p class="rl-hint">Copied from <a href="{{ url('spacecraft/' . $copiedFrom) }}">drone {{ $copiedFrom }}</a>{{ $copiedWord ? ' (the function ' . $copiedWord . ')' : '' }}: the new drone's page will say so.
                        Or start from a starter instead:</p>
                @endif

                {{-- Each card loads its starter into the editor; without scripts it reloads the page with it --}}
                <div role="group" aria-labelledby="starters-legend">
                    <p class="rl-legend" id="starters-legend">Start from a starter</p>
                    <div class="rl-cards" id="starters" style="--rl-n: {{ count($starters) }}">
                        @foreach($starters as $name => $what)
                            @php($card = \App\Services\Release::STARTER_CARDS[$name])
                            <a class="rl-card" href="{{ url('release?starter=' . $name) }}" data-starter="{{ $name }}"
                               role="button" aria-pressed="{{ $picked === $name ? 'true' : 'false' }}" title="{{ $what }}">
                                <span class="rl-tick">In the editor</span>
                                <span class="rl-role">{{ $card['role'] }}</span>
                                <b>{{ ucfirst($name) }}</b>
                                <span class="rl-line">{{ $card['line'] }}</span>
                                <small>{{ $card['kit'] }}@if($newRules) &middot; matures in {{ $card['mature'] }}@endif</small>
                            </a>
                        @endforeach
                    </div>
                </div>

                <div class="form-group">
                    <label for="company_name">Company name</label>
                    <input class="form-control" id="company_name" name="company_name" type="text" maxlength="45"
                           value="{{ old('company_name') }}" required>
                </div>
                @unless($newRules)
                <div class="form-group">
                    {{-- Code pushes (#553) --}}
                    <label for="password">Push password (optional)</label>
                    <input class="form-control" id="password" name="password" type="password" minlength="{{ config('forge.push_password_min') }}"
                           maxlength="200" autocomplete="new-password">
                    <small class="rl-hint">With a password you can push new code to this company's drones later, from its page or the API,
                        for a fee: {{ config('forge.push_fee_permille') / 10 }}% of its credits for the first push,
                        {{ 2 * config('forge.push_fee_permille') / 10 }}% for the second and so on. Without one, its code can
                        never change. The same password can serve all your companies; it is kept only as a hash.</small>
                </div>
                @endunless
                <div class="form-group">
                    <label for="script">Script (Lua)</label>
                    <p class="rl-hint mb-2">It starts with no equipment and buys what it needs: <code>equip(mining_kit)</code> buys a Mining Kit
                        (Engine, Worm and Refinery), <code>equip(factory_kit)</code> a Factory Kit (Engine, Smelter, Chemical Reactor and
                        Spider); each costs 2,000 at Earth. Each starter equips itself; its <b>Settings</b> at the top are the numbers to change first.</p>
                    @include('partials.lua-editor')
                    <textarea v-pre class="form-control" id="script" name="script" rows="20" spellcheck="false" data-lua-editor
                              maxlength="{{ config('forge.max_script_length') }}" required>{{ old('script', $starter) }}</textarea>
                </div>
                <div class="rl-submit">
                    {{-- Only a player founds a startup (#871) --}}
                    @if($newRules && !(\Illuminate\Support\Facades\Auth::user()?->login))
                        <p id="needs-account"><b>Make a free account, or log in, to found a startup.</b>
                            <a class="button button-primary button-lg" href="{{ url('signup') }}">Make an account</a>
                            <a class="button button-secondary button-lg" href="{{ url('login') }}">Log in</a></p>
                    @else
                    <button class="button button-primary button-lg" type="submit">{{ $newRules ? 'Found startup' : 'Release' }}</button>
                    @endif
                    <span class="rl-hint">New here? <a href="{{ url('getting-started') }}">Your first startup in 5 minutes</a>.</span>
                </div>
            </form>

            @if($clock && $clock['waiting'] > 0)
                {{-- The launch queue (#324 step 20) --}}
                <p class="mt-4 rl-hint" id="launch-queue">{{ $clock['waiting'] }} release(s) waiting to launch: they go
                    in order as soon as the world has room.</p>
            @endif
        </div>
    </section>
    <script>
        // The starters' code (#719), for the cards: read now, used once the page has loaded
        window.exStarters = @json($sources);
        document.addEventListener('DOMContentLoaded', function () {
            // Vue mounts #app first and replaces these elements: find them afresh
            var box = document.getElementById('script');
            var cards = document.querySelectorAll('#starters [data-starter]');
            if (!box || !cards.length) return;
            var loaded = box.value;
            cards.forEach(function (card) {
                card.addEventListener('click', function (e) {
                    var name = card.getAttribute('data-starter');
                    var code = window.exStarters[name];
                    if (typeof code !== 'string') return;
                    e.preventDefault();
                    if (box.value.trim() !== '' && box.value !== loaded && box.value !== code
                        && !window.confirm('Replace your script with the ' + name + ' starter?')) return;
                    box.value = code;
                    // The Lua editor (#852) shows what the box holds when told
                    box.dispatchEvent(new Event('input'));
                    loaded = code;
                    cards.forEach(function (c) { c.setAttribute('aria-pressed', c === card ? 'true' : 'false'); });
                    try { history.replaceState(null, '', '?starter=' + encodeURIComponent(name)); } catch (err) {}
                });
                // A role="button" link also answers the space bar
                card.addEventListener('keydown', function (e) { if (e.key === ' ') { e.preventDefault(); card.click(); } });
            });
        });
    </script>
@endsection
