@extends('layouts.app')
@php $title = $user->name ; @endphp
@section('pageTitle', $title)
@push('styles')
    <style>
        /* The company at a glance (#591) */
        /* Four to a row, two on a phone (#744: 7 tiles wrapped into 6 + 1), in the cards' one colour */
        .stat-row { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin: 0 0 8px; }
        @media (max-width: 760px) { .stat-row { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        .stat { background: #111729; border: 1px solid rgba(255,255,255,0.1); border-radius: 10px; padding: 12px 14px; text-align: left; min-width: 0; }
        .stat-label { font-size: 0.8em; text-transform: uppercase; letter-spacing: 0.05em; opacity: 0.75; }
        .stat-value { font-size: 1.6em; font-weight: 600; font-variant-numeric: tabular-nums; line-height: 1.3; }
        .stat-value small { font-size: 0.55em; font-weight: 400; opacity: 0.8; }
        .stat-negative { color: #ff7b72; }
        .stat-link { font-size: 0.85em; }
        .stat-meta { font-size: 0.85em; opacity: 0.75; margin-bottom: 1.5em; }
        /* Seedling or matured, and the road to maturity (#697) */
        .co-badge { display: inline-block; font-size: 0.85rem; font-weight: 700; padding: 3px 10px; border-radius: 99px; vertical-align: middle;
              background: rgba(76, 201, 240, 0.16); color: #4cc9f0; font-family: system-ui, sans-serif; letter-spacing: 0.02em; }
        .co-badge-matured { background: rgba(90, 209, 154, 0.16); color: #5ad19a; }
        /* One status scale (#744): steady green, at risk and stalled amber, failing red, ended grey */
        .co-badge-steady { background: rgba(90, 209, 154, 0.16); color: #5ad19a; }
        .co-badge-warn { background: rgba(240, 180, 41, 0.16); color: #f0b429; }
        .co-badge-bad { background: rgba(255, 123, 114, 0.16); color: #ff7b72; }
        .co-badge-ended { background: rgba(163, 166, 178, 0.18); color: #c3c6d0; }
        a.co-badge { text-decoration: none; }
        .road { background: #111729; border: 1px solid rgba(255,255,255,0.1); border-radius: 10px; padding: 16px 18px; margin: 0 0 16px; white-space: normal; }
        .road h2 { font-size: 1.25rem; margin: 0 0 6px; }
        .road-bar { position: relative; height: 16px; background: #263052; border-radius: 99px; margin: 30px 0 30px; }
        .road-fill { position: absolute; left: 0; top: 0; bottom: 0; border-radius: 99px; background: linear-gradient(90deg, #ffb547, #ffd08a); }
        .road-mark { position: absolute; top: -6px; bottom: -6px; width: 2px; background: #e6e9f2; }
        .road-mark span { position: absolute; left: 50%; transform: translateX(-50%); font-size: 12px; color: #c3c9d8; white-space: nowrap; }
        .road-mark.up span { bottom: 100%; margin-bottom: 2px; } .road-mark.down span { top: 100%; margin-top: 2px; }
        .road-mark.cap { background: #ff6b6b; }
        .road-mark.edge-r span { left: auto; right: 0; transform: none; } .road-mark.edge-l span { left: 0; transform: none; }
        .road-actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-start; margin-top: 12px; }
        .road-actions form { margin: 0; }
        .road-actions details { flex-basis: 100%; }
        .health-banner { border-left: 4px solid #e3b341; background: rgba(227,179,65,0.12); padding: 10px 14px; margin: 0 0 12px; }
        /* Wide tables scroll sideways inside the page on a phone, not the page (#692) */
        .table-scroll, .table-custom-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; max-width: 100%; }        /* Code revisions (#594) */
        #code-revisions .num { text-align: right !important; font-variant-numeric: tabular-nums; white-space: nowrap; }
        #code-revisions td:first-child { color: #fff !important; white-space: nowrap; }
        /* Code lineage (#616) */
        #lineage .num { text-align: right !important; font-variant-numeric: tabular-nums; white-space: nowrap; }
        #lineage td:first-child { white-space: nowrap; }
    </style>
@endpush
@section('content')
    {{-- Seedling or matured, and whose (#697) --}}
    @php($badge = ($user->eliminated_at ?? null) !== null ? 'Ended' : ((int) ($user->seedling ?? 0) === 1 ? 'Seedling' : (($user->matured_t ?? null) !== null ? 'Matured' : null)))
    <x-page-header :subtitle="$owner ? 'Owned by ' . $owner : null">
        @php($hb = $shares['health'] ?? [])
        @php($healthBadge = $badge === 'Ended' ? null : (!empty($hb['stalled']) ? ['Stalled', 'warn'] : (!empty($hb['failing_reason']) ? ['Failing', 'bad'] : (!empty($hb['at_risk']) ? ['At risk', 'warn'] : ['Steady', 'steady']))))
        <x-slot:title>{{$title}}@if($badge) <span @class(['co-badge', 'co-badge-matured' => $badge === 'Matured', 'co-badge-ended' => $badge === 'Ended']) id="company-badge">{{ $badge }}</span>@endif @if($healthBadge) <a href="#health" class="co-badge co-badge-{{ $healthBadge[1] }}" id="health-badge">{{ $healthBadge[0] }}</a>@endif</x-slot:title>
        <li><a href="/companies">Companies</a></li>
        <li class="active">{{$title}}</li>
    </x-page-header>
    <section class="section section-xs section-first text-md-left">
        <div class="container" id="container">
            {{-- Its owner sees what changed since they last looked (#771) --}}
            @if(auth()->check() && ($user->owner_id ?? null) !== null && (int) $user->owner_id === (int) auth()->user()->user_id)
                @include('partials.since-looked')
            @endif
            {{-- The company at a glance (#591): its score first, then what it has --}}
            @php($h = $shares['health'])
            <div class="stat-row" id="company-stats">
                @if(($user->eliminated_at ?? null) !== null)
                    {{-- Ended, with its reason (#744: it read "NET WEALTH: ended" beside its credits) --}}
                    <div class="stat" id="stat-ended"><div class="stat-label">Ended</div>
                        <div class="stat-value" style="font-size: 1.15em">{{ \App\Services\GameDate::at((int) $user->eliminated_at, false) }}</div>
                        <small>{{ ['closed' => 'its player closed it', 'reset' => 'the world was reset', 'tax' => "it couldn't pay its tax", 'dissolved' => 'no credits for too long', 'drones' => 'it lost its last drone'][$user->end_cause ?? ''] ?? 'it ended' }}</small></div>
                @else
                    <div class="stat"><div class="stat-label">Net wealth</div>
                        <div class="stat-value {{ ($stats['wealth'] ?? 0) < 0 ? 'stat-negative' : '' }}">{{ $stats['wealth'] === null ? '—' : number_format($stats['wealth']) }}</div>
                        {{-- A seedling's starts below 0 (#833): its kit is equipment, which net wealth doesn't count --}}
                        @if($road)<small>its kit and drone aren't counted, so a seedling starts about 2,000 below 0</small>@endif</div>
                @endif
                <div class="stat"><div class="stat-label">Credits</div>
                    <div class="stat-value">{{ number_format($user->credits) }}</div></div>
                @if($road)
                    <div class="stat" id="owes-the-bank"><div class="stat-label">Owes the bank</div>
                        <div class="stat-value">{{ number_format($road['debt']) }}</div></div>
                @endif
                <div class="stat"><div class="stat-label">Drones</div>
                    <div class="stat-value">{{ number_format($stats['working']) }}<small>{{ $stats['drones'] > $stats['working'] ? ' + ' . ($stats['drones'] - $stats['working']) . ' wrecked' : ' working' }}</small></div></div>
                @if(isset($stats['cash_out']))
                    <div class="stat" id="stat-cash-out"><div class="stat-label">Cash-out a share</div>
                        <div class="stat-value">{{ number_format($stats['cash_out']) }}</div>
                        <a class="stat-link" href="#player-shares">the bank's prices</a></div>
                    <div class="stat" id="stat-dividends"><div class="stat-label">Dividends a share, 30 days</div>
                        <div class="stat-value">{{ number_format($stats['dividends30']) }}</div></div>
                @endif
                <div class="stat"><div class="stat-label">Rank at its age</div>
                    <div class="stat-value" id="peer-rank">@if($rank){{ $rank['rank'] }}<small> of {{ $rank['of'] }}</small>@else — @endif</div>
                    @if($rank)<a class="stat-link" href="{{ url('leaderboard') . '?age=' . $rank['age'] }}">compare</a>@endif</div>
                <div class="stat"><div class="stat-label">Age</div>
                    <div class="stat-value">{{ number_format($stats['age'], 1) }}<small> game days</small></div></div>
            </div>
            <p class="stat-meta">Company {{ $user->user_id }} · released {{ ($user->founded_t ?? null) ? \App\Services\GameDate::at((int) $user->founded_t, false) : \Carbon\Carbon::parse($user->created_at)->format('j M Y') }}</p>
            {{-- The new rules (#637) --}}
            @php($ended = ($user->eliminated_at ?? null) !== null)
            @if($ended)
                {{-- An ended company owes nothing and risks nothing (#686) --}}
                @php($endWhy = ['closed' => 'its player closed it', 'reset' => 'the world was reset', 'tax' => "it couldn't pay its tax",
                    'dissolved' => 'it had no credits for too long', 'drones' => 'it lost its last drone'][$user->end_cause ?? ''] ?? null)
                <p class="stat-meta" id="seedling">It ended on {{ \App\Services\DronePage::when((int) $user->eliminated_at) }}{{ $endWhy ? ': ' . $endWhy : '' }}.
                    @if((int) ($user->seedling ?? 0) === 1) It was a seedling: its debt was cancelled, and it owes nothing. @endif</p>
                {{-- The failure card (#773): what went wrong, and a fixed copy a click away --}}
                @php($pm = \App\Services\PostMortem::of((int) $user->user_id))
                @if($pm)
                    <section class="health-banner" id="post-mortem" style="white-space: normal">
                        <strong>What happened.</strong>
                        <ul class="mb-1">@foreach($pm['lines'] as $l)<li>{{ $l }}</li>@endforeach
                            @if($pm['error'])<li>{{ $pm['error']['drone'] }}'s last error ({{ $pm['error']['when'] }}): <code>{{ $pm['error']['text'] }}</code>
                                @if($pm['line'])<br>Line {{ $pm['line']['number'] }} of its code ({{ strtolower($pm['line']['in']) }}): <code>{{ $pm['line']['code'] }}</code>@endif</li>@endif
                        </ul>
                        @if($pm['copy'])<a class="button button-primary button-sm" id="fixed-copy" href="{{ url($pm['copy']) }}">Start a fixed copy</a>
                            <small>opens Start a seedling with its code loaded and this noted</small>@endif
                    </section>
                @endif
            @elseif((int) ($user->seedling ?? 0) === 1)
                <p class="stat-meta" id="seedling">A seedling: it owes the bank {{ number_format((int) $user->debt) }} credits, and
                    trades only with the House until it matures.</p>
            @elseif(($user->matured_t ?? null) !== null)
                <p class="stat-meta" id="seedling">Matured on {{ \App\Services\DronePage::when((int) $user->matured_t) }}: its code is locked for good.</p>
            @endif

            @if($road)
                {{-- The road to maturity (#697): its credits against its debt and the mark it matures at --}}
                @php($scale = max($road['mature_at'], $road['cap'] ?? 0, $road['credits'], 1) * 1.04)
                @php($at = fn ($v) => round(min(100, max(0, $v / $scale * 100)), 2))
                @php($edge = fn ($v) => $at($v) > 85 ? 'edge-r' : ($at($v) < 12 ? 'edge-l' : ''))
                @php($toGo = max(0, $road['mature_at'] + $road['owed_tax'] - $road['credits']))
                <section class="road" id="road-to-maturity">
                    <h2>Road to maturity</h2>
                    <div>It holds <b>{{ number_format($road['credits']) }}</b> credits. It can mature at
                        <b>{{ number_format($road['mature_at']) }}</b>: its debt of {{ number_format($road['debt']) }}, plus
                        {{ number_format($road['margin']) }} to work with{{ $road['owed_tax'] > 0 ? ', and the ' . number_format($road['owed_tax']) . ' processor tax it owes' : '' }}.
                        @if($road['can_mature']) <b>It can mature now.</b> @else {{ number_format($toGo) }} to go.@if(($road['forecast']['game_t'] ?? null) !== null)
                            <span id="road-forecast">At its pace over the last {{ \App\Services\CompanyNow::PACE_DAYS }} game days, it matures about
                                <b>{{ \App\Services\GameDate::at($road['forecast']['game_t'], false) }}</b> ({{ $road['forecast']['minutes'] < 90 ? 'in ' . $road['forecast']['minutes'] . ' real minutes' : 'in about ' . round($road['forecast']['minutes'] / 60, 1) . ' real hours' }}).</span>@endif @endif</div>
                    <div class="road-bar" role="img" aria-label="{{ number_format($road['credits']) }} of {{ number_format($road['mature_at']) }} credits, {{ min(100, (int) floor(100 * $road['credits'] / max(1, $road['mature_at']))) }} percent">
                        <div class="road-fill" style="width: {{ $at($road['credits']) }}%"></div>
                        <div class="road-mark up {{ $edge($road['debt']) }}" style="left: {{ $at($road['debt']) }}%"><span>debt {{ number_format($road['debt']) }}</span></div>
                        <div class="road-mark down {{ $edge($road['mature_at']) }}" style="left: {{ $at($road['mature_at']) }}%"><span>mature at {{ number_format($road['mature_at']) }}</span></div>
                        @if($road['cap'])
                            <div class="road-mark cap up {{ $edge($road['cap']) }}" style="left: {{ $at($road['cap']) }}%" title="Its credit cap: what it earns above this goes to the bank"><span>cap {{ number_format($road['cap']) }}</span></div>
                        @endif
                    </div>
                    @php($lastPush = $pushes['log'][0] ?? null)
                    @if($lastPush && $lastPush['effect'])
                        <p class="mb-2" id="last-push-effect" style="white-space: normal"><b>Your last push</b> (push {{ $lastPush['number'] }}, {{ $lastPush['when'] }}):
                            @if($lastPush['effect']['ready']) {{ $lastPush['effect']['verdict'] }}, over {{ $lastPush['effect']['span_days'] }} game days each side.
                            @else {{ $lastPush['effect']['note'] }}. @endif
                            <a href="#pushes">Each push</a></p>
                    @endif
                    @if($road['cap'] && $road['credits'] >= $road['cap'])
                        <div class="health-banner" id="at-cap"><strong>It is at its credit cap:</strong> what it earns above
                            {{ number_format($road['cap']) }} now goes to the bank (#749). It is ready to mature: mature it now.</div>
                    @endif
                    @if($road['cap'])
                        <p class="mb-2"><small>Its credit cap is {{ number_format($road['cap']) }}: what it earns above that goes to the bank, so mature it before it gets there.</small></p>
                    @endif
                    @if($h['stalled'])
                        <div class="health-banner" id="stalled-banner"><strong>It has stalled:</strong> {{ $h['stalled_reason'] }}.
                            It won't reach the mark like this. See <a href="#health">Health</a> for what its drones last said, then push a fix or close it.</div>
                    @endif
                    @if($road['owner'])
                        <div class="road-actions" id="seedling-actions">
                            @if($road['can_mature'])
                                <form method="POST" action="{{ url('seedlings/' . $user->user_id . '/mature') }}">{{ csrf_field() }}
                                    <button class="button button-primary" type="submit">Mature it</button></form>
                            @endif
                            <form method="POST" action="{{ url('seedlings/' . $user->user_id . '/close') }}"
                                  onsubmit="return confirm('Close this seedling? It leaves the game with all it holds, and its debt is cancelled.')">{{ csrf_field() }}
                                <button class="button button-secondary" type="submit">Close it (debt cancelled)</button></form>
                            <details id="seedling-push">
                                <summary class="button button-secondary" style="display: inline-block">Push new code {{ $road['push_fee'] === 0 ? '(the first is free)' : '(adds ' . number_format($road['push_fee']) . ' to its debt)' }}</summary>
                                <form method="POST" action="{{ url('seedlings/' . $user->user_id . '/push') }}" class="mt-2">{{ csrf_field() }}
                                    <label for="seedling-script">New script (Lua): its working drones drop what they run and start it</label>
                                    <textarea v-pre class="form-control" id="seedling-script" name="script" rows="10" spellcheck="false"
                                              maxlength="{{ config('forge.max_script_length') }}" required>{{ $code['current']['script'] ?? '' }}</textarea>
                                    <button class="button button-primary mt-2" type="submit">Push it</button>
                                </form>
                            </details>
                        </div>
                    @endif
                </section>
            @endif

            {{-- Stalled, at the top where a buyer looks first (#689) --}}
            @if($h['stalled'] && !$road)
                <div class="health-banner" id="stalled-banner"><strong>Stalled:</strong> {{ $h['stalled_reason'] }}.
                    See <a href="#health">Health</a> for which drones and what they last said.</div>
            @endif

            {{-- The new rules (#638): players' shares and the bank --}}
            @if($playerShares)
                <h2 id="player-shares">Shares</h2>
                <p>The bank buys this company's shares at their cash-out value,
                    <b>{{ number_format($playerShares['buys_at']) }}</b> a share now (its credits
                    {{ number_format($playerShares['cash_out']['credits']) }}, goods {{ number_format($playerShares['cash_out']['goods']) }}
                    and hulls {{ number_format($playerShares['cash_out']['hulls']) }}, less debts
                    {{ number_format($playerShares['cash_out']['debts']) }}), paying {{ config('forge.bank_pay_days') }} game days later at
                    the lower of that value and the value then. It sells the shares it holds
                    ({{ number_format($playerShares['bank_holds']) }}) at <b>{{ number_format($playerShares['sells_at']) }}</b>.
                    Players trade between {{ number_format($playerShares['low']) }} and {{ number_format($playerShares['high']) }} a share.
                    It has <b id="share-count">{{ number_format($playerShares['count']) }}</b> shares
                    @if($playerShares['count'] > \App\Services\PlayerShares::COUNT)({{ \App\Services\PlayerShares::COUNT }} when it matured, and new ones it sold since) @endif
                    in all.</p>
                {{-- New shares to fund growth (#780): 10 at a time, at cash-out, the credits to the company --}}
                <div id="share-offering" class="mb-3">
                    @if($playerShares['offering'])
                        @php($off = $playerShares['offering'])
                        <p><b>New shares on offer:</b> {{ $off['left'] }} of {{ $off['units'] }} left, at its cash-out value a share
                            (<b>{{ number_format($off['price']) }}</b> now). What they fetch goes to the company, to fund its growth;
                            buying at cash-out leaves every share's cash-out value as it was.@if($off['raised'] > 0) Raised so far:
                            {{ number_format($off['raised']) }} credits.@endif</p>
                        @if($playerShares['held'] !== null)
                            <form method="POST" action="{{ url('companies/' . $user->user_id . '/offering/buy') }}" class="form-inline mb-2">
                                {{ csrf_field() }}
                                <label class="mr-2" for="offer-buy">Buy new shares:</label>
                                <input class="form-control mr-2 mb-2" id="offer-buy" name="units" type="number" min="1" max="{{ $off['left'] }}" value="1" required>
                                <button class="button button-secondary button-sm mb-2" type="submit">Buy</button>
                            </form>
                        @endif
                        @if($playerShares['is_owner'])
                            <form method="POST" action="{{ url('companies/' . $user->user_id . '/offering/withdraw') }}" class="form-inline mb-2">
                                {{ csrf_field() }}
                                <button class="button button-secondary button-sm mb-2" type="submit">Withdraw the rest</button>
                            </form>
                        @endif
                    @elseif($playerShares['is_owner'])
                        @if($playerShares['count'] + \App\Services\PlayerShares::OFFER_UNITS <= \App\Services\PlayerShares::MAX_COUNT)
                            <form method="POST" action="{{ url('companies/' . $user->user_id . '/offering') }}" class="form-inline mb-2">
                                {{ csrf_field() }}
                                <span class="mr-2">Raise credits for a copy: offer {{ \App\Services\PlayerShares::OFFER_UNITS }} new shares at
                                    {{ number_format($playerShares['buys_at']) }} each (about {{ number_format(\App\Services\PlayerShares::OFFER_UNITS * $playerShares['buys_at']) }} credits).
                                    Every holder's part of the company shrinks a little.</span>
                                <button class="button button-secondary button-sm mb-2" type="submit">Offer new shares</button>
                            </form>
                        @else
                            <p><small>It has {{ $playerShares['count'] }} shares, the most a company may have ({{ \App\Services\PlayerShares::MAX_COUNT }}).</small></p>
                        @endif
                    @endif
                </div>
                <div class="row">
                    @foreach(['sell' => 'Offered', 'buy' => 'Wanted'] as $side => $label)
                        <div class="col-md-6"><h3 class="h6">{{ $label }} by players</h3>
                            @if(count($playerShares['book'][$side]))
                                <table class="table table-sm"><tbody>
                                @foreach($playerShares['book'][$side] as $o)
                                    <tr><td>{{ $o['by'] }}@if(!empty($o['script'])) <small class="text-muted" title="Placed by this player's trading script">(script)</small>@endif</td><td class="text-right">{{ number_format($o['units']) }}</td><td class="text-right">at {{ number_format($o['price']) }}@if(!empty($o['peg']))
                                        <br><small class="text-muted">{{ $o['peg'] === 'buys_at' ? 'bank buys' : 'bank sells' }} {{ $o['offset_percent'] >= 0 ? '+' : '−' }}{{ abs($o['offset_percent']) }}%</small>@endif</td></tr>
                                @endforeach
                                </tbody></table>
                            @else <p><small>None</small></p> @endif
                        </div>
                    @endforeach
                </div>
                {{-- Its latest trades between players (#676) --}}
                @php($recentTrades = \App\Services\PlayerShares::trades((int) $user->user_id, 10))
                @if(count($recentTrades))
                    <h3 class="h6">Latest trades</h3>
                    <table class="table table-sm" id="player-share-trades"><tbody>
                        @foreach($recentTrades as $t)
                            <tr><td>{{ $t['when'] }}</td><td class="text-right">{{ number_format($t['units']) }}</td>
                                <td class="text-right">at {{ number_format($t['price']) }}</td><td>{{ $t['seller_name'] }} to {{ $t['buyer_name'] }}</td></tr>
                        @endforeach
                    </tbody></table>
                @endif
                {{-- Who holds its shares (#666): players and the bank --}}
                <table class="table table-sm" id="player-share-holders"><tbody>
                    <tr><th>Holder</th><th class="text-right">Shares</th><th class="text-right">Of the company</th></tr>
                    @foreach(\App\Services\PlayerShares::holders((int) $user->user_id) as $holder)
                        <tr><td>{{ $holder['name'] }}</td><td class="text-right">{{ number_format($holder['units']) }}</td>
                            <td class="text-right">{{ rtrim(rtrim(number_format($holder['percent'], 1), '0'), '.') }}%</td></tr>
                    @endforeach
                </tbody></table>
                @if($playerShares['held'] !== null)
                    <p>You hold {{ number_format($playerShares['held']) }} of its shares.</p>
                    <form method="POST" action="{{ url('companies/' . $user->user_id . '/share-orders') }}" class="form-inline mb-2">
                        {{ csrf_field() }}
                        <label class="sr-only" for="ps-side">Buy or sell</label>
                        <select class="form-control mr-2 mb-2" id="ps-side" name="side"><option value="buy">Buy</option><option value="sell">Sell</option></select>
                        <label class="mr-2" for="ps-units">shares</label>
                        <input class="form-control mr-2 mb-2" id="ps-units" name="units" type="number" min="1" max="{{ $playerShares['count'] }}" value="1" required>
                        <label class="mr-2" for="ps-price">at</label>
                        <input class="form-control mr-2 mb-2" id="ps-price" name="price" type="number" min="{{ $playerShares['low'] }}"
                               max="{{ $playerShares['high'] }}" value="{{ $playerShares['sells_at'] }}">
                        {{-- Or a price that follows the bank's (#766: fixed orders went stale within 10 game days) --}}
                        <label class="mr-2" for="ps-peg">or follow</label>
                        <select class="form-control mr-2 mb-2" id="ps-peg" name="peg"><option value="">a fixed price</option>
                            <option value="buys_at">what the bank buys at</option><option value="sells_at">what the bank sells at</option></select>
                        <label class="sr-only" for="ps-offset">plus or minus, percent</label>
                        <input class="form-control mr-2 mb-2" id="ps-offset" name="offset_percent" type="number" step="0.5"
                               min="-{{ \App\Services\PlayerShares::PEG_MAX_PERCENT }}" max="{{ \App\Services\PlayerShares::PEG_MAX_PERCENT }}" value="0" style="width: 6em">
                        <span class="mr-2 mb-2">%</span>
                        <button class="button button-secondary button-sm mb-2" type="submit">Post the order</button>
                    </form>
                    <p><small class="text-muted">A pegged order's price follows the bank's price, plus or minus the percent you give,
                        within the range players trade in, and is moved every few real minutes as it changes.</small></p>
                    <form method="POST" action="{{ url('companies/' . $user->user_id . '/bank/sell') }}" class="form-inline mb-2">
                        {{ csrf_field() }}
                        <label class="mr-2" for="bank-sell">Sell to the bank:</label>
                        <input class="form-control mr-2 mb-2" id="bank-sell" name="units" type="number" min="1" max="{{ $playerShares['count'] }}" required>
                        <button class="button button-secondary button-sm mb-2" type="submit">Sell</button>
                    </form>
                    @if($playerShares['bank_holds'] > 0)
                        <form method="POST" action="{{ url('companies/' . $user->user_id . '/bank/buy') }}" class="form-inline mb-2">
                            {{ csrf_field() }}
                            <label class="mr-2" for="bank-buy">Buy from the bank:</label>
                            <input class="form-control mr-2 mb-2" id="bank-buy" name="units" type="number" min="1" max="{{ $playerShares['bank_holds'] }}" required>
                            <button class="button button-secondary button-sm mb-2" type="submit">Buy</button>
                        </form>
                    @endif
                @else
                    <p><a href="{{ url('login') }}">Log in</a> to trade its shares.</p>
                @endif
            @endif

            {{-- The new rules (#639): who it deals with --}}
            @if($dealings !== null)
                <h2 id="dealings">Dealt with</h2>
                @if(count($dealings))
                    <p>The companies its drones have traded with or plundered, or that plundered its wrecks (the House aside).
                        <b>Same owner</b> marks a company with the same player behind it: read its code to see whether it
                        favours its owner's other companies.</p>
                    <div class="table-scroll" id="dealings-table"><table class="table table-sm">
                        <thead><tr><th scope="col">Company</th><th scope="col" class="text-right">Trades</th>
                            <th scope="col" class="text-right">Units</th><th scope="col" class="text-right">Credits</th>
                            <th scope="col" class="text-right">Plunders</th><th scope="col"></th></tr></thead>
                        <tbody>
                        @foreach($dealings as $d)
                            <tr><td><a href="{{ url('companies/' . $d['company_id']) }}">{{ $d['name'] }}</a></td>
                                <td class="text-right">{{ number_format($d['trades']) }}</td>
                                <td class="text-right">{{ number_format($d['units']) }}</td>
                                <td class="text-right">{{ number_format($d['credits']) }}</td>
                                <td class="text-right">{{ number_format($d['plunders']) }}</td>
                                <td>@if($d['same_owner'])<span class="badge badge-warning">Same owner</span>@endif</td></tr>
                        @endforeach
                        </tbody>
                    </table></div>
                @else
                    <p>It hasn't dealt with another company yet.</p>
                @endif
            @endif

            {{-- Its drones ranked by what they earn (#774): which code path pays --}}
            @php($ranked = \App\Services\DroneEarnings::ranked((int) $user->user_id))
            @if(count($ranked) > 0)
                <h2 id="drone-earnings">Its drones by what they earn</h2>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead><tr><th>Drone</th><th class="text-right">A game day</th><th class="text-right">Over its life</th>
                            <th class="text-right">Sold (after tax)</th><th class="text-right">Bought</th><th class="text-right">Invested</th><th class="text-right">Game days</th></tr></thead>
                        <tbody>
                        @foreach($ranked as $r)
                            <tr><td><a href="{{ url('spacecraft/' . $r['agent_id']) }}">{{ $r['name'] }}</a>{{ $r['wrecked'] ? ' (wreck)' : '' }}</td>
                                <td class="text-right"><b>{{ $r['per_day'] !== null ? number_format($r['per_day']) : '—' }}</b></td>
                                <td class="text-right">{{ number_format($r['net']) }}</td><td class="text-right">{{ number_format($r['sales']) }}</td>
                                <td class="text-right">{{ number_format($r['bought']) }}</td><td class="text-right">{{ number_format($r['invested']) }}</td><td class="text-right">{{ $r['days'] }}</td></tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <p><small>What each drone earned: its sales after tax less its purchases. Equipment it bought (its kit, modules and hulls) is
                    invested, not spent, as on the charts above, so it isn't counted against it. A maker that buys inputs earns less here than the
                    seller it feeds, so read a drone against the job its code gives it.</small></p>
            @endif

            {{-- Health and running costs (#537, #544), in their own section (#591) --}}
            <h2 id="health">Health</h2>
            @if($ended)
                {{-- An ended company has no health to report (#741: it said "sold nothing for 10 game days or more") --}}
                <p id="company-health">It has ended: its drones are wrecks, and it does nothing more.</p>
            @else
            @if($h['at_risk'] || $h['stalled'] || $h['failing_reason'])
                <div class="health-banner" id="health-banner">
                    @if($h['at_risk'])<strong>At risk:</strong> {{ $h['at_risk_reason'] }}. A warning, not a verdict.<br>@endif
                    @if($h['stalled'])<strong>Stalled:</strong> {{ $h['stalled_reason'] }}.<br>@endif
                    {{-- A loop that keeps failing looks idle (#715): say what fails --}}
                    @if($h['failing_reason'])<strong id="failing">Failing:</strong> {{ $h['failing_reason'] }}. Its drones' pages say why each failed.@endif
                </div>
            @endif
            <p id="company-health">{{ $h['working'] }} working {{ \Illuminate\Support\Str::plural('drone', $h['working']) }}{{ $h['wrecked'] ? ', ' . $h['wrecked'] . ' wrecked' : '' }}.
                Fuel aboard: {{ number_format($h['fuel']) }} (the least, {{ number_format($h['lowest']) }}); {{ number_format($h['used']) }} used over the last game day.
                {{ $h['days'] >= 0 ? 'A drone runs dry in about ' . $h['days'] . ' game ' . \Illuminate\Support\Str::plural('day', $h['days']) . ' at that rate.' : "No drone's fuel is falling." }}
                @php($lookback = \App\Services\CompanyHealth::IDLE_LOOKBACK)
                {{ $h['last_sale'] === null ? 'It has sold nothing yet.' : ($h['last_sale'] >= $lookback * 24 ? 'It has sold nothing for ' . $lookback . ' game days or more.'
                    : 'Its last sale: ' . $h['last_sale'] . ' game ' . \Illuminate\Support\Str::plural('hour', $h['last_sale']) . ' ago.') }}</p>
            @if($h['stalled'] || collect($h['drones'])->contains(fn ($d) => $d['idle'] >= 24 || $d['failing']))
                <ul id="idle-drones">
                    @foreach($h['drones'] as $d)
                        @if($d['idle'] >= 24 || $d['failing'])
                            @php($says = $d['status'] ? ', says "' . $d['status'] . '"' : '')
                            @php($holds = count($d['holds']) ? ', holds ' . collect($d['holds'])->map(fn ($x) => number_format($x['units']) . ' ' . $x['resource'])->implode(', ') : '')
                            @php($state = implode(', ', array_filter([$d['idle'] >= 24 ? 'idle ' . $d['idle'] . ' game hours' : null,
                                $d['failing'] ? 'failing: ' . $d['failing'] : null])))
                            <li><a href="{{ url('/spacecraft/' . $d['drone']) }}">{{ $d['name'] }}</a>: {{ $state }}{{ $says }}{{ $holds }}</li>
                        @endif
                    @endforeach
                </ul>
            @endif
            @endif
            @php($pt = $shares['compute'])
            <p id="processor-tax">Processor tax: {{ number_format($pt['tax_day']) }} credits over the last game day for
                {{ number_format($pt['instructions_day']) }} instructions ({{ $pt['per_million'] }} credits a million{{ ($pt['congestion'] ?? 1) > 1 ? ' now: ' . $pt['congestion'] . ' times the usual ' . $pt['base_per_million'] . ' while the clock runs slowed' : '' }}).
                @if($pt['debt'] > 0)
                    <strong>It owes {{ number_format($pt['debt']) }}</strong>: a drone of its whose turn runs out thinks again only a game hour later, until it has paid.
                @endif
                @if($pt['broke_days'] !== null)
                    <strong>Broke</strong> for {{ $pt['broke_days'] }} game days{{ $pt['dissolves_in'] !== null ? ': it dissolves in ' . $pt['dissolves_in'] . ' game days unless it earns credits, and its drones become wrecks' : '' }}.
                @endif
            </p>

            {{-- Its performance by game day (#600, #699): what investors judge it by --}}
            <h2 id="performance">Performance</h2>
            @include('partials.company-performance', ['perf' => $perf, 'companyId' => (int) $user->user_id])

            {{-- Its working drones, its wrecks folded away below (#726: 106
                 "spacecraft" for a company with 8 working drones) --}}
            @php($wrecks = $spacecraft->whereNotNull('wrecked_at'))
            <div class="row row-30">
                <div class="col">
                    <h2 class="" id="drones">Drones</h2>
                </div>
            </div>

            <div class="table-custom-responsive">
                <table class="table-ticket">
                    @if($spacecraft->whereNull('wrecked_at')->isEmpty())
                        <tr>
                            <td colspan="4">No working drones.</td>
                        </tr>
                    @endif
                    @foreach($spacecraft->whereNull('wrecked_at') as $agent)
                        <tr>
                            <td>
                                <a href="{{url('/spacecraft/'.$agent->agent_id)}}">{{$agent->name}}</a>
                            </td>
                            <td>
                            <span class="meta-label">hull</span> {{$agent->hull?$agent->hull->name:'no hull'}}
                            </td>
                            <td>
                                {{-- In flight: where it is going, not where it left (#689) --}}
                                @php($flight = \App\Services\DronePage::flight($agent))
                                <span class="meta-label">location</span> @if($flight)flying to <a
                                        href="{{url('locations/'.$flight['to_id'])}}">{{ $flight['to'] }}</a>@else<a
                                        href="{{url('locations/'.$agent->location->object_id)}}">{{$agent->location->object_name}}</a>@endif
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
            @if($wrecks->isNotEmpty())
                <details id="wrecks" style="margin-top: 1em">
                    <summary>{{ number_format($wrecks->count()) }} {{ \Illuminate\Support\Str::plural('wreck', $wrecks->count()) }}</summary>
                    <div class="table-custom-responsive">
                        <table class="table-ticket">
                            @foreach($wrecks->sortByDesc('wrecked_at') as $agent)
                                <tr>
                                    <td><a href="{{url('/spacecraft/'.$agent->agent_id)}}">{{$agent->name}}</a></td>
                                    <td><span class="meta-label">hull</span> {{$agent->hull?$agent->hull->name:'no hull'}}</td>
                                    <td><span class="meta-label">location</span> @if($agent->location)<a
                                            href="{{url('locations/'.$agent->location->object_id)}}">{{$agent->location->object_name}}</a>@endif</td>
                                    <td><span class="meta-label">wrecked</span> {{ lcfirst(\App\Services\DronePage::WRECK_CAUSES[$agent->wreck_cause] ?? 'It became a wreck') }}</td>
                                </tr>
                            @endforeach
                        </table>
                    </div>
                </details>
            @endif

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
                    {!! \App\Services\LuaHighlight::block($current['script'], ['id' => 'company-code', 'style' => 'white-space: pre-wrap']) !!}
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
                    <div class="table-custom-responsive table-scroll">
                        <table class="table-ticket" id="code-revisions">
                            <tr><th>Revision</th><th>When</th><th class="num">Fee</th><th class="num">Drones</th><th>Code</th></tr>
                            @foreach(array_reverse($code['revisions']) as $r)
                                <tr><td>{{ $r['label'] }}{{ $r['number'] === $current['number'] ? ' (running)' : '' }}</td><td>{{ $r['when'] }}</td>
                                    <td class="num">{{ $r['fee'] === null ? '' : number_format($r['fee']) . ' cr (' . $r['percent'] . '%)' }}</td>
                                    <td class="num">{{ $r['drones'] ?? '' }}</td>
                                    <td style="white-space: normal"><details><summary>Show</summary>
                                        {!! \App\Services\LuaHighlight::block($r['script'], ['style' => 'white-space: pre-wrap; padding: .5em']) !!}
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

            @php($newRules = (int) ($user->seedling ?? 0) === 1 || ($user->owner_id ?? null) !== null || ($user->matured_t ?? null) !== null)
            @if($newRules)
                {{-- The new rules (#666): reprogramming a seedling, or a matured company's locked code --}}
                <h3 class="mt-4" id="pushes">New code</h3>
                @if($ended)
                    <p style="white-space: normal">It has ended: its code can't change.</p>
                @elseif(!empty($pushes['seedling']))
                    <p style="white-space: normal">Its owner reprograms this seedling from their
                        <a href="{{ url('account') }}#seedlings">account page</a> (or the API). Pushed {{ $pushes['count'] }}
                        {{ $pushes['count'] === 1 ? 'time' : 'times' }}; the next push adds {{ number_format($pushes['next_fee']) }}
                        credits to its debt{{ $pushes['next_fee'] === 0 ? ' (the first is free)' : '' }}.</p>
                @else
                    <p style="white-space: normal">This company has matured: its code is locked for good.</p>
                @endif
                @include('partials.push-effects')
            @else
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
            @include('partials.push-effects')

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
                    'chartPlaceMenu' => false, 'places' => [(int) $user->user_id], 'today' => $today, 'chartMarks' => $perf['marks'],
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
            @endif

        </div>


    </section>
@endsection
