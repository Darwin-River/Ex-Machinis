@extends('layouts.app')
@php $title = $user->name ; @endphp
@section('pageTitle', $title)
@section('content')
    <x-page-header>
        <x-slot:title>{{$title}}</x-slot:title>
        <li><a href="/companies">Companies</a></li>
        <li class="active">{{$title}}</li>
    </x-page-header>
    <section class="section section-xs section-first text-md-left">
        <div class="container" id="container">
            <div class="description-fields">
                <div class="row row-30">
                    <div class="col-12 col-lg-12 col mb-0">
                        <b>Company Id</b>: <span style="font-weight: normal">{{$user->user_id}}</span><br/>
                    </div>
                    <div class="col-12 col-lg-12 col mb-0">
                        <b>Founded on</b>: <span
                                style="font-weight: normal">{{ \Carbon\Carbon::parse($user->created_at)->format('l, F j, Y') }}</span><br/>

                    </div>
                    <div class="col-12 col-lg-12 col">

                        <b>Credits: </b><span style="font-weight: normal">{{$user->credits}}</span><br/>
                        @if($rank)
                            {{-- Ranked only among companies its age or younger (#548) --}}
                            <b>Leaderboard: </b><span style="font-weight: normal" id="peer-rank">{{ $rank['rank'] }} of {{ $rank['of'] }}
                                {{ $rank['of'] === 1 ? 'company' : 'companies' }} up to {{ number_format($rank['age'], 1) }} game days old
                                (its age)</span>
                            (<a href="{{ url('leaderboard') . '?max_age=' . $rank['age'] }}">compare</a>)<br/>
                        @endif

                    </div>
                </div>
            </div>
            <div class="row row-30">
                <div class="col">
                    <h2 class="">Spacecraft</h2>
                </div>
            </div>

            <div class="table-custom-responsive">
                <table class="table-ticket">
                    @if(sizeof($spacecraft) == 0)
                        <tr>
                            <td colspan="4"> No spacecraft present.</td>
                        </tr>
                    @endif
                    @foreach($spacecraft as $agent)
                        <tr>
                            <td>
                                <a href="{{url('/spacecraft/'.$agent->agent_id)}}">{{$agent->name}}</a>
                            </td>
                            <td>
                            <span class="meta-label">hull</span> {{$agent->hull?$agent->hull->name:'no hull'}}
                            </td>
                            <td>
                                <span class="meta-label">location</span> <a
                                        href="{{url('locations/'.$agent->location->object_id)}}">{{$agent->location->object_name}}</a>
                            </td>
                            <td><span class="meta-label">cargo</span>
                                @if(!$agent->hull || !$agent->hull->cargo_capacity)
                                    <span class="text-nowrap">no cargo capacity</span>
                                @else
                                    <span class="text-nowrap">{{( $agent->getCurrentCargoWeight())}}
                                        / {{$agent->hull->cargo_capacity}} Kg</span>
                                @endif

                            </td>
                        </tr>
                    @endforeach
                </table>
            </div>

            {{-- The stock market (#471) --}}
            <div class="row row-30">
                <div class="col">
                    <h2 class="">Shares</h2>
                    {{-- What its drones' thinking costs it (#544) --}}
                    @php($pt = $shares['compute'])
                    <p id="processor-tax">Processor tax: {{ number_format($pt['tax_day']) }} credits over the last game day for
                        {{ number_format($pt['instructions_day']) }} instructions ({{ $pt['per_million'] }} credits a million).
                        @if($pt['debt'] > 0)
                            <strong>It owes {{ number_format($pt['debt']) }}</strong>: a drone of its whose turn runs out thinks again only a game hour later, until it has paid.
                        @endif
                        @if($pt['broke_days'] !== null)
                            <strong>Broke</strong> for {{ $pt['broke_days'] }} game days{{ $pt['dissolves_in'] !== null ? ': it dissolves in ' . $pt['dissolves_in'] . ' game days unless it earns credits, and its drones become wrecks' : '' }}.
                        @endif
                    </p>
                    {{-- Whether it can keep its drones flying (#537) --}}
                    @php($h = $shares['health'])
                    <p id="company-health">Health: {{ $h['working'] }} working {{ \Illuminate\Support\Str::plural('drone', $h['working']) }}{{ $h['wrecked'] ? ', ' . $h['wrecked'] . ' wrecked' : '' }};
                        {{ number_format($h['fuel']) }} fuel aboard (the least, {{ $h['lowest'] }}), {{ number_format($h['used']) }} used over the last game day;
                        {{ $h['days'] >= 0 ? 'a drone runs dry in about ' . $h['days'] . ' game ' . \Illuminate\Support\Str::plural('day', $h['days']) . ' at that rate' : "no drone's fuel is falling" }};
                        {{ number_format($h['credits']) }} credits.
                        @if($h['at_risk'])
                            <strong>At risk:</strong> {{ $h['at_risk_reason'] }}. A warning, not a verdict.
                        @endif
                        @if($h['stalled'])
                            <strong>Stalled:</strong> none of its drones has bought, sold or made anything for {{ $h['idle'] }} game hours.
                        @endif
                    </p>
                    @if($h['stalled'] || collect($h['drones'])->contains(fn ($d) => $d['idle'] >= 24))
                        <ul id="idle-drones">
                            @foreach($h['drones'] as $d)
                                @if($d['idle'] >= 24)
                                    @php($says = $d['status'] ? ', says "' . $d['status'] . '"' : '')
                                    @php($holds = count($d['holds']) ? ', holds ' . collect($d['holds'])->map(fn ($x) => $x['units'] . ' ' . $x['resource'])->implode(', ') : '')
                                    <li><a href="{{ url('/spacecraft/' . $d['drone']) }}">{{ $d['name'] }}</a>: idle {{ $d['idle'] }}
                                        game hours{{ $says }}{{ $holds }}</li>
                                @endif
                            @endforeach
                        </ul>
                    @endif
                    <p>{{ number_format($shares['count']) }} shares, each paying its part of the company's sales income after tax.
                        Last trade price: <b>{{ $shares['price'] !== null ? number_format($shares['price']) . ' credits' : 'never traded' }}</b>.</p>
                </div>
            </div>
            <div class="table-custom-responsive">
                <table class="table-ticket">
                    <tr><th>Holder</th><th>Shares</th><th>Dividends paid</th></tr>
                    @forelse($shares['holders'] as $h)
                        <tr><td><a href="{{ url('/companies/'.$h['company']) }}">{{ $h['name'] }}</a></td>
                            <td>{{ number_format($h['units']) }}</td><td>{{ number_format($h['dividends']) }}</td></tr>
                    @empty
                        <tr><td colspan="3">No holders.</td></tr>
                    @endforelse
                </table>
            </div>
            @if($shares['offering'])
                @php($o = $shares['offering'])
                <h4>Offering new shares</h4>
                <p>Up to {{ number_format($o['units']) }} new shares at {{ number_format($o['price']) }} credits each,
                    raising up to {{ number_format($o['raise']) }}, and at least {{ number_format($o['min']) }} of them:
                    {{ number_format($o['committed']) }} committed so far, {{ $o['hours'] }} game hours left. Once every
                    share is committed they are issued and the company paid. At the deadline, if at least
                    {{ number_format($o['min']) }} are committed, those are issued; if not, everyone is refunded.
                    If it fills, the new shares take {{ $o['income_percent'] }}% of the company's future sales income:
                    about {{ number_format($o['dividends_a_day'], 1) }} credits a day at today's sales (#572).</p>
                @if(count($o['investors']))
                    <div class="table-custom-responsive">
                        <table class="table-ticket">
                            <tr><th>Investor</th><th>Shares committed</th></tr>
                            @foreach($o['investors'] as $m)
                                <tr><td><a href="{{ url('/companies/'.$m['company']) }}">{{ $m['name'] }}</a></td>
                                    <td>{{ number_format($m['units']) }}</td></tr>
                            @endforeach
                        </table>
                    </div>
                @endif
            @endif
            @if(count($shares['orders']))
                <h4>Orders at the Earth exchange</h4>
                <div class="table-custom-responsive">
                    <table class="table-ticket">
                        <tr><th>Company</th><th>Order</th><th>Price</th><th>Limit</th></tr>
                        @foreach($shares['orders'] as $o)
                            <tr><td><a href="{{ url('/companies/'.$o['company']) }}">{{ $o['name'] }}</a></td>
                                <td>{{ $o['side'] }}</td><td>{{ number_format($o['price']) }}</td>
                                <td>{{ $o['side'] === 'sell' ? 'keeps ' . $o['keep'] : 'wants ' . $o['want'] }}</td></tr>
                        @endforeach
                    </table>
                </div>
            @endif
            @if(count($shares['holdings']))
                <h4>Shares it holds in other companies</h4>
                <div class="table-custom-responsive">
                    <table class="table-ticket">
                        <tr><th>Company</th><th>Shares</th><th>Last price</th><th>Value</th><th>Dividends received</th></tr>
                        @foreach($shares['holdings'] as $h)
                            <tr><td><a href="{{ url('/companies/'.$h['company']) }}">{{ $h['name'] }}</a></td>
                                <td>{{ number_format($h['units']) }}</td>
                                <td>{{ $h['ended'] ? 'ended: worth nothing' : ($h['price'] !== null ? number_format($h['price']) : '-') }}</td>
                                <td>{{ number_format($h['value']) }}</td><td>{{ number_format($h['dividends']) }}</td></tr>
                        @endforeach
                    </table>
                </div>
            @endif
            @if(count($shares['trades']))
                <h4>Recent trades in its shares</h4>
                <div class="table-custom-responsive">
                    <table class="table-ticket">
                        <tr><th>Seller</th><th>Buyer</th><th>Shares</th><th>Price</th></tr>
                        @foreach($shares['trades'] as $t)
                            <tr><td><a href="{{ url('/companies/'.$t['seller']) }}">{{ $t['seller_name'] }}</a></td>
                                <td><a href="{{ url('/companies/'.$t['buyer']) }}">{{ $t['buyer_name'] }}</a></td>
                                <td>{{ number_format($t['units']) }}</td><td>{{ number_format($t['price']) }}</td></tr>
                        @endforeach
                    </table>
                </div>
            @endif

            {{-- Code pushes (#553): new code for its drones, with the password it was released with --}}
            <h3 class="mt-4" id="pushes">Code pushes</h3>
            @include('_flash_message')
            @if(!$pushes['enabled'])
                <p style="white-space: normal">This company was released without a push password, so its code can't change.</p>
            @else
                <p style="white-space: normal">
                    Its owner can push new code to its working drones with the password it was released with. The code
                    travels from Earth to each drone at the speed of light; the drone then drops what it was running and
                    starts the new code's <code>launched()</code> with fresh variables, keeping its hold and its place.
                    Pushed {{ $pushes['count'] }} {{ $pushes['count'] === 1 ? 'time' : 'times' }}. The next push costs
                    {{ $pushes['next_fee_percent'] }}% of its credits: {{ number_format($pushes['next_fee']) }} now.
                </p>
                @if($errors->has('push'))
                    <div class="alert alert-danger">{{ $errors->first('push') }}</div>
                @endif
                <form method="POST" action="{{ url('/companies/' . $user->user_id . '/push') }}">
                    {{ csrf_field() }}
                    <div class="form-group">
                        <label for="push-script">New script (Lua)</label>
                        <textarea class="form-control" id="push-script" name="script" rows="10" spellcheck="false"
                                  style="font-family: monospace" maxlength="{{ config('forge.max_script_length') }}"
                                  required>{{ old('script') }}</textarea>
                    </div>
                    <div class="form-group">
                        <label for="push-password">Password</label>
                        <input class="form-control" id="push-password" name="password" type="password" maxlength="200"
                               autocomplete="current-password" required>
                    </div>
                    <button class="button button-primary" type="submit">Push for {{ number_format($pushes['next_fee']) }} credits</button>
                </form>
                @if(count($pushes['log']))
                    <div class="table-custom-responsive mt-3">
                        <table class="table-ticket" id="push-log">
                            <tr><th>#</th><th>When</th><th>Fee</th><th>Drones</th></tr>
                            @foreach($pushes['log'] as $p)
                                <tr><td>{{ $p['number'] }}</td><td>{{ $p['when'] }}</td>
                                    <td>{{ number_format($p['fee']) }} ({{ $p['percent'] }}%)</td><td>{{ $p['drones'] }}</td></tr>
                            @endforeach
                        </table>
                    </div>
                @endif
            @endif
        </div>


    </section>
@endsection
