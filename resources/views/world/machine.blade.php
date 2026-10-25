@extends('layouts.app')
@section('pageTitle', 'Machine')
@section('content')
    {{-- The Machine view (#324 step 19): the engine's VM, in the browser --}}
    <section class="section section-xs section-first bg-default text-md-left">
        <div class="container" id="machine" data-wasm="{{ asset('wasm/machine.wasm') }}">
            <h2>Machine</h2>
            <div style="white-space: normal; margin-bottom: 1em">
                Watch a word run on the same machine your drones run on: the engine's FORTH virtual machine, built
                for your browser, set up as a drone's is, with the game words loaded. Type definitions, a starting
                stack and a word, then step through it one instruction at a time and see the stacks change. Here
                <code>perform</code> and <code>query</code> do nothing (perform leaves -11, query -3): there is no
                game around the machine. Every instruction counts: a drone burns 1 fuel for each
                {{ number_format(100000) }} it runs.
            </div>
            <div class="row">
                <div class="col-12 col-lg-5">
                    <label for="m-defs"><b>Definitions</b></label>
                    <textarea id="m-defs" rows="10" style="width:100%; font-family:monospace">{{ $defs }}</textarea>
                    <label for="m-start"><b>Starting stack</b></label>
                    <input id="m-start" type="text" value="{{ $start }}" style="width:100%; font-family:monospace">
                    <label for="m-word"><b>Word to run</b></label>
                    <input id="m-word" type="text" value="{{ $word }}" style="width:100%; font-family:monospace">
                    <label for="m-limit"><b>Run at most</b> (instructions)</label>
                    <input id="m-limit" type="number" value="1000000" min="1" style="width:100%">
                    <div style="margin-top: 1em">
                        <button class="button button-sm button-primary" id="m-load" type="button">Load</button>
                        <button class="button button-sm button-default" id="m-step" type="button">Step</button>
                        <button class="button button-sm button-default" id="m-step10" type="button">Step 10</button>
                        <button class="button button-sm button-default" id="m-run" type="button">Run</button>
                    </div>
                    <div id="m-message" style="white-space: normal; margin-top: 1em"></div>
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
