@extends('layouts.app')
@section('pageTitle', 'Leaderboard')
@section('content')
    {{-- The leaderboard (#324 step 18) --}}
    <section class="section section-xs section-first bg-default text-md-left">
        <div class="container" id="container">
            <h2>Leaderboard</h2>
            <div style="white-space: normal; margin-bottom: 1em">
                Players by net wealth: the credits of their companies that still trade, plus the goods aboard their
                working drones at the House price (what a House station pays, or else what one charges), minus what
                every company started with ({{ number_format($kit) }} for each: its credits, fuel and starter kit).
                So a new release is worth 0, and wealth is what you made. <b>Rising</b> is the gain over the last real
                week.
            </div>
            <div class="row">
                @foreach(['All time' => ['rows' => $allTime, 'key' => 'wealth', 'id' => 'all-time'],
                          'Rising this week' => ['rows' => $rising, 'key' => 'gain', 'id' => 'rising']] as $title => $board)
                    <div class="col-12 col-lg-6">
                        <h3>{{ $title }}</h3>
                        <div class="table-custom-responsive">
                            <table class="table-ticket" id="{{ $board['id'] }}">
                                <tr><th>#</th><th>Player</th><th>Companies</th><th>Drones</th><th>{{ $board['key'] === 'gain' ? 'Gain' : 'Net wealth' }}</th></tr>
                                @forelse($board['rows'] as $i => $row)
                                    <tr>
                                        <td>{{ $i + 1 }}</td><td>{{ $row['name'] }}</td><td>{{ $row['companies'] }}</td>
                                        <td>{{ $row['drones'] }}</td><td>{{ number_format($row[$board['key']]) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5">Nobody yet.</td></tr>
                                @endforelse
                            </table>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>
@endsection
