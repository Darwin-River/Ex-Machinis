@extends('layouts.app')
@php $title = 'Release a Script' ; @endphp
@section('pageTitle', $title)
@section('content')
    <section class="section section-xs section-first bg-default text-md-left">
        <div class="container" id="container">
            <h1>{{$title}}</h1>
            @include('_flash_message')

            <p>Write your drone's program and release it. Each release starts a new company with one drone in orbit
                around Earth, a starter kit and {{ number_format(config('forge.starting_credits')) }} credits.
                The drone loads your script, then runs your <code>launched</code> word on its own.</p>
            <p>Define words only, and end with <code>: launched ... ;</code>. If <code>launched</code> crashes, the drone
                restarts it from the copy you released, waiting longer after each crash; five crashes in a row make
                it a wreck. A drone can't be changed once released: test your words first.</p>

            @if(count($errors))
                <div class="alert alert-danger mt-3">
                    <ul class="mb-0">
                        @foreach($errors->all() as $error)
                            <li>{{$error}}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ url('release') }}" class="mt-3">
                {{ csrf_field() }}
                <div class="form-group">
                    <label for="company_name">Company name</label>
                    <input class="form-control" id="company_name" name="company_name" type="text" maxlength="45"
                           value="{{ old('company_name') }}" required>
                </div>
                <div class="form-group">
                    <label for="script">Script</label>
                    <textarea class="form-control" id="script" name="script" rows="16" spellcheck="false"
                              style="font-family: monospace"
                              maxlength="{{ config('forge.max_script_length') }}" required>{{ old('script', ": launched  ( -- )\n  .\" Hello from orbit\" cr ;") }}</textarea>
                </div>
                <button class="button button-primary" type="submit">Release</button>
                @if($testWorld)
                    <button class="button button-default" type="submit" formaction="{{ url('release/test') }}" formnovalidate>Test</button>
                @endif
            </form>
            @if($testWorld)
                <p class="mt-2"><small><b>Test</b> runs your script in the test world: the same rules, game data and
                    starting company, with a faster clock. Nothing there touches the live world, and you can test as
                    often as you like (your latest {{ config('forge.test_runs_kept') }} test runs are kept).</small></p>
            @endif

            @if($testWorld && $testRuns->isNotEmpty())
                <h3 class="mt-5" id="test-runs">Your test runs</h3>
                @foreach($testRuns as $run)
                    <div class="mt-4">
                        <h5>{{ $run->drone->name }} (test)
                            &middot; {{ number_format($run->credits) }} credits
                            @if($run->drone->wrecked_at)
                                &middot; <span class="text-danger">wreck</span>
                            @endif
                            @if($run->crashes)
                                &middot; <span class="text-warning">{{ $run->crashes }} crash(es)</span>
                            @endif
                        </h5>
                        <h6>Output</h6>
                        @forelse($run->messages as $message)
                            <pre class="p-2 bg-light" style="white-space: pre-wrap">{{ $message->body }}</pre>
                        @empty
                            <p>Nothing yet: the drone is loading your script.</p>
                        @endforelse
                        @if($run->events->isNotEmpty())
                            <h6>Latest events</h6>
                            <table class="table table-sm">
                                <tr><th>Event</th><th>Resource</th><th>Outcome</th><th>Quantity</th><th>Credits</th></tr>
                                @foreach($run->events as $event)
                                    <tr>
                                        <td>{{ $event->type_name ?? $event->event_type }}</td>
                                        <td>{{ $event->resource_name }}</td>
                                        <td>{{ $event->outcome }}</td>
                                        <td>{{ $event->new_quantity }}</td>
                                        <td>{{ $event->new_credits }}</td>
                                    </tr>
                                @endforeach
                            </table>
                        @endif
                        @if($run->trades->isNotEmpty())
                            <h6>Trades</h6>
                            <table class="table table-sm">
                                <tr><th>Resource</th><th>Units</th><th>Credits</th><th>Side</th></tr>
                                @foreach($run->trades as $trade)
                                    <tr>
                                        <td>{{ $trade->resource }}</td>
                                        <td>{{ $trade->units }}</td>
                                        <td>{{ $trade->credits }}</td>
                                        <td>{{ $trade->seller == $run->drone->agent_id ? 'sold' : 'bought' }}</td>
                                    </tr>
                                @endforeach
                            </table>
                        @endif
                    </div>
                @endforeach
            @endif

            <h3 class="mt-5">Your drones</h3>
            @if($drones->isEmpty())
                <p>You haven't released a script yet.</p>
            @else
                @foreach($drones as $drone)
                    @php $company = $companies->firstWhere('company_id', $drone->company_id); @endphp
                    <div class="mt-4">
                        <h5><a href="{{ url('spacecraft/' . $drone->agent_id) }}">{{ $drone->name }}</a>
                            &middot; {{ $company ? $company->name : '' }}
                            @if($drone->wrecked_at)
                                &middot; <span class="text-danger">wreck</span>
                            @elseif($drone->crashes_in_row > 0)
                                &middot; <span class="text-warning">{{ $drone->crashes_in_row }} crash(es) in a row</span>
                            @endif
                        </h5>
                        @if(!config('forge.email'))
                            @forelse(($messages[$drone->agent_id] ?? collect()) as $message)
                                <pre class="p-2 bg-light" style="white-space: pre-wrap">{{ $message->body }}</pre>
                            @empty
                                <p>No replies yet.</p>
                            @endforelse
                        @endif
                    </div>
                @endforeach
            @endif
        </div>
    </section>
@endsection
