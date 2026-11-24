@extends('layouts.app')
@php $title = 'Your account' ; @endphp
@section('pageTitle', $title)
@section('content')
    <x-page-header :title="$title" />
    <section class="section section-xs section-first text-md-left">
        <div class="container">
            @include('_flash_message')
            @include('account._errors')
            @if($digest = session('digest'))
                <div class="alert alert-info" id="digest" style="white-space: normal">
                    <b>While you were away</b> (since {{ $digest['since'] }}{{ !empty($digest['capped']) ? ', the last ' . \App\Services\PlayerBoards::DIGEST_MAX_DAYS . ' game days' : '' }}):
                    <ul class="mb-0">
                        @foreach($digest['companies'] as $c)
                            @if(!empty($c['at_cap']))<li><strong>{{ $c['name'] }} is ready to mature:</strong> what it earns above {{ number_format($c['credit_cap']) }} now goes to the bank. <a href="{{ url('companies/' . $c['company_id']) }}#road-to-maturity">Mature it</a>.</li>@endif
                            <li>{{ $c['name'] }}{{ $c['startup'] ? ' (startup)' : '' }}@if(!empty($c['to_bank'])) gave the bank {{ number_format($c['to_bank']) }} over its cap,@endif sold {{ number_format($c['sales']) }} and bought
                                {{ number_format($c['purchases']) }} credits' worth.@if(($c['ended'] ?? null) === 'closed') You closed it.@elseif($c['ended'] ?? null) It has ended.@endif</li>
                        @endforeach
                        @if($digest['dividends'])<li>Your shares paid you {{ number_format($digest['dividends']) }} credits in dividends.</li>@endif
                        @if($digest['bank_paid'])<li>The bank paid you {{ number_format($digest['bank_paid']) }} credits for shares you sold it.</li>@endif
                        @foreach($digest['share_fills'] ?? [] as $f)
                            <li>You {{ $f['side'] }} {{ number_format($f['units']) }} {{ $f['name'] }} {{ \Illuminate\Support\Str::plural('share', $f['units']) }} at {{ number_format($f['price']) }}
                                {{ $f['side'] === 'sold' ? 'to' : 'from' }} {{ $f['with'] }} ({{ $f['when'] }}).@if(!empty($f['rebate'])) Your order had rested, so the bank paid you a {{ number_format($f['rebate']) }}-credit rebate.@endif</li>
                        @endforeach
                        @foreach($digest['same_owner_deals'] as $f)
                            <li>{{ $f['company'] }} dealt with {{ $f['with'] }}, a company of yours: flagged on both pages.</li>
                        @endforeach
                        @foreach(array_slice($digest['news'], 0, 5) as $n)<li>News: {{ $n['text'] }}</li>@endforeach
                        @if(!$digest['companies'] && !$digest['dividends'] && !$digest['bank_paid'] && !$digest['news'] && empty($digest['share_fills']))<li>Nothing new.</li>@endif
                    </ul>
                </div>
            @endif
            @if(!session('digest'))
                @include('partials.since-looked')
            @endif
            {{-- Where you are on the path, and the next step (#987) --}}
            @include('partials.pipeline')
            <h2 class="h4">{{ $account['name'] }}</h2>
            @if(!$account['verified'])
                <div class="alert alert-warning" style="white-space: normal">
                    Verify your e-mail address ({{ $account['email'] }}): open the link we sent to it. Verifying lets you
                    mature startups and trade shares.@if(config('forge.player_share_credits') > 0) It brings your account its
                    {{ number_format(config('forge.player_share_credits')) }} credits for buying shares.@endif
                    <form method="POST" action="{{ url('account/verify') }}" class="mt-2">
                        {{ csrf_field() }}
                        <button class="button button-secondary button-sm" type="submit">Send the link again</button>
                    </form>
                </div>
            @endif
            <table class="table table-sm" style="max-width: 32rem">
                <tr><th scope="row">Credits</th><td class="text-right">{{ number_format($account['credits']) }}</td></tr>
                <tr><th scope="row">Credits for buying shares</th><td class="text-right">{{ number_format($account['share_credits']) }}</td></tr>
                @if($account['email'])
                    <tr><th scope="row">E-mail (only you see it)</th><td class="text-right">{{ $account['email'] }}{{ $account['verified'] ? '' : ' (not verified)' }}</td></tr>
                @endif
                @if($account['class_group'])
                    <tr><th scope="row">Class group</th><td class="text-right">{{ $account['class_group']['name'] }}</td></tr>
                @endif
            </table>
            <p>Your credits are what your shares and companies have earned you: dividends, payouts, selling shares and
                companies closing. Your first come from maturing a startup.@if((int) ($account['share_credits'] ?? 0) > 0) Your
                credits for buying shares came with your account: they can only buy shares.@endif</p>

            {{-- Personal records (#775): a record to beat --}}
            @php($records = \App\Services\PlayerBoards::records(auth()->user()))
            <h3 class="h5 mt-4" id="records">Your records</h3>
            <table class="table table-sm" style="max-width: 40rem">
                <tr><th scope="row">Best season (most made in a real day)</th><td class="text-right">@if($records['best_season']){{ number_format($records['best_season']['made']) }}
                    <small>({{ !empty($records['best_season']['today']) ? 'today, so far' : $records['best_season']['day'] }})</small>@else — @endif</td></tr>
                <tr><th scope="row">Fastest to mature</th><td class="text-right">@if($records['fastest_maturity'])<a href="{{ url('companies/' . $records['fastest_maturity']['company_id']) }}">{{ $records['fastest_maturity']['name'] }}</a>,
                    {{ $records['fastest_maturity']['days'] }} game days @else — @endif</td></tr>
                <tr><th scope="row">Best yield among the shares you hold</th><td class="text-right">@if($records['best_yield'])<a href="{{ url('companies/' . $records['best_yield']['company_id']) }}">{{ $records['best_yield']['name'] }}</a>,
                    {{ $records['best_yield']['percent'] }}% over 30 game days @else — @endif</td></tr>
                <tr><th scope="row">Longest-running company</th><td class="text-right">@if($records['longest_running'])<a href="{{ url('companies/' . $records['longest_running']['company_id']) }}">{{ $records['longest_running']['name'] }}</a>,
                    {{ $records['longest_running']['days'] }} game days{{ $records['longest_running']['running'] ? ' and counting' : '' }} @else — @endif</td></tr>
                <tr><th scope="row">Share trades with other players</th><td class="text-right">{{ number_format($records['player_trades']) }}</td></tr>
            </table>
            <p><small>Today's season, a real day (UTC), is on the <a href="{{ url('players') }}#season">Players</a> page.</small></p>

            <h3 class="h5 mt-4" id="shares">Your shares</h3>
            @if(count($portfolio['holdings']))
                <table class="table table-sm">
                    <thead><tr><th scope="col">Company</th><th scope="col" class="text-right">Shares</th>
                        <th scope="col" class="text-right">Cash-out value</th></tr></thead>
                    <tbody>
                    @foreach($portfolio['holdings'] as $h)
                        <tr><td><a href="{{ url('companies/' . $h['company_id']) }}#player-shares">{{ $h['name'] }}</a></td>
                            <td class="text-right">{{ number_format($h['units']) }}</td>
                            <td class="text-right">{{ number_format($h['value']) }}</td></tr>
                    @endforeach
                    </tbody>
                </table>
            @else
                <p>None yet: mature a startup, or buy shares on a matured company's page.</p>
            @endif
            @if(count($portfolio['ended_holdings'] ?? []))
                {{-- Shares of companies that have ended, apart (#758) --}}
                <p class="text-muted" id="ended-holdings">Worth nothing now, as these companies have ended:
                    {{ collect($portfolio['ended_holdings'])->map(fn ($h) => $h['units'] . ' of ' . $h['name'] . ' (ended ' . $h['ended'] . ')')->implode('; ') }}.</p>
            @endif
            @if($portfolio['dividends_30_days'] > 0)
                <p>Your shares have paid you {{ number_format($portfolio['dividends_30_days']) }} credits in dividends over the last 30 game days.</p>
            @endif
            {{-- Its own share trades (#715: nothing said when an order filled) --}}
            @if(count($portfolio['fills']))
                <p id="share-fills">Your latest share trades:</p>
                <ul>
                    @foreach($portfolio['fills'] as $f)
                        <li>{{ ucfirst($f['side']) }} {{ number_format($f['units']) }} of
                            <a href="{{ url('companies/' . $f['company_id']) }}#player-shares">{{ $f['name'] }}</a> at {{ number_format($f['price']) }}
                            {{ $f['side'] === 'sold' ? 'to' : 'from' }} {{ $f['with'] }}, {{ $f['when'] }} ({{ $f['ago'] }})@if(!empty($f['rebate'])), and a {{ number_format($f['rebate']) }}-credit rebate from the bank for your resting order @endif</li>
                    @endforeach
                </ul>
            @endif
            @if(count($portfolio['orders']))
                <p>Your standing orders:</p>
                <ul id="share-orders">
                    @foreach($portfolio['orders'] as $o)
                        <li>{{ $o['side'] === 'buy' ? 'Buy' : 'Sell' }} {{ number_format($o['units']) }} of
                            <a href="{{ url('companies/' . $o['company_id']) }}#player-shares">{{ $o['name'] }}</a> at {{ number_format($o['price']) }}
                            @if(!empty($o['peg']))<small class="text-muted">(follows {{ $o['peg'] === 'buys_at' ? 'what the bank buys at' : 'what the bank sells at' }}
                                {{ $o['offset_percent'] >= 0 ? '+' : '−' }}{{ abs($o['offset_percent']) }}%)</small>@endif
                            {{-- An order the range has left can't fill, and stands unseen (#715) --}}
                            @if($o['out_of_range'])<strong class="text-warning">Can't fill now:</strong>
                                {{ $o['low'] !== null ? 'players trade between ' . number_format($o['low']) . ' and ' . number_format($o['high']) . ' a share now, so it waits until the range comes back. Cancel it, or post a new price.' : 'the company no longer trades.' }}@endif
                            <form method="POST" action="{{ url('share-orders/' . $o['id'] . '/cancel') }}" class="d-inline">
                                {{ csrf_field() }}<button class="button button-secondary button-sm" type="submit">Cancel</button>
                            </form></li>
                    @endforeach
                </ul>
            @endif
            @if(count($portfolio['bank_payments']))
                <p>The bank owes you for shares you sold it:</p>
                <ul>
                    @foreach($portfolio['bank_payments'] as $b)
                        <li>{{ number_format($b['units']) }} of {{ $b['name'] }}: paid {{ $b['due'] }}, at most
                            {{ number_format($b['price_at_sale']) }} a share</li>
                    @endforeach
                </ul>
            @endif

            {{-- The account trading script (#768): Lua that trades your shares while you're away --}}
            @if($tradeScript !== null)
                <h3 class="h5 mt-4" id="trade-script">Your trading script</h3>
                <p>A Lua script that trades your shares for you. Every few real minutes it is given the share market and your
                    account (<code>trade(market)</code>) and can <code>buy</code>, <code>sell</code>, post pegged orders, <code>cancel</code>,
                    and buy from or sell to the bank, under the same rules as you, at most 20 actions a run. What it keeps in
                    <code>memory</code> is there next run. Its orders show on the book as <i>(script)</i>. Start from
                    <a href="https://github.com/Darwin-River/Ex-Machinis/blob/main/examples/traders/payback.lua">the payback trader</a>
                    (buy when dividends pay a share back soon enough, sell when a company is about to end: made for classes) or <a href="https://github.com/Darwin-River/Ex-Machinis/blob/main/examples/traders/value.lua">the
                    value trader</a>, watch it on the <a href="{{ url('market/trade') }}">Trading desk</a>, and see the <a href="{{ url('manual') }}#trading-script">manual</a>.</p>
                <form method="POST" action="{{ url('account/trade-script') }}">
                    {{ csrf_field() }}
                    <label class="sr-only" for="trade-code">Your trading script</label>
                    @include('partials.lua-editor')
                    <textarea v-pre class="form-control mb-2" id="trade-code" name="code" rows="12" style="font-family: monospace" data-lua-editor spellcheck="false"
                              placeholder="function trade(market) ... end">{{ old('code', $tradeScript['code']) }}</textarea>
                    <label class="mr-3"><input type="checkbox" name="enabled" value="1" @checked($tradeScript['enabled'])> Run it every few real minutes</label>
                    <button class="button button-secondary button-sm" type="submit">Save</button>
                    {{-- A test drive (#1005): this draft against the market now, applying nothing --}}
                    <button class="button button-secondary button-sm" type="submit" formaction="{{ url('account/trade-script/test-drive') }}">Test drive (changes nothing)</button>
                </form>
                @if($drive = session('test_drive'))
                    <div class="alert alert-info mt-2" id="trade-test-drive" style="white-space: normal"><b>Test drive</b> against the market now (nothing was traded or saved):
                        <ul class="mb-0" v-pre>@foreach($drive as $line)<li>{{ $line }}</li>@endforeach</ul></div>
                @endif
                @if($tradeScript['code'] !== '')
                    <form method="POST" action="{{ url('account/trade-script/run') }}" class="d-inline">
                        {{ csrf_field() }}<button class="button button-secondary button-sm mt-2" type="submit">Run it once now</button>
                    </form>
                    <p class="mt-2"><small class="text-muted">It has run {{ number_format($tradeScript['runs']) }} times{{ $tradeScript['last_run'] ? ', last ' . $tradeScript['last_run'] : '' }}.</small></p>
                    @if(count($tradeScript['log']))
                        <ul id="trade-script-log">
                            @foreach(array_slice($tradeScript['log'], 0, 5) as $run)
                                <li>{{ $run['when'] }}: {{ implode('; ', $run['lines']) }}</li>
                            @endforeach
                        </ul>
                    @endif
                @endif
            @endif

            <h3 class="h5 mt-4" id="startups">Your startups</h3>
            <p>Every company starts as a startup: <a href="{{ url('release') }}">release a drone</a> and the bank lends it
                its drone and starting credits, plus a fee ({{ number_format($mine['grant']) }} credits in all). A startup
                trades only with the House, and you can change its code whenever you like, for free. Once it holds its debt and
                {{ number_format(\App\Services\Startups::matureMargin()) }} more, mature it: it repays, trades with everyone, and its
                {{ \App\Services\PlayerShares::COUNT }} shares are yours. Its code stays yours to change while you hold all its shares;
                the first share you sell makes it public and locks its code. A company ends through its own code
                (<code>close_company()</code>): its page's Close button pushes that for you, and a startup's debt is cancelled.
                You own {{ $mine['slots']['used'] }} of at most {{ $mine['slots']['max'] }} companies outright (startups included).</p>
            @if(count($mine['startups']))
                <table class="table table-sm">
                    <thead><tr><th scope="col">Startup</th><th scope="col" class="text-right">Credits</th>
                        <th scope="col" class="text-right">Owes</th><th scope="col" class="text-right">Holds at most</th>
                        <th scope="col" class="text-right">To the bank</th><th scope="col"></th></tr></thead>
                    <tbody>
                    @foreach($mine['startups'] as $seed)
                        <tr>
                            <td><a href="{{ url('companies/' . $seed['company_id']) }}">{{ $seed['name'] }}</a></td>
                            <td class="text-right">{{ number_format($seed['credits']) }}</td>
                            <td class="text-right">{{ number_format($seed['debt']) }}</td>
                            <td class="text-right">{{ $seed['credit_cap'] ? number_format($seed['credit_cap']) : '' }}</td>
                            <td class="text-right">{{ number_format($seed['to_bank']) }}</td>
                            <td class="text-right">
                                @if($seed['can_mature'] && $account['verified'])
                                    <form method="POST" action="{{ url('startups/' . $seed['company_id'] . '/mature') }}" class="d-inline"
                                          onsubmit="return confirm('Mature this startup? It repays its debt and its 100 shares are yours; its code stays yours to change until you sell a share.')">
                                        {{ csrf_field() }}<button class="button button-primary button-sm" type="submit">Mature</button>
                                    </form>
                                @endif
                                <form method="POST" action="{{ url('startups/' . $seed['company_id'] . '/close') }}" class="d-inline"
                                      onsubmit="return confirm('Close this startup? It leaves the game with all it holds.')">
                                    {{ csrf_field() }}<button class="button button-secondary button-sm" type="submit">Close</button>
                                </form>
                            </td>
                        </tr>
                        <tr><td colspan="4">
                            <details><summary>Change its code</summary>
                                <form method="POST" action="{{ url('startups/' . $seed['company_id'] . '/push') }}" class="mt-2">
                                    {{ csrf_field() }}
                                    @include('partials.lua-editor')
                                    <textarea v-pre class="form-control" name="script" rows="8" maxlength="{{ config('forge.max_script_length') }}" data-lua-editor spellcheck="false"
                                              aria-label="New code for {{ $seed['name'] }}" required></textarea>
                                    <button class="button button-secondary button-sm mt-2" type="submit">Send the new code</button>
                                </form>
                            </details>
                        </td></tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
            @if(count($mine['companies']))
                <p>Your matured companies:
                    @foreach($mine['companies'] as $co)
                        <a href="{{ url('companies/' . $co['company_id']) }}">{{ $co['name'] }}</a>{{ $co['ended'] ? ' (ended)' : '' }}{{ $loop->last ? '' : ',' }}
                    @endforeach
                </p>
            @endif
            <form method="POST" action="{{ url('startups/claim') }}" class="form-inline mb-3">
                {{ csrf_field() }}
                <label class="mr-2" for="claim">Released as a guest? Claim it with its code:</label>
                <input class="form-control mr-2 mb-2" id="claim" name="code" type="text" maxlength="64" required>
                <button class="button button-secondary button-sm mb-2" type="submit">Claim</button>
            </form>

            <h3 class="h5 mt-4">The API</h3>
            <p>Programs act for your account through the <a href="https://github.com/Darwin-River/Ex-Machinis/blob/main/documents/api.md">API</a> with a token, sent
                as <code>Authorization: Bearer</code> and the token. A new token replaces the old one.</p>
            @if($token = session('token'))
                <div class="alert alert-info" style="white-space: normal">Your new token (copy it now: it won't be shown
                    again): <code>{{ $token }}</code></div>
            @endif
            <form method="POST" action="{{ url('account/token') }}">
                {{ csrf_field() }}
                <button class="button button-secondary button-sm" type="submit">Make a new API token</button>
            </form>

            {{-- Opt-in e-mail notices (#761) --}}
            @if(\App\Services\Notices::available())
                <h3 class="h5 mt-4" id="notices">E-mail notices</h3>
                @if($account['class_group'])
                    <p>Your account has no e-mail address, so it gets no e-mails. Your teacher can turn on a daily
                        summary of what needs the class.</p>
                @elseif(!$account['verified'])
                    <p>Verify your e-mail address to turn on e-mail notices.</p>
                @else
                    @php($noticeSettings = \App\Services\Notices::settings(auth()->user()))
                    <p>Pick what we may e-mail you about. The two about your startups' maturing start on; the rest are off until
                        you turn them on. All but the weekly digest and filled orders come together in one e-mail, at most one a
                        real day, each with something to try next; filled orders come at most hourly. Every e-mail has a link that
                        turns them all off.</p>
                    {{-- The basic ones first, the traders' folded (#840) --}}
                    @php($basicKinds = ['can_mature', 'at_cap', 'at_risk', 'wrecked', 'digest'])
                    @php($tradeKinds = array_diff(array_keys(\App\Services\Notices::KINDS), $basicKinds))
                    <form method="POST" action="{{ url('account/notices') }}">
                        {{ csrf_field() }}
                        <p class="mb-1"><strong>Your companies</strong></p>
                        @foreach($basicKinds as $kind)
                            <div><label><input type="checkbox" name="notices[]" value="{{ $kind }}" @checked($noticeSettings[$kind])> {{ \App\Services\Notices::KINDS[$kind] }}</label></div>
                        @endforeach
                        <details class="mt-2" @if(collect($tradeKinds)->contains(fn ($k) => $noticeSettings[$k])) open @endif>
                            <summary><strong>For traders</strong> ({{ count($tradeKinds) }} more: share orders, the bank, cash-out moves, price alerts)</summary>
                            @foreach($tradeKinds as $kind)
                                <div><label><input type="checkbox" name="notices[]" value="{{ $kind }}" @checked($noticeSettings[$kind])> {{ \App\Services\Notices::KINDS[$kind] }}</label></div>
                            @endforeach
                        </details>
                        <details class="mt-2"><summary>What a notice e-mail looks like</summary>
                            <div class="p-2" style="border: 1px solid #263052; border-radius: 8px; white-space: normal">
                                <p class="mb-1"><b>Ex Machinis: 2 things about your companies</b></p>
                                <p class="mb-1">• Copper Co can mature: it holds 4,780 of the 4,700 it needs. <i>Try next:</i> mature it on its page
                                    before it reaches its credit cap.</p>
                                <p class="mb-1">• Drone Copper Co-2 was wrecked at the Moon: out of fuel. <i>Try next:</i> read its post-mortem, and
                                    keep a fuel floor in your script.</p>
                                <p class="mb-0"><small>Turn these off: a link in every e-mail.</small></p>
                            </div>
                        </details>
                        <button class="button button-secondary button-sm mt-2" type="submit">Save</button>
                    </form>
                    {{-- House price alerts (#761, part 2) --}}
                    @php($alerts = \App\Services\Notices::alerts(auth()->user()))
                    <p class="mt-3 mb-1" id="alerts"><strong>Your price alerts</strong> (at most {{ \App\Services\Notices::MAX_ALERTS }}):
                        tell me when the House bids more than a price for a good.</p>
                    @if($alerts)
                        <ul>
                            @foreach($alerts as $a)
                                <li>{{ $a['resource_name'] ?? ('good ' . $a['resource']) }} over {{ number_format($a['above']) }},
                                    {{ $a['place_name'] ? 'at ' . $a['place_name'] : 'anywhere' }}
                                    <form method="POST" action="{{ url('account/alerts/' . $a['id'] . '/remove') }}" class="d-inline">
                                        {{ csrf_field() }}<button class="button button-secondary button-sm" type="submit">Remove</button>
                                    </form></li>
                            @endforeach
                        </ul>
                    @endif
                    @php($alertGoods = \Illuminate\Support\Facades\DB::table('house_market as h')->join('resources as r', 'r.id', '=', 'h.resource')->where('h.side', 1)->distinct()->orderBy('r.name')->get(['r.id', 'r.name']))
                    @php($alertPlaces = \Illuminate\Support\Facades\DB::table('stations as s')->join('objects as o', 'o.object_id', '=', 's.object_id')->distinct()->orderBy('o.object_name')->get(['o.object_id', 'o.object_name']))
                    <form method="POST" action="{{ url('account/alerts') }}" class="form-inline">
                        {{ csrf_field() }}
                        <label class="mr-2" for="alert-resource">Good</label>
                        <select class="form-control mr-2 mb-2" id="alert-resource" name="resource" required>
                            @foreach($alertGoods as $g)<option value="{{ $g->id }}">{{ $g->name }}</option>@endforeach
                        </select>
                        <label class="mr-2" for="alert-place">at</label>
                        <select class="form-control mr-2 mb-2" id="alert-place" name="place">
                            <option value="">anywhere</option>
                            @foreach($alertPlaces as $pl)<option value="{{ $pl->object_id }}">{{ $pl->object_name }}</option>@endforeach
                        </select>
                        <label class="mr-2" for="alert-above">over</label>
                        <input class="form-control mr-2 mb-2" id="alert-above" name="above" type="number" min="1" required style="width: 8rem">
                        <button class="button button-secondary button-sm mb-2" type="submit">Add the alert</button>
                    </form>
                    @if(count($account['teaches']))
                        <p class="mt-3">For each of your class groups, a daily summary of what needs your pupils (a startup
                            that can mature, a company at risk, a wrecked drone), with what each could try next. Your pupils
                            get no e-mails.</p>
                        <ul>
                            @foreach($account['teaches'] as $group)
                                <li>{{ $group['name'] }}: {{ $group['notices'] ? 'summary on' : 'summary off' }}
                                    <form method="POST" action="{{ url('account/classes/' . $group['id'] . '/notices') }}" class="d-inline">
                                        {{ csrf_field() }}<input type="hidden" name="on" value="{{ $group['notices'] ? 0 : 1 }}">
                                        <button class="button button-secondary button-sm" type="submit">{{ $group['notices'] ? 'Turn off' : 'Turn on' }}</button>
                                    </form></li>
                            @endforeach
                        </ul>
                    @endif
                @endif
            @endif

            @if(!$account['class_group'] && $account['verified'])
                <h3 class="h5 mt-4">Class groups</h3>
                <p>Teaching a class? Make a class group: accounts for your pupils, named after the group ("Orbit 1",
                    "Orbit 2"...) with passwords you hand out. Your pupils need no e-mail addresses and give no names,
                    and the group trades shares only within itself, with its own leaderboards.</p>
                @if(count($account['teaches']))
                    <ul>
                        @foreach($account['teaches'] as $group)
                            <li><a href="{{ url('account/classes/' . $group['id']) }}">{{ $group['name'] }}</a>: {{ $group['pupils'] }} pupils</li>
                        @endforeach
                    </ul>
                @endif
                <form method="POST" action="{{ url('account/classes') }}" class="form-inline">
                    {{ csrf_field() }}
                    <label class="mr-2" for="group_name">Group name</label>
                    <input class="form-control mr-2 mb-2" id="group_name" name="group_name" type="text" maxlength="25"
                           value="{{ old('group_name') }}" required>
                    <label class="mr-2" for="pupils">Pupils</label>
                    <input class="form-control mr-2 mb-2" id="pupils" name="pupils" type="number" min="1"
                           max="{{ config('forge.class_group_max') }}" value="{{ old('pupils', 30) }}" required>
                    <button class="button button-secondary button-sm mb-2" type="submit">Make the group</button>
                </form>
            @endif

            @if($bots ?? null)
                <h3 class="h5 mt-4" id="claude-players">Claude players</h3>
                <p>You are an operator. A key lets Claude make Claude players through the API
                    (<code>POST /api/v1/bots</code> with the header <code>X-Bot-Key</code>): accounts verified without an e-mail
                    address, playing by every rule a person does. Only the database marks them: to players they look like anyone else. Enter a key in Claude's environment as a
                    network secret for this site; it is shown here once.</p>
                @if(session('bot_key'))
                    <div class="alert alert-warning" id="new-bot-key">Your new key, shown only now:
                        <code>{{ session('bot_key') }}</code></div>
                @endif
                @if(count($bots['keys']))
                    <ul>
                        @foreach($bots['keys'] as $k)
                            <li>Key {{ $k['id'] }}, made {{ $k['made'] }}:
                                @if($k['revoked']) revoked {{ $k['revoked'] }}
                                @else
                                    <form method="POST" action="{{ url('account/bot-keys/' . $k['id'] . '/revoke') }}" class="d-inline">
                                        {{ csrf_field() }}
                                        <button class="button button-secondary button-sm" type="submit">Revoke</button>
                                    </form>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
                <form method="POST" action="{{ url('account/bot-keys') }}">
                    {{ csrf_field() }}
                    <button class="button button-secondary button-sm" type="submit">Make a key</button>
                </form>
                @if(count($bots['players']))
                    <p class="mt-2 mb-1">Your Claude players ({{ count($bots['players']) }} of {{ config('forge.bot_max') }}):</p>
                    <ul>
                        @foreach($bots['players'] as $p)
                            <li><a href="{{ url('players/' . rawurlencode($p['name'])) }}">{{ $p['name'] }}</a>:
                                {{ number_format($p['credits']) }} credits, {{ $p['companies'] }} companies</li>
                        @endforeach
                    </ul>
                @endif
            @endif

            <form method="POST" action="{{ url('logout') }}" class="mt-4">
                {{ csrf_field() }}
                <button class="button button-secondary button-sm" type="submit">Log out</button>
            </form>
        </div>
    </section>
@endsection
