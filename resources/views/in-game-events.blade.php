@extends('layouts.app')
@php $title = 'Events' ; @endphp
@section('pageTitle', $title)
@section('content')

    <x-page-header :title="$title" />
    <section class="section section-xs section-first text-md-left">

        <div class="container" id="container">
            @if($empty ?? false)
                {{-- #692: an empty page with no word of why --}}
                <p id="events-empty" style="white-space: normal">No events have reached Earth's central database yet.
                    A drone's own actions, trades and messages are on its page (its <b>Activity</b>), the home page's feed
                    has the latest news, and the <a href="{{ url('market') }}">Market</a> shows the trades at each exchange.</p>
            @endif
            {{-- #726: the page lists and searches the latest events only, so it answers on a world of millions --}}
            <p id="events-window" style="white-space: normal">The latest {{ number_format(\App\Http\Controllers\HomeController::EVENTS_WINDOW) }}
                events to reach Earth's central database, newest first: what drones did that others can see. The keyword
                searches these. A drone's own history is on its page (its <b>Activity</b>).</p>
            <in-game-events-table
                    :results-per-page="{{Config::get('constants.options.results_per_page')}}"></in-game-events-table>
        </div>
    </section>
@endsection
