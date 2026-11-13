@extends('layouts.app')
@section('pageTitle', 'Leaderboard')
@push('styles')
    <style>
        /* Numbers right-aligned, negatives marked (#591) */
        #all-time .num, #rising .num, #at-age .num { text-align: right; font-variant-numeric: tabular-nums; }
        #all-time .negative, #rising .negative, #at-age .negative { color: #ff7b72; }
        #at-age .ended { color: #9aa0ac; }
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
                To compare companies fairly, pick an age: every company is then ranked by its net wealth when it was that old.
            </div>
            {{-- Every company at the same age (#614): its net wealth when it was that old, from its history --}}
            <form method="get" action="{{ url()->current() }}" id="age-form" style="white-space: normal; margin-bottom: 1em">
                Compare every company at age
                <input type="number" name="age" min="0" step="0.1" style="width: 6em" aria-label="Age in game days"
                       value="{{ $age === null ? '' : $age }}" placeholder="days">
                game days <button type="submit">Show</button>
                &middot;
                @foreach([3, 10, 30, 100] as $days)
                    <a href="{{ url()->current() . '?age=' . $days }}"
                       style="{{ (string) $age === (string) $days ? 'font-weight: bold' : '' }}">{{ $days }} days</a>
                @endforeach
            </form>
            @if($atAge !== null)
                <h3>At age {{ rtrim(rtrim(number_format($age, 1), '0'), '.') }} game days</h3>
                <p style="white-space: normal">
                    Each company's net wealth when it was {{ rtrim(rtrim(number_format($age, 1), '0'), '.') }} game days old,
                    from its daily history: so a company that had longer to grow is compared as it was then.
                    {{ number_format($atAge['of']) }} {{ $atAge['of'] === 1 ? 'company has' : 'companies have' }} reached this age{{ $atAge['too_young'] ? '; ' . number_format($atAge['too_young']) . ' still trading haven\'t yet' : '' }}{{ $atAge['ended_before'] ? '; ' . number_format($atAge['ended_before']) . ' ended before it' : '' }}.
                    A figure marked ~ is read between history points more than a day apart.
                    The <a href="{{ url('lineage') }}">lineage map</a> colours every company's code by this rank.
                </p>
                <div class="table-custom-responsive" style="margin-bottom: 2em">
                    <table class="table-ticket" id="at-age">
                        <tr><th>#</th><th>Company</th><th class="num">Net wealth at that age</th><th class="num">Net wealth now</th><th class="num">Age now (game days)</th></tr>
                        @forelse($atAge['rows'] as $i => $row)
                            <tr>
                                <td>{{ $i + 1 }}</td><td><a href="{{ url('/companies/' . $row['company_id']) }}">{{ $row['company'] }}</a></td>
                                <td class="num {{ $row['wealth'] < 0 ? 'negative' : '' }}" @if($row['gap'] > 1.01) title="read between points {{ $row['gap'] }} game days apart" @endif>{{ $row['gap'] > 1.01 ? '~' : '' }}{{ number_format($row['wealth']) }}</td>
                                <td class="num {{ $row['ended'] ? 'ended' : ($row['now'] < 0 ? 'negative' : '') }}">{{ $row['ended'] ? 'ended' : number_format($row['now']) }}</td>
                                <td class="num">{{ number_format($row['age'], 1) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5">No company has reached this age yet.</td></tr>
                        @endforelse
                    </table>
                </div>
            @endif
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
