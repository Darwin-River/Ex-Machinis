@extends('layouts.app')
@section('pageTitle', 'Home')
@section('content')
    <!-- Section welcome-->
    <section class="section section-md bg-default context-dark section-height-1 box-transform-wrap bg-image"
             style="background-image: url('/images/bg-index.jpg');">
        <div class="container">
            <div class="row">
                <div class="col offset-md-1">
                    <div class="post-erin">
                        <h5 class="post-erin-subtitle">welcome to</h5>
                        <h1 class="post-erin-title">Ex Machinis — a multiplayer space game<br>
                          <span class="font-weight-light">where you <b>program</b> fleets of <b>self-replicating spacecraft</b></span><br>
                          <span class="font-weight-light">to exploit the riches of our solar system</span>
                        </h1><a class="button button-2 button-lg button-white-2 button-icon button-icon-left"
                                href="http://exmachinis.wiki"><span
                                    class="icon mdi mdi-arrow-right"></span><span>Learn more on our wiki</span></a>
                    </div>
                </div>
            </div>
        </div>
        <div class="box-transform" style="background-image: url('/images/bg-index.jpg');"></div>
        <div class="comets comets-left comets-long"></div>
        <div class="comets comets-center comets-long"></div>
        <div class="comets comets-top comets-long"></div>
    </section>


    <div class="container">
        <div class="row">
            <div class="col-md-8 col-md-offset-2">
                <div class="panel panel-default">
                    <!--<div class="panel-heading">test</div>-->

                    <div class="panel-body">
                        @if (session('status'))
                            <div class="alert alert-success">
                                {{ session('status') }}
                            </div>
                        @endif

                        {{-- What's happening (#324 step 17) --}}
                        <h3 class="mt-4">What's happening</h3>
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
        </div>
    </div>
@endsection
