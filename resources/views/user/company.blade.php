@extends('layouts.app')
@php $title = $user->name ; @endphp
@section('pageTitle', $title)
@push('styles')
    <style>
        /* The company at a glance (#591) */
        .stat-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin: 0 0 8px; }
        .stat { border: 1px solid rgba(255,255,255,0.15); border-radius: 6px; padding: 12px 14px; text-align: left; }
        .stat-label { font-size: 0.8em; text-transform: uppercase; letter-spacing: 0.05em; opacity: 0.75; }
        .stat-value { font-size: 1.6em; font-weight: 600; font-variant-numeric: tabular-nums; line-height: 1.3; }
        .stat-value small { font-size: 0.55em; font-weight: 400; opacity: 0.8; }
        .stat-negative { color: #ff7b72; }
        .stat-link { font-size: 0.85em; }
        .stat-meta { font-size: 0.85em; opacity: 0.75; margin-bottom: 1.5em; }
        .health-banner { border-left: 4px solid #e3b341; background: rgba(227,179,65,0.12); padding: 10px 14px; margin: 0 0 12px; }
        /* Code revisions (#594) */
        #code-revisions .num { text-align: right !important; font-variant-numeric: tabular-nums; white-space: nowrap; }
        #code-revisions td:first-child { color: #fff !important; white-space: nowrap; }
        /* Code lineage (#616) */
        #lineage .num { text-align: right !important; font-variant-numeric: tabular-nums; white-space: nowrap; }
        #lineage td:first-child { white-space: nowrap; }
    </style>
@endpush
@section('content')
    <x-page-header>
        <x-slot:title>{{$title}}</x-slot:title>
        <li><a href="/companies">Companies</a></li>
        <li class="active">{{$title}}</li>
    </x-page-header>
    <section class="section section-xs section-first text-md-left">
        <div class="container" id="container">
            {{-- The company at a glance (#591): its score first, then what it has --}}
            @php($h = $shares['health'])
            <div class="stat-row" id="company-stats">
                <div class="stat"><div class="stat-label">Net wealth</div>
                    <div class="stat-value {{ ($stats['wealth'] ?? 0) < 0 ? 'stat-negative' : '' }}">{{ $stats['wealth'] === null ? 'ended' : number_format($stats['wealth']) }}</div></div>
                <div class="stat"><div class="stat-label">Credits</div>
                    <div class="stat-value">{{ number_format($user->credits) }}</div></div>
                <div class="stat"><div class="stat-label">Drones</div>
                    <div class="stat-value">{{ number_format($stats['working']) }}<small>{{ $stats['drones'] > $stats['working'] ? ' + ' . ($stats['drones'] - $stats['working']) . ' wrecked' : ' working' }}</small></div></div>
                <div class="stat"><div class="stat-label">Rank at its age</div>
                    <div class="stat-value" id="peer-rank">@if($rank){{ $rank['rank'] }}<small> of {{ $rank['of'] }}</small>@else — @endif</div>
                    @if($rank)<a class="stat-link" href="{{ url('leaderboard') . '?age=' . $rank['age'] }}">compare</a>@endif</div>
                <div class="stat"><div class="stat-label">Age</div>
                    <div class="stat-value">{{ number_format($stats['age'], 1) }}<small> game days</small></div></div>
            </div>
            <p class="stat-meta">Company {{ $user->user_id }} · released {{ \Carbon\Carbon::parse($user->created_at)->format('F j, Y') }}</p>

            {{-- Health and running costs (#537, #544), in their own section (#591) --}}
            <h2 id="health">Health</h2>
            @if($h['at_risk'] || $h['stalled'])
                <div class="health-banner" id="health-banner">
                    @if($h['at_risk'])<strong>At risk:</strong> {{ $h['at_risk_reason'] }}. A warning, not a verdict.<br>@endif
                    @if($h['stalled'])<strong>Stalled:</strong> none of its drones has bought, sold or made anything for {{ $h['idle'] }} game hours.@endif
                </div>
            @endif
            <p id="company-health">{{ $h['working'] }} working {{ \Illuminate\Support\Str::plural('drone', $h['working']) }}{{ $h['wrecked'] ? ', ' . $h['wrecked'] . ' wrecked' : '' }}.
                Fuel aboard: {{ number_format($h['fuel']) }} (the least, {{ number_format($h['lowest']) }}); {{ number_format($h['used']) }} used over the last game day.
                {{ $h['days'] >= 0 ? 'A drone runs dry in about ' . $h['days'] . ' game ' . \Illuminate\Support\Str::plural('day', $h['days']) . ' at that rate.' : "No drone's fuel is falling." }}</p>
            @if($h['stalled'] || collect($h['drones'])->contains(fn ($d) => $d['idle'] >= 24))
                <ul id="idle-drones">
                    @foreach($h['drones'] as $d)
                        @if($d['idle'] >= 24)
                            @php($says = $d['status'] ? ', says "' . $d['status'] . '"' : '')
                            @php($holds = count($d['holds']) ? ', holds ' . collect($d['holds'])->map(fn ($x) => number_format($x['units']) . ' ' . $x['resource'])->implode(', ') : '')
                            <li><a href="{{ url('/spacecraft/' . $d['drone']) }}">{{ $d['name'] }}</a>: idle {{ $d['idle'] }}
                                game hours{{ $says }}{{ $holds }}</li>
                        @endif
                    @endforeach
                </ul>
            @endif
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

            {{-- Its earnings by game day (#600): what investors judge it by --}}
            <h2 id="earnings">Earnings</h2>
            @include('partials.earnings-chart', ['earningsCompany' => $user->user_id, 'earningsDays' => 30])

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
                                    <span class="text-nowrap">{{ number_format($agent->getCurrentCargoWeight()) }}
                                        / {{ number_format($agent->hull->cargo_capacity) }} kg</span>
                                @endif

                            </td>
                        </tr>
                    @endforeach
                </table>
            </div>

            {{-- Its code and its revisions (#594): every drone of the company runs it --}}
            <div class="row row-30">
                <div class="col">
                    <h2 class="" id="code">Code</h2>
                </div>
            </div>
            @if($code)
                @php($current = $code['current'])
                <div style="white-space: normal; margin-bottom: 1em">
                    Every drone of this company runs this code.
                    @if($current['number'] === 0)
                        It is the code it was released with ({{ $code['release']->created_at }}).
                    @else
                        It is push {{ $current['number'] }} (sent {{ $current['when'] }}): a push reaches each drone at the speed of light.
                    @endif
                    @if($code['release']->copied_from)
                        Its release was copied from <a href="{{ url('spacecraft/' . $code['release']->copied_from) }}">drone {{ $code['release']->copied_from }}</a>{{ $code['release']->copied_word ? ' (the function ' . $code['release']->copied_word . ')' : '' }}.
                    @endif
                    @if($code['same_as'])
                        Its release had the same code as <a href="{{ url('spacecraft/' . $code['same_as']) }}">drone {{ $code['same_as'] }}</a>'s, released earlier.
                    @endif
                    @php($copy = 'release?copy=' . $code['release']->agent_id . ($current['number'] ? '&push=' . $current['number'] : ''))
                    <a class="button button-primary button-sm" href="{{ url($copy) }}">Copy this code</a>
                </div>
                <details id="company-code-details">
                    <summary>Show the code ({{ number_format(substr_count(rtrim($current['script']), "\n") + 1) }} lines)</summary>
                    <pre id="company-code" v-pre style="white-space: pre-wrap; color: #e6e6e6; background: #2b2b36; padding: 1em; border-radius: 4px">{{ $current['script'] }}</pre>
                </details>
                @if($code['words'])
                    <div style="white-space: normal; line-height: 2"><b>Functions</b>:
                        @foreach($code['words'] as $name => $origin)
                            <span class="mr-2 text-nowrap"><code>{{ $name }}</code>
                                @if($origin)
                                    <span>(first in <a href="{{ url('spacecraft/' . $origin) }}">drone {{ $origin }}</a>)</span>
                                @endif
                                <a href="{{ url($copy . '&word=' . urlencode($name)) }}" title="Copy this function">copy</a></span>
                        @endforeach
                    </div>
                @endif
                @if(count($code['revisions']) > 1)
                    <h4 class="mt-3">Revisions</h4>
                    <div class="table-custom-responsive">
                        <table class="table-ticket" id="code-revisions">
                            <tr><th>Revision</th><th>When</th><th class="num">Fee</th><th class="num">Drones</th><th>Code</th></tr>
                            @foreach(array_reverse($code['revisions']) as $r)
                                <tr><td>{{ $r['label'] }}{{ $r['number'] === $current['number'] ? ' (running)' : '' }}</td><td>{{ $r['when'] }}</td>
                                    <td class="num">{{ $r['fee'] === null ? '' : number_format($r['fee']) . ' cr (' . $r['percent'] . '%)' }}</td>
                                    <td class="num">{{ $r['drones'] ?? '' }}</td>
                                    <td style="white-space: normal"><details><summary>Show</summary>
                                        <pre v-pre style="white-space: pre-wrap; color: #e6e6e6; background: #2b2b36; padding: .5em; border-radius: 4px">{{ $r['script'] }}</pre>
                                        <a href="{{ url('release?copy=' . $code['release']->agent_id . ($r['number'] ? '&push=' . $r['number'] : '')) }}">Copy this revision</a>
                                    </details></td></tr>
                            @endforeach
                        </table>
                    </div>
                @endif
            @else
                <p>This company's program came by email: it has no released code to show.</p>
            @endif

            {{-- Code lineage (#616): the earlier code each revision carries, and who carries its code --}}
            @if($lineage)
                <h3 class="mt-4" id="lineage-heading">Lineage</h3>
                <div style="white-space: normal; margin-bottom: .5em">
                    The earlier code each revision carries, compared function by function with every earlier release and push,
                    and with the starter and example scripts. A revision can have several parents. <b>New</b> is the share of its code
                    no earlier revision explains. <a href="{{ url('lineage') . '?company=' . $user->user_id }}">See it on the lineage map</a>.
                </div>
                <div class="table-custom-responsive">
                    <table class="table-ticket" id="lineage">
                        <tr><th>Revision</th><th>Parents (share of its code)</th><th class="num">New</th></tr>
                        @foreach($lineage['revisions'] as $r)
                            <tr><td>{{ $r['label'] }}</td>
                                <td style="white-space: normal">
                                    @if($r['new_share'] === null)
                                        Being worked out.
                                    @elseif(!$r['parents'])
                                        None: all its code is new.
                                    @else
                                        @foreach($r['parents'] as $p)
                                            <a href="{{ $p['url'] }}">{{ $p['label'] }}</a> {{ round($p['share'] * 100) }}%{{ $loop->last ? '' : ',' }}
                                        @endforeach
                                    @endif
                                </td>
                                <td class="num">{{ $r['new_share'] === null ? '' : round($r['new_share'] * 100) . '%' }}</td></tr>
                        @endforeach
                    </table>
                </div>
                <p style="white-space: normal" id="lineage-children">
                    @if($lineage['children'])
                        <b>Carried on by</b>:
                        @foreach($lineage['children'] as $c)
                            <a href="{{ $c['url'] }}">{{ $c['label'] }}</a> ({{ round($c['share'] * 100) }}%){{ $loop->last ? '' : ',' }}
                        @endforeach
                    @else
                        No other company's code carries this company's yet.
                    @endif
                </p>
            @endif

            {{-- Code pushes (#553): new code for its drones, with the password it was released with --}}
            <h3 class="mt-4" id="pushes">Push new code</h3>
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
                        <textarea v-pre class="form-control" id="push-script" name="script" rows="10" spellcheck="false"
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
            @endif

            {{-- The stock market (#471) --}}
            <div class="row row-30">
                <div class="col">
                    <h2 class="" id="shares">Shares</h2>
                    <p>{{ number_format($shares['count']) }} shares, each paying its part of the company's profit once a game day
                        (its sales after tax less what it paid for goods and equipment, after making up earlier losses: see Earnings).
                        Last trade price: <b>{{ $shares['price'] !== null ? number_format($shares['price']) . ' credits' : 'never traded' }}</b>.</p>
                    {{-- What a share earns, judged over 30 days, and the losses before dividends resume (#607) --}}
                    <p id="share-earnings">Profit a day: <b>{{ number_format($shares['profit30']) }}</b> over the last
                        {{ \App\Services\Shares::PROFIT_DAYS }} game days ({{ number_format($shares['profit']) }} over the last 7).
                        @if($shares['carried_loss'] > 0)
                            <b>No dividends until it makes {{ number_format($shares['carried_loss']) }} more profit</b>{{ $shares['carry_days'] !== null
                                ? ', about ' . $shares['carry_days'] . ' ' . \Illuminate\Support\Str::plural('day', $shares['carry_days']) . ' at that rate' : '' }}:
                            earlier losses are made up first.
                        @else
                            It has no losses to make up: each profitable day pays dividends.
                        @endif
                        @if($shares['payback'] !== null)
                            At the last price a share pays itself back in about {{ $shares['payback'] }} game days.
                        @endif
                    </p>
                    {{-- How much of it others hold (#599): offerings and its own sales stop at half --}}
                    <p id="outside-share" @class(['health-banner' => $shares['outside_share'] >= \App\Services\Shares::WARN_OUTSIDE])>
                        Held by other companies: <b>{{ round($shares['outside_share'] * 100) }}%</b>
                        ({{ number_format($shares['outside']) }} of {{ number_format($shares['count']) }} shares), so
                        {{ round($shares['outside_share'] * 100) }}% of its profit goes to them as dividends.
                        @if($shares['outside_share'] >= $shares['max_outside'])
                            That is the most others may hold: it can't offer new shares or sell its own.
                        @elseif($shares['outside_share'] >= \App\Services\Shares::WARN_OUTSIDE)
                            Others may hold at most {{ round($shares['max_outside'] * 100) }}%: think twice before selling more.
                        @else
                            Others may hold at most {{ round($shares['max_outside'] * 100) }}%.
                        @endif
                    </p>
                </div>
            </div>
            {{-- Its share price by game day (#600), in the price charts' style --}}
            @if(\Illuminate\Support\Facades\DB::table('share_trades')->where('share_company', $user->user_id)->where('units', '>', 0)->exists())
                @include('partials.price-volume-chart', ['chartResource' => 0, 'chartDays' => 60, 'chartTitle' => 'Share price and shares traded',
                    'chartSource' => url('api/v1/shares/' . $user->user_id . '/history'), 'chartPer' => 'share', 'chartUnits' => 'shares',
                    'chartPlaceMenu' => false, 'places' => [(int) $user->user_id], 'today' => $today,
                    'chartNote' => 'The dot is the average price a share changed hands at each game day (left axis); the thin line '
                        . 'through it runs from the cheapest trade that day to the dearest. The bars are the shares traded (right axis).'])
            @else
                <p id="share-price-none">Its shares have never traded, so there is no share price to chart yet.</p>
            @endif
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
                    If it fills, the new shares take {{ $o['income_percent'] }}% of the company's future profit:
                    about {{ number_format($o['dividends_a_day'], 1) }} credits a day at today's profit (#572).</p>
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
                        <tr><th>Company</th><th>Order</th><th>Price</th><th>Limit</th><th>Shares it trades now</th></tr>
                        @foreach($shares['orders'] as $o)
                            <tr><td><a href="{{ url('/companies/'.$o['company']) }}">{{ $o['name'] }}</a></td>
                                <td>{{ $o['side'] }}</td><td>{{ number_format($o['price']) }}</td>
                                <td>{{ $o['side'] === 'sell' ? 'keeps ' . $o['keep'] : 'wants ' . $o['want'] }}</td>
                                <td>{{ number_format($o['units']) }}</td></tr>
                        @endforeach
                    </table>
                </div>
            @endif
            @if(count($shares['holdings']))
                <h4>Shares it holds in other companies</h4>
                <div class="table-custom-responsive">
                    <table class="table-ticket">
                        <tr><th>Company</th><th>Shares</th><th>Last price</th><th>Value</th><th>Dividends received</th><th>Best bid now</th></tr>
                        @foreach($shares['holdings'] as $h)
                            <tr><td><a href="{{ url('/companies/'.$h['company']) }}">{{ $h['name'] }}</a></td>
                                <td>{{ number_format($h['units']) }}</td>
                                <td>{{ $h['ended'] ? 'ended: worth nothing' : ($h['price'] !== null ? number_format($h['price']) : '-') }}</td>
                                <td>{{ number_format($h['value']) }}</td><td>{{ number_format($h['dividends']) }}</td>
                                {{-- #610: what it could sell them for now --}}
                                <td>{{ $h['best_bid'] ? number_format($h['best_bid']['price']) . ' for up to ' . number_format($h['best_bid']['units']) . ' (' . $h['best_bid']['by_name'] . ')' : 'no bids' }}</td></tr>
                        @endforeach
                    </table>
                </div>
            @endif
            @if(count($shares['trades']))
                <h4>Recent trades in its shares</h4>
                <div class="table-custom-responsive">
                    <table class="table-ticket">
                        <tr><th>When</th><th>Seller</th><th>Buyer</th><th>Shares</th><th>Price</th></tr>
                        @foreach($shares['trades'] as $t)
                            <tr><td>{{ $t['days_ago'] === 0 ? 'today' : $t['days_ago'] . ' game ' . \Illuminate\Support\Str::plural('day', $t['days_ago']) . ' ago' }}</td>
                                <td><a href="{{ url('/companies/'.$t['seller']) }}">{{ $t['seller_name'] }}</a></td>
                                <td><a href="{{ url('/companies/'.$t['buyer']) }}">{{ $t['buyer_name'] }}</a></td>
                                <td>{{ number_format($t['units']) }}</td><td>{{ number_format($t['price']) }}</td></tr>
                        @endforeach
                    </table>
                </div>
            @endif

        </div>


    </section>
@endsection
