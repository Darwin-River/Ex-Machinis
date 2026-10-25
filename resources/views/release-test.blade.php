@extends('layouts.app')
@section('pageTitle', 'Test run')
@section('content')
    {{-- A test run's results (#324 step 7; by link since #367 step 3) --}}
    <section class="section section-xs section-first bg-default text-md-left">
        <div class="container" id="container">
            <h1>Test run</h1>
            @include('_flash_message')
            <p>Your script, running in the test world: the same rules and game data, with a faster clock. This page
                shows what it has done so far; reload it to see more. <a href="{{ url('release') }}">Back to the
                Release page</a>.</p>
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
        </div>
    </section>
@endsection
