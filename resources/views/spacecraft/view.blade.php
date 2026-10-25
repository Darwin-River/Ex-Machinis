@extends('layouts.app')
@php $title = $agent->name ; @endphp
@section('pageTitle', $title)
@section('content')
    <section class="breadcrumbs-modern box-transform-wrap bg-image context-dark"
             style="background-image: url('/images/240907570-huge.jpg');">
        <div class="container">
            <div class="breadcrumbs-modern-body">
                <h5 class="breadcrumbs-modern-subtitle"></h5>
                <h1 class="breadcrumbs-modern-title">{{$title}}</h1>
            </div>
            <ul class="breadcrumbs-path breadcrumbs-modern-path">
                <li><a href="/">Home</a></li>
                <li><a href="/spacecraft">Spacecraft</a></li>
                <li class="active">{{$title}}</li>
            </ul>
        </div>
        <div class="box-transform" style="background-image: url('/images/240907570-huge.jpg');"></div>
    </section>

    <section class="section section-xs section-first bg-default text-md-left">
        <div class="container grid-demonstration" id="container">
            <div class="description-fields">
                <div class="row row-xxl row-30 row-md-60 ">
                    <div class="col-12 col-sm-6 col mb-0">
                        <b>Spacecraft Id</b>: {{$agent->agent_id}}<br/>
                        <b>Location</b>: <a
                                href="{{url('locations/'.$agent->object_id)}}">{{$agent->location->object_name}}</a><br/>

                        <b>Company</b>: {{ $state['company']->name ?? '' }}
                            {{ ($state['company'] && $state['company']->eliminated_at) ? '(ended)' : '' }}<br/>
                        <b>Status</b>: {{ $state['status'] }}<br/>
                        <b>Fuel</b>: {{ $state['fuel'] }}<br/>
                    </div>
                    <div class="col-12 col-sm-6 col ">
                        <b>Hull</b>: {{$agent->hull->name}}<br/>
                        <b>Max Cargo</b>: {{$agent->hull->cargo_capacity}} kg.<br/>

                        <b>Current Cargo</b>: {{$totalWeight}} kg.<br/>
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
                <div class="row row-xxl row-30 row-md-60">
                    <div class="col">
                        <h2 class="wow fadeInLeft">Post-mortem</h2>
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
            @endif

            {{-- Its code and where it comes from (#324 step 16) --}}
            <div class="row row-xxl row-30 row-md-60">
                <div class="col">
                    <h2 class="wow fadeInLeft">Code</h2>
                    @if($code)
                        <div style="white-space: normal; margin-bottom: 1em">
                            @if($code['inherited'])
                                Inherited from <a href="{{ url('spacecraft/' . $code['release']->agent_id) }}">drone {{ $code['release']->agent_id }}</a>'s release.
                            @else
                                Released {{ $code['release']->created_at }}.
                            @endif
                            @if($code['release']->copied_from)
                                Copied from <a href="{{ url('spacecraft/' . $code['release']->copied_from) }}">drone {{ $code['release']->copied_from }}</a>{{ $code['release']->copied_word ? ' (the word ' . $code['release']->copied_word . ')' : '' }}.
                            @endif
                            @if($code['same_as'])
                                The same code as <a href="{{ url('spacecraft/' . $code['same_as']) }}">drone {{ $code['same_as'] }}</a>'s, released earlier.
                            @endif
                            <a class="button button-primary button-sm" href="{{ url('release?copy=' . $agent->agent_id) }}">Copy this code</a>
                        </div>
                        <pre id="drone-code" style="white-space: pre-wrap; color: #e6e6e6; background: #2b2b36; padding: 1em; border-radius: 4px">{{ $code['release']->source }}</pre>
                        @if($code['words'])
                            <div style="white-space: normal; line-height: 2"><b>Words</b>:
                                @foreach($code['words'] as $name => $origin)
                                    <span class="mr-2"><code>{{ $name }}</code>
                                        @if($origin)
                                            <span>(first in <a href="{{ url('spacecraft/' . $origin) }}">drone {{ $origin }}</a>)</span>
                                        @endif
                                        <a href="{{ url('release?copy=' . $agent->agent_id . '&word=' . urlencode($name)) }}" title="Copy this word">copy</a></span>
                                @endforeach
                            </div>
                        @endif
                    @else
                        <p>This drone's program came by email: it has no released code to show.</p>
                    @endif
                </div>
            </div>

            <div class="row row-xxl row-30 row-md-60">
                <div class="col">
                    <h2 class="wow fadeInLeft">Cargo Manifest</h2>
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
                                    <span class="icon mdi mdi-lock" title="resource in use"></span>
                                @endif
                            </td>
                            <td><span class="rd-nav-icon mdi mdi-package-down" title="cargo"></span>
                                {{$cargoPlacement->new_quantity}} units
                            </td>
                            <td>
                            <td><span class="rd-nav-icon mdi mdi-weight" title="weight"></span>
                                {{$cargoPlacement->new_quantity*$cargoPlacement->mass}} kg
                            </td>
                        <!--     <td>
                                Unit Mass: {{$cargoPlacement->mass}} kg

                            </td>-->
                        </tr>
                    @endforeach
                </table>
            </div>
            <div class="row row-xxl row-30 row-md-60">
                <div class="col">
                    <h2 class="wow fadeInLeft">Observed Events</h2>
                </div>
            </div>
            <in-game-events-table
                    :results-per-page="{{Config::get('constants.options.results_per_page')}}" :limit-results="10"
                    :agent-id="{{$agent->agent_id}}"></in-game-events-table>
        </div>
    </section>

@endsection
