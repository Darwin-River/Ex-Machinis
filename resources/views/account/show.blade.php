@extends('layouts.app')
@php $title = 'Your account' ; @endphp
@section('pageTitle', $title)
@section('content')
    <x-page-header :title="$title" />
    <section class="section section-xs section-first text-md-left">
        <div class="container">
            @include('_flash_message')
            @include('account._errors')
            <h2 class="h4">{{ $account['name'] }}</h2>
            @if(!$account['verified'])
                <div class="alert alert-warning" style="white-space: normal">
                    Verify your e-mail address ({{ $account['email'] }}): open the link we sent to it. Verifying brings
                    your account its {{ number_format(config('forge.player_share_credits')) }} credits for buying shares.
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
            <p>Your credits are what your shares and companies have earned you. The credits for buying shares came with
                your account: they can only buy shares.</p>

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
                <p>None yet: mature a seedling, or buy shares on a matured company's page.</p>
            @endif
            @if($portfolio['dividends_30_days'] > 0)
                <p>Your shares have paid you {{ number_format($portfolio['dividends_30_days']) }} credits in dividends over the last 30 game days.</p>
            @endif
            @if(count($portfolio['orders']))
                <p>Your standing orders:</p>
                <ul>
                    @foreach($portfolio['orders'] as $o)
                        <li>{{ $o['side'] === 'buy' ? 'Buy' : 'Sell' }} {{ number_format($o['units']) }} of
                            <a href="{{ url('companies/' . $o['company_id']) }}#player-shares">{{ $o['name'] }}</a> at {{ number_format($o['price']) }}
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

            <h3 class="h5 mt-4" id="seedlings">Your seedlings</h3>
            <p>Every company starts as a seedling: <a href="{{ url('release') }}">release a drone</a> and the bank lends it
                its drone and starting credits, plus a fee ({{ number_format($mine['grant']) }} credits in all). A seedling
                trades only with the House, and you can change its code whenever you like (each change adds
                {{ number_format(config('forge.seedling_push_fee')) }} to its debt). Once it holds enough to pay its debt,
                mature it: it repays, its code is locked for good, and its 1,000 shares are yours. Close one at any time:
                it leaves the game and its debt is cancelled. You have {{ $mine['slots']['used'] }} of
                {{ $mine['slots']['max'] }} seedling slots in use.</p>
            @if(count($mine['seedlings']))
                <table class="table table-sm">
                    <thead><tr><th scope="col">Seedling</th><th scope="col" class="text-right">Credits</th>
                        <th scope="col" class="text-right">Owes</th><th scope="col"></th></tr></thead>
                    <tbody>
                    @foreach($mine['seedlings'] as $seed)
                        <tr>
                            <td><a href="{{ url('companies/' . $seed['company_id']) }}">{{ $seed['name'] }}</a></td>
                            <td class="text-right">{{ number_format($seed['credits']) }}</td>
                            <td class="text-right">{{ number_format($seed['debt']) }}</td>
                            <td class="text-right">
                                @if($seed['can_mature'] && $account['verified'])
                                    <form method="POST" action="{{ url('seedlings/' . $seed['company_id'] . '/mature') }}" class="d-inline"
                                          onsubmit="return confirm('Mature this seedling? Its code is locked for good.')">
                                        {{ csrf_field() }}<button class="button button-primary button-sm" type="submit">Mature</button>
                                    </form>
                                @endif
                                <form method="POST" action="{{ url('seedlings/' . $seed['company_id'] . '/close') }}" class="d-inline"
                                      onsubmit="return confirm('Close this seedling? It leaves the game with all it holds.')">
                                    {{ csrf_field() }}<button class="button button-secondary button-sm" type="submit">Close</button>
                                </form>
                            </td>
                        </tr>
                        <tr><td colspan="4">
                            <details><summary>Change its code</summary>
                                <form method="POST" action="{{ url('seedlings/' . $seed['company_id'] . '/push') }}" class="mt-2">
                                    {{ csrf_field() }}
                                    <textarea class="form-control" name="script" rows="8" maxlength="{{ config('forge.max_script_length') }}"
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
            <form method="POST" action="{{ url('seedlings/claim') }}" class="form-inline mb-3">
                {{ csrf_field() }}
                <label class="mr-2" for="claim">Released as a guest? Claim it with its code:</label>
                <input class="form-control mr-2 mb-2" id="claim" name="code" type="text" maxlength="64" required>
                <button class="button button-secondary button-sm mb-2" type="submit">Claim</button>
            </form>

            <h3 class="h5 mt-4">The API</h3>
            <p>Programs act for your account through the <a href="{{ url('getting-started') }}">API</a> with a token, sent
                as <code>Authorization: Bearer</code> and the token. A new token replaces the old one.</p>
            @if($token = session('token'))
                <div class="alert alert-info" style="white-space: normal">Your new token (copy it now: it won't be shown
                    again): <code>{{ $token }}</code></div>
            @endif
            <form method="POST" action="{{ url('account/token') }}">
                {{ csrf_field() }}
                <button class="button button-secondary button-sm" type="submit">Make a new API token</button>
            </form>

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

            <form method="POST" action="{{ url('logout') }}" class="mt-4">
                {{ csrf_field() }}
                <button class="button button-secondary button-sm" type="submit">Log out</button>
            </form>
        </div>
    </section>
@endsection
