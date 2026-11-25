@extends('layouts.app')
@php $title = 'Try a drone' ; @endphp
@section('pageTitle', $title)
@section('content')
    <x-page-header :title="$title" />
    <section class="section section-xs section-first text-md-left">
        <div class="container">
            @include('_flash_message')
            @include('partials.ladder', ['here' => 'try'])
            <p class="lead" style="white-space: normal; max-width: 44em">@if($starter === 'shuttle')This is your first drone: a shuttle that digs ore
                on the Moon, refines it and sells the metals at Earth. Choose a route or change a line,@else Change a setting marked <code>TRY FIRST</code>,@endif
                then <b>Test drive</b> it to see what it would do. No account needed: a test drive runs your code against the live world and
                changes nothing in it.</p>
            {{-- The starters (#719), as on the Found page --}}
            <div class="mb-3" role="group" aria-label="Start from a starter" style="display: flex; flex-wrap: wrap; gap: 8px; align-items: center">
                <span style="color: #9aa3b8">Start from:</span>
                @foreach($starters as $name => $what)
                    <a class="button button-sm {{ $name === $starter ? 'button-primary' : 'button-secondary' }}" href="{{ url('try?starter=' . $name) }}"
                       title="{{ $what }}" @if($name === $starter) aria-current="true" @endif>{{ ucfirst($name) }}</a>
                @endforeach
            </div>
            @include('partials.try-drone', ['script' => $script, 'starter' => $starter, 'replay' => $replay, 'drive' => $drive])
            @include('partials.shuttle-routes', ['routes' => $routes ?? []])
            <p class="mt-3 mb-0" style="color: #9aa3b8; white-space: normal">A test drive can't show other drones reacting, or prices and deposits
                changing over a day: the <a href="{{ url('manual#test-driving-your-code') }}">manual</a> says what it can and can't show.
                The Miner, Refiner and Maker mature as they are. <a href="{{ url('getting-started') }}">Your first startup, step by step</a>.</p>
        </div>
    </section>
@endsection
