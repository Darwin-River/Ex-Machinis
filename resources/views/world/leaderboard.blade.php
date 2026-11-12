@extends('layouts.app')
@section('pageTitle', 'Leaderboard')
@push('styles')
    <style>
        /* Numbers right-aligned, negatives marked (#591) */
        #all-time .num, #rising .num { text-align: right; font-variant-numeric: tabular-nums; }
        #all-time .negative, #rising .negative { color: #ff7b72; }
    </style>
@endpush
@section('content')
    {{-- The leaderboard (#324 step 18) --}}
    <x-page-header :title="'Leaderboard'" />
    <section class="section section-xs section-first text-md-left">
        <div class="container" id="container">
            <div style="white-space: normal; margin-bottom: 1em">
                Companies by net wealth: each release starts a company, and the drones it launches belong to it.
                Net wealth is the company's credits, plus the goods aboard its working drones at their market price
                (what they sold for, a unit, over the last three game days), plus the shares it holds in other companies,
                less its own shares that others hold (both at the last three days' average share price), minus what every company starts with
                ({{ number_format($kit) }}: its starting credits and fuel). So a new company is worth 0, and
                wealth is what it made. The drones themselves don't count, so launching a copy lowers net wealth by
                about the price of its hull until the copy earns it back (often 10 to 20 game days).
                <b>Rising</b> is the gain over the last real week. <b>Age</b> is game days since the company was founded.
            </div>
            {{-- Compare young companies only (#548), so they aren't eclipsed by ones that had longer to grow --}}
            <form method="get" action="{{ url()->current() }}" id="age-filter" style="white-space: normal; margin-bottom: 1em">
                Companies up to
                <input type="number" name="max_age" min="0" step="0.1" style="width: 6em"
                       value="{{ $maxAge === null ? '' : $maxAge }}" placeholder="any">
                game days old <button type="submit">Show</button>
                &middot;
                @foreach([null => 'any age', 30 => '30 days', 10 => '10 days', 3 => '3 days'] as $days => $label)
                    <a href="{{ $days === '' ? url()->current() : url()->current() . '?max_age=' . $days }}"
                       style="{{ (string) $maxAge === (string) $days ? 'font-weight: bold' : '' }}">{{ $label }}</a>
                @endforeach
            </form>
            <div class="row">
                @foreach(['All time' => ['rows' => $allTime, 'key' => 'wealth', 'id' => 'all-time'],
                          'Rising this week' => ['rows' => $rising, 'key' => 'gain', 'id' => 'rising']] as $title => $board)
                    <div class="col-12 col-lg-6">
                        <h3>{{ $title }}</h3>
                        <div class="table-custom-responsive">
                            <table class="table-ticket" id="{{ $board['id'] }}">
                                <tr><th>#</th><th>Company</th><th class="num">Age (game days)</th><th class="num">Working drones</th><th class="num">{{ $board['key'] === 'gain' ? 'Gain' : 'Net wealth' }}</th></tr>
                                @forelse($board['rows'] as $i => $row)
                                    <tr>
                                        <td>{{ $i + 1 }}</td><td><a href="{{ url('/companies/' . $row['company_id']) }}">{{ $row['company'] }}</a></td><td class="num">{{ number_format($row['age'], 1) }}</td>
                                        <td class="num">{{ $row['drones'] }}</td><td class="num {{ $row[$board['key']] < 0 ? 'negative' : '' }}">{{ number_format($row[$board['key']]) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5">{{ $maxAge === null ? 'Nobody yet.' : 'No company that young.' }}</td></tr>
                                @endforelse
                            </table>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>
@endsection
