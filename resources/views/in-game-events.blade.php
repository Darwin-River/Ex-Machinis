@extends('layouts.app')
@php $title = 'In-Game Events' ; @endphp
@section('pageTitle', $title)
@section('content')

    <x-page-header :title="$title" />
    <section class="section section-xs section-first text-md-left">

        <div class="container-fluid px-3 px-sm-5" id="container">
            <in-game-events-table
                    :results-per-page="{{Config::get('constants.options.results_per_page')}}"></in-game-events-table>
        </div>
    </section>
@endsection
