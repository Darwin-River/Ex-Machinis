@extends('layouts.app')
@php $title = $group ? 'Players: ' . $group->name : 'Players' ; @endphp
@section('pageTitle', $title)
@section('content')
    <x-page-header :title="$title" />
    <section class="section section-xs section-first text-md-left">
        <div class="container">
            @include('_flash_message')
            <p>A player's wealth is their credits, their shares at cash-out value, and what the bank still owes them for
                shares sold to it. The season board ranks how much it grew this month ({{ $boards['month'] }}), as a share of
                their wealth when the month began (counted as at least {{ number_format(config('forge.season_floor')) }}).
                Only what they made above that counts as growth.
                @if($group) This is {{ $group->name }}'s class group: its boards are its own. <a href="{{ url('players') }}?class=">Everyone else's</a>.@endif</p>
            <div class="row">
                @foreach(['wealth' => 'Wealth', 'season' => 'This month'] as $key => $label)
                    <div class="col-lg-6" id="{{ $key }}">
                        <h2 class="h4">{{ $label }}</h2>
                        <table class="table table-sm">
                            <thead><tr><th scope="col">#</th><th scope="col">Player</th>
                                <th scope="col" class="text-right">{{ $key === 'wealth' ? 'Wealth' : 'Growth' }}</th></tr></thead>
                            <tbody>
                            @forelse($boards[$key] as $i => $r)
                                <tr @if($me === $r['name']) class="table-active" @endif>
                                    <td>{{ $i + 1 }}</td><td>{{ $r['name'] }}</td>
                                    <td class="text-right">@if($key === 'wealth'){{ number_format($r['wealth']) }}@if($r['bank_due'] > 0)<br><small>{{ number_format($r['bank_due']) }} of it due from the bank</small>@endif
                                        @else{{ ($r['growth_percent'] > 0 ? '+' : '') . number_format($r['growth_percent'], 1) }}%@endif</td>
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
