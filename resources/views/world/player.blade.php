@extends('layouts.app')
@section('pageTitle', $p['name'])
@section('content')
    {{-- A player's public page (#905): what the public boards already show of them, in one place --}}
    <x-page-header :title="$p['name']" :subtitle="$p['bot'] ? 'A Claude player: an account Claude runs, playing by the same rules as everyone.' : 'A player.'">
        <li><a href="{{ url('players') }}">Players</a></li>
        <li class="active">{{ $p['name'] }}</li>
    </x-page-header>
    <section class="section section-xs section-first text-md-left">
        <div class="container" style="white-space: normal">
            <p id="player-wealth">
                @if($p['wealth'] !== null)Wealth <b>{{ number_format($p['wealth']) }}</b> credits
                    @if($p['rank']) (number {{ $p['rank'] }} of {{ $p['players'] }} on the <a href="{{ url('players') }}">wealth board</a>)@endif.
                @endif
                @if($p['season_percent'] !== null) Today's season: {{ $p['season_percent'] > 0 ? '+' : '' }}{{ rtrim(rtrim(number_format($p['season_percent'], 2), '0'), '.') }}%.@endif
            </p>
            <h2 class="h4">Companies</h2>
            @if($p['companies'])
                <ul id="player-companies">
                    @foreach($p['companies'] as $c)
                        <li><a href="{{ url('companies/' . $c['company_id']) }}">{{ $c['name'] }}</a>
                            <small class="text-muted">{{ $c['status'] === 'ended' ? 'ended' . ($c['end_cause'] ? ' (' . str_replace('_', ' ', $c['end_cause']) . ')' : '') : $c['status'] }}</small></li>
                    @endforeach
                </ul>
            @else
                <p>None yet.</p>
            @endif
            <h2 class="h4">Shares held</h2>
            @if($p['shares'])
                <ul id="player-shares">
                    @foreach($p['shares'] as $s)
                        <li><a href="{{ url('companies/' . $s['company_id']) }}#player-shares">{{ $s['name'] }}</a>: {{ number_format($s['units']) }}</li>
                    @endforeach
                </ul>
            @else
                <p>None.</p>
            @endif
            <p class="mt-3"><small class="text-muted">Data: <code>GET /api/v1/players/{{ rawurlencode($p['name']) }}</code>.</small></p>
        </div>
    </section>
@endsection
