@extends('layouts.app')
@php $title = $group ? 'Players: ' . $group->name : 'Players' ; @endphp
@section('pageTitle', $title)
@section('content')
    <x-page-header :title="$title" />
    <section class="section section-xs section-first text-md-left">
        <div class="container">
            @include('_flash_message')
            <p>A player's wealth is their credits, their shares at cash-out value, and what the bank still owes them for
                shares sold to it. The season is a real day: its board ranks what each player has made since today ({{ $boards['day'] }}, UTC)
                began, so anyone can win one, and a new season starts at midnight UTC. Your best season and your other records are on
                your <a href="{{ url('account') }}#records">account page</a>.
                @if($group) This is {{ $group->name }}'s class group: its boards are its own. <a href="{{ url('players') }}?class=">Everyone else's</a>.@endif</p>
            <div class="row">
                @foreach(['wealth' => 'Wealth', 'season' => 'Today\'s season'] as $key => $label)
                    <div class="col-lg-6" id="{{ $key }}">
                        <h2 class="h4">{{ $label }}</h2>
                        <table class="table table-sm">
                            <thead><tr><th scope="col">#</th><th scope="col">Player</th>
                                <th scope="col" class="text-right">{{ $key === 'wealth' ? 'Wealth' : 'Made today' }}</th></tr></thead>
                            <tbody>
                            {{-- Equal figures share a place (#836: 18 players on 5,000 were ranked 4 to 21) --}}
                            @php $place = 0; $prev = null; @endphp
                            @forelse($boards[$key] as $i => $r)
                                @php $v = $key === 'wealth' ? $r['wealth'] : $r['made']; if ($v !== $prev) { $place = $i + 1; $prev = $v; } @endphp
                                <tr @if($me === $r['name']) class="table-active" @endif>
                                    <td>{{ $place }}</td><td class="text-break">{{ $r['name'] }}</td>
                                    <td class="text-right">@if($key === 'wealth'){{ number_format($r['wealth']) }}@if($r['bank_due'] > 0)<br><small>{{ number_format($r['bank_due']) }} of it due from the bank</small>@endif
                                        @else{{ ($r['made'] > 0 ? '+' : '') . number_format($r['made']) }}@endif</td>
                                </tr>
                            @empty
                                <tr><td colspan="3">No players yet.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                @endforeach
            </div>
            <p><a href="{{ url('leaderboard') }}">The company boards</a> compare companies, at the same age too.</p>
        </div>
    </section>
@endsection
