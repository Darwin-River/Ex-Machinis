@extends('layouts.app')
@section('pageTitle', 'Machine')
@section('content')
    {{-- The Machine view (#324 step 19): the engine's VM, in the browser --}}
    <section class="section section-xs section-first bg-default text-md-left">
        <div class="container" id="machine" data-wasm="{{ asset('wasm/machine.wasm') }}"
             data-queries="{{ url('machine/queries') }}" data-protocols="{{ url('machine/protocols') }}" data-query="{{ url('machine/query') }}">
            <h2>Machine</h2>
            <div style="white-space: normal; margin-bottom: 1em">
                Watch a word run on the same machine your drones run on: the engine's FORTH virtual machine, built
                for your browser, set up as a drone's is, with the game words loaded. Type definitions, a starting
                stack and a word, then step through it one instruction at a time and see the stacks change.
                <code>perform</code> does nothing here (it leaves -11; with live data on, it first takes the protocol's arguments, as a drone would): nothing you run here acts in the game. With
                <b>live game data</b> on, <code>query</code> asks the game, now, as the drone you choose would: the
                same rows land in memory as in a drone's, so a word that sizes up the market here works the same in
                a company design. Off, query leaves -3. Every instruction counts: a drone burns 1 fuel for each
                {{ number_format(100000) }} it runs, and each query counts as 1,000.
            </div>
            <div class="row">
                <div class="col-12 col-lg-5">
                    <label for="m-defs"><b>Definitions</b></label>
                    <textarea id="m-defs" rows="10" style="width:100%; font-family:monospace">{{ $defs }}</textarea>
                    <label for="m-start"><b>Starting stack</b></label>
                    <input id="m-start" type="text" value="{{ $start }}" style="width:100%; font-family:monospace">
                    <label for="m-word"><b>Word to run</b></label>
                    <input id="m-word" type="text" value="{{ $word }}" style="width:100%; font-family:monospace">
                    <div style="margin-top: 0.5em">
                        <label><input id="m-live" type="checkbox" {{ request('live', '1') ? 'checked' : '' }}> <b>Live game data</b>, asking as drone</label>
                        <input id="m-drone" type="number" min="1" value="{{ $drone }}" style="width:6em">
                        <select id="m-vantage" style="max-width: 100%">
                            <option value="">or a drone at...</option>
                            @foreach($vantages as $v)
                                <option value="{{ $v->drone }}" {{ (int) $v->drone === $drone ? 'selected' : '' }}>{{ $v->place }} (drone {{ $v->drone }})</option>
                            @endforeach
                        </select>
                        <div id="m-station-warning" data-stations="{{ json_encode($stations) }}" style="white-space: normal; color: #c0392b; {{ in_array($drone, $stations, true) ? '' : 'display:none' }}">
                            Drone <span id="m-station-id">{{ $drone }}</span> is a House station drone. A drone doesn't see its own orders, so
                            asking as it shows its station's buy and sell prices as 0 or missing. Pick a player's drone at the same place.
                        </div>
                        <script>
                            (function () {
                                var w = document.getElementById('m-station-warning'), ids = JSON.parse(w.dataset.stations);
                                var check = function (v) { v = parseInt(v, 10); w.style.display = ids.indexOf(v) >= 0 ? '' : 'none';
                                    document.getElementById('m-station-id').textContent = v; };
                                document.getElementById('m-drone').addEventListener('input', function () { check(this.value); });
                                document.getElementById('m-vantage').addEventListener('change', function () { if (this.value) check(this.value); });
                            })();
                        </script>
                    </div>
                    <label for="m-limit"><b>Run at most</b> (instructions)</label>
                    <input id="m-limit" type="number" value="1000000" min="1" style="width:100%">
                    <div style="margin-top: 1em">
                        <button class="button button-sm button-primary" id="m-load" type="button">Load</button>
                        <button class="button button-sm button-default" id="m-step" type="button">Step</button>
                        <button class="button button-sm button-default" id="m-step10" type="button">Step 10</button>
                        <button class="button button-sm button-default" id="m-run" type="button">Run</button>
                    </div>
                    <div id="m-message" style="white-space: normal; margin-top: 1em"></div>
                    <div style="white-space: normal; margin-top: 1em">
                        Try it: <a href="{{ url('machine') . '?' . http_build_query(['defs' => ": cheapest ( resource -- price )\n  answer 120 610 query 0> if answer 2@ drop else -1 then ;", 'stack' => '2011', 'word' => 'cheapest', 'drone' => $drone]) }}">the cheapest Metals (A1) on sale where the drone is</a>,
                        <a href="{{ url('machine') . '?' . http_build_query(['defs' => "variable best  variable most\n: best-ore ( -- mineral )\n  0 best !  -1 most !\n  640 ask 0 max begin dup while 1- dup 12 * answer +\n    dup 2 + 2@ drop dup most @ > if most ! @ best ! else 2drop then\n  repeat drop best @ ;", 'stack' => '', 'word' => 'best-ore', 'drone' => $drone]) }}">the deposit with the most left here</a>.
                        The <a href="{{ url('queries') }}">Queries</a> page lists what each query returns.
                    </div>
                    <h4 style="margin-top: 1em">Live queries</h4>
                    <div id="m-queries" style="white-space: normal"></div>
                </div>
                <div class="col-12 col-lg-7">
                    <div class="table-custom-responsive">
                        <table class="table-ticket" style="font-family: monospace; width: 100%; table-layout: fixed">
                            <colgroup><col style="width: 9em"><col></colgroup>
                            <tr><th>Instructions</th><td id="m-count">0</td></tr>
                            <tr><th>Fuel</th><td id="m-fuel">0</td></tr>
                            <tr><th>Next (pc)</th><td id="m-pc"></td></tr>
                            <tr><th>Instruction</th><td id="m-next"></td></tr>
                            <tr><th>Data stack</th><td id="m-stack" style="white-space: normal"></td></tr>
                            <tr><th>Return stack</th><td id="m-rstack" style="white-space: normal"></td></tr>
                            <tr><th>Printed</th><td id="m-output" style="white-space: pre-wrap"></td></tr>
                        </table>
                    </div>
                    <h4 style="margin-top: 1em">Code of <span id="m-listing-name"></span></h4>
                    <div class="table-custom-responsive">
                        <table class="table-ticket" style="font-family: monospace">
                            <thead><tr><th>Address</th><th>Cell</th><th>Kind</th><th>Does</th></tr></thead>
                            <tbody id="m-listing"></tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div style="white-space: normal; margin-top: 1em">
                The stacks read left to right, bottom to top. A <b>call</b> pushes where to come back to on the return
                stack and goes to a word; an <b>alu</b> instruction does arithmetic or logic on the top of the stack
                and can return; a <b>literal</b> pushes a number; <b>branch</b> and <b>0branch</b> jump (0branch only
                when the top is 0). The word runs inside <code>catch</code>, as your launched word does, so it ends
                with 0 on top, or with the error it threw.
            </div>
        </div>
    </section>
    <script src="{{ asset('wasm/machine.js') }}" defer></script>
@endsection
