@extends('layouts.app')
@php $title = $agent->name ; @endphp
@section('pageTitle', $title)
@section('content')
    <x-page-header>
        <x-slot:title>{{$title}}</x-slot:title>
        <li><a href="/spacecraft">Spacecraft</a></li>
        <li class="active">{{$title}}</li>
    </x-page-header>

    <section class="section section-xs section-first text-md-left">
        <div class="container" id="container">
            @include('_flash_message')
            <div class="description-fields">
                <div class="row row-30">
                    <div class="col-12 col-sm-6 col mb-0">
                        <b>Spacecraft Id</b>: {{$agent->agent_id}}<br/>
                        @if($flight)
                            <b>Location</b>: flying from <a href="{{url('locations/'.$agent->object_id)}}">{{$agent->location->object_name}}</a>
                            to <a href="{{url('locations/'.$flight['to_id'])}}">{{ $flight['to'] }}</a>, arriving {{ $flight['arrives'] }}
                            ({{ \App\Services\LaunchQueue::realWait((int) $flight['arrives_game_time'] - (int) \App\Services\World::gameNow()) }} from now)<br/>
                        @else
                            <b>Location</b>: <a
                                href="{{url('locations/'.$agent->object_id)}}">{{$agent->location->object_name}}</a><br/>
                        @endif

                        <b>Company</b>: @if($state['company'])<a href="{{ url('companies/' . $state['company']->company_id) }}">{{ $state['company']->name }}</a>@endif
                            {{ ($state['company'] && $state['company']->eliminated_at) ? '(ended)' : '' }}<br/>
                        <b>Status</b>: {{ $state['status'] }}<br/>
                        @if($activity && !empty($activity['now']))
                            <b>Now</b>: {{ $activity['now'] }}<br/>
                        @endif
                        @if($agent->script_status !== null)
                            <b>Its script says</b> ({{ \App\Services\DronePage::when((int) $agent->script_status_t) }}): <span v-pre>{{ $agent->script_status }}</span><br/>
                        @endif
                        <b>Fuel</b>: {{ $state['fuel'] }}
                            <span>· used in the last game day: {{ $state['fuel_day']['actions'] }} for actions{{ $state['fuel_day']['thinking'] ? ', ' . $state['fuel_day']['thinking'] . ' for thinking (before #544)' : '' }}
                                (thinking burns no fuel: the company pays processor tax)</span>
                            @if($state['fuel_day']['by_action'])
                                <span>({{ collect($state['fuel_day']['by_action'])->map(fn ($u) => $u['what'] . ' ' . $u['fuel'])->implode(', ') }})</span>
                            @endif<br/>
                        @if($messages->isNotEmpty())
                            <b>Last said</b> ({{ \App\Services\DronePage::when($messages->first()->game_t) }}):
                            <a href="#messages" v-pre>{{ \Illuminate\Support\Str::limit(trim(preg_replace('/^Location:[^\n]*\n/', '', $messages->first()->body)), 120) }}</a><br/>
                        @endif
                    </div>
                    <div class="col-12 col-sm-6 col">
                        <b>Hull</b>: {{$agent->hull->name}}<br/>
                        <b>Cargo</b>: {{ number_format($totalWeight) }} of {{ number_format($agent->hull->cargo_capacity) }} kg (<a href="#cargo">manifest</a>)<br/>

                        <b>Generation</b>: {{ $state['generation'] }}
                            @if($state['parent'])
                                <span>(launched by <a href="{{ url('spacecraft/' . $state['parent']->agent_id) }}">{{ $state['parent']->name }}</a>)</span>
                            @endif<br/>
                        @if($state['clones']->isNotEmpty())
                            <b>Clones</b>:
                            @foreach($state['clones'] as $clone)
                                <a href="{{ url('spacecraft/' . $clone->agent_id) }}">{{ $clone->name }}</a>{{ $clone->wrecked_at ? ' (wreck)' : '' }}{{ $loop->last ? '' : ',' }}
                            @endforeach<br/>
                        @endif
                        <b>Instructions run</b>: {{ number_format($state['instructions']) }}
                            @if($state['crashes'])
                                <span>· <b>Crashes</b>: {{ $state['crashes'] }}</span>
                            @endif<br/>
                    </div>
                </div>
            </div>
            @if($postMortem)
                {{-- Post-mortem (#324 step 16) --}}
                <div class="row row-30">
                    <div class="col">
                        <h2 class="">Post-mortem</h2>
                        <div id="post-mortem-cause" style="white-space: normal; margin-bottom: 1em">{{ $postMortem['cause'] }}</div>
                    </div>
                </div>
                <div class="table-custom-responsive">
                    <table class="table-ticket">
                        <tr><th>When</th><th>What</th><th>Result</th><th>Fuel after</th></tr>
                        @foreach($postMortem['timeline'] as $row)
                            <tr><td>{{ $row['when'] }}</td><td>{{ $row['what'] }}</td><td>{{ $row['result'] }}</td><td>{{ $row['fuel'] }}</td></tr>
                        @endforeach
                    </table>
                </div>
                @php($wreckDeals = \App\Services\DronePage::deals((int) $agent->agent_id))
                @if($wreckDeals)
                    <h4 class="mt-3">Its last sales and purchases</h4>
                    <div class="table-custom-responsive">
                        <table class="table-ticket">
                            <tr><th>When</th><th>What</th><th>With</th><th>Credits</th></tr>
                            @foreach($wreckDeals as $row)
                                <tr><td>{{ $row['when'] }}</td><td>{{ $row['what'] }}</td><td>{{ $row['with'] }}</td><td>{{ $row['credits'] }}</td></tr>
                            @endforeach
                        </table>
                    </div>
                @endif
            @endif

            <div class="row row-30">
                <div class="col">
                    <h2 class="" id="cargo">Cargo</h2>
                </div>
            </div>
            <div class="table-custom-responsive">
                <table class="table-ticket">
                    @if(sizeof($cargoManifest) == 0)
                        <tr>
                            <td colspan="4"> No cargo loaded</td>
                        </tr>
                    @endif
                    @foreach($cargoManifest as $cargoPlacement)
                        <tr>
                            <td>
                                <a href="{{url('/resources/'.$cargoPlacement->resource_id)}}">{{$cargoPlacement->name}}</a>
                            </td>
                            <td>
                                @if($cargoPlacement->locked)
                                    <span class="locked" title="resource in use">in use</span>
                                @endif
                            </td>
                            <td><span class="meta-label">cargo</span>
                                {{ number_format($cargoPlacement->new_quantity) }} units
                            </td>
                            <td><span class="meta-label">weight</span>
                                {{ number_format($cargoPlacement->new_quantity * $cargoPlacement->mass) }} kg
                            </td>
                        <!--     <td>
                                Unit Mass: {{$cargoPlacement->mass}} kg

                            </td>-->
                        </tr>
                    @endforeach
                </table>
            </div>
            @if($activity)
                {{-- What a working drone is doing (#380) --}}
                <div class="row row-30">
                    <div class="col" id="activity">
                        <h2 class="">Activity</h2>
                        <p><b>Now</b>: {{ $activity['now'] ?? $state['status'] }}</p>
                    </div>
                </div>
                @if($activity['timeline'])
                    <div class="table-custom-responsive">
                        <table class="table-ticket">
                            <tr><th>When</th><th>What</th><th>Result</th><th>Fuel after</th></tr>
                            @foreach($activity['timeline'] as $row)
                                <tr><td>{{ $row['when'] }}</td><td>{{ $row['what'] }}</td><td>{{ $row['result'] }}</td><td>{{ $row['fuel'] }}</td></tr>
                            @endforeach
                        </table>
                    </div>
                @endif
                @if($activity['deals'])
                    <h4 class="mt-3">Sales and purchases</h4>
                    <div class="table-custom-responsive">
                        <table class="table-ticket">
                            <tr><th>When</th><th>What</th><th>With</th><th>Credits</th></tr>
                            @foreach($activity['deals'] as $row)
                                <tr><td>{{ $row['when'] }}</td><td>{{ $row['what'] }}</td><td>{{ $row['with'] }}</td><td>{{ $row['credits'] }}</td></tr>
                            @endforeach
                        </table>
                    </div>
                @endif
            @endif

            {{-- What it has said (#367 step 3: public, like its code); repeats collapsed (#591) --}}
            <div class="row row-30">
                <div class="col" id="messages">
                    <h2 class="">What it said</h2>
                    @php($said = [])
                    @foreach($messages as $message)
                        @php($last = count($said) - 1)
                        @if($last >= 0 && $said[$last]['body'] === $message->body && $said[$last]['subject'] === $message->subject)
                            @php($said[$last]['count']++)
                            @php($said[$last]['first'] = $message->game_t)
                        @else
                            @php($said[] = ['body' => $message->body, 'subject' => $message->subject, 'last' => $message->game_t, 'first' => $message->game_t, 'count' => 1])
                        @endif
                    @endforeach
                    @forelse($said as $m)
                        <div><small>{{ \App\Services\DronePage::when($m['last']) }}{{ $m['subject'] ? ' · ' . $m['subject'] : '' }}{{ $m['count'] > 1 ? ' · said ' . $m['count'] . ' times since ' . \App\Services\DronePage::when($m['first']) : '' }}</small></div>
                        <pre class="p-2" v-pre style="white-space: pre-wrap">{{ $m['body'] }}</pre>
                    @empty
                        <p>Nothing yet. What it prints shows here once it reaches Earth.</p>
                    @endforelse
                </div>
            </div>

            {{-- Its code is its company's (#594): shown, with its revisions, on the company page --}}
            <div class="row row-30">
                <div class="col" id="code">
                    <h2 class="">Code</h2>
                    @if($code && $state['company'])
                        @php($lastPush = \App\Services\CompanyCode::latestPush((int) $state['company']->company_id))
                        <p style="white-space: normal">
                            Every drone of {{ $state['company']->name }} runs the same code:
                            <a href="{{ url('companies/' . $state['company']->company_id . '#code') }}">see it, where it came from and its revisions on the company page</a>.
                            @if($lastPush)
                                Its newest revision is push {{ $lastPush['number'] }}, sent {{ $lastPush['when'] }}; a push reaches each drone at the speed of light.
                            @else
                                It still runs the code it was released with.
                            @endif
                        </p>
                    @elseif($code)
                        <p>It runs the code released with <a href="{{ url('spacecraft/' . $code['release']->agent_id) }}">drone {{ $code['release']->agent_id }}</a>.</p>
                    @else
                        <p>This drone's program came by email: it has no released code to show.</p>
                    @endif
                </div>
            </div>

            <div class="row row-30">
                <div class="col">
                    <h2 class="">Observed Events</h2>
                </div>
            </div>
            <in-game-events-table
                    :results-per-page="{{Config::get('constants.options.results_per_page')}}" :limit-results="10"
                    :agent-id="{{$agent->agent_id}}"></in-game-events-table>
        </div>
    </section>

@endsection
