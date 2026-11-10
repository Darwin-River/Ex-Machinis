@extends('layouts.app')
@php $title = 'Release a Script' ; @endphp
@section('pageTitle', $title)
@section('content')
    <section class="section section-xs section-first bg-default text-md-left">
        <div class="container" id="container">
            <h1>{{$title}}</h1>
            @include('_flash_message')

            <p>Write your drone's program and release it: anyone can, no account needed. Each release starts a new
                company with one drone in orbit around Earth, the kit you choose and
                {{ number_format(config('forge.starting_credits')) }} credits. The drone loads your script, then runs
                your <code>launched</code> function on its own. You go to its page, where everything it does and says is
                public: keep the link to follow it.</p>
            <p>Write it in Lua and define <code>function launched() ... end</code>. If <code>launched</code> crashes, the drone
                restarts it from the copy you released, waiting longer after each crash; five crashes in a row make
                it a wreck.</p>
            <p><b>Break, learn, repeat.</b> Release early, release often: there is no test world, every release
                is real, and it costs nothing. Release a first version, watch it on its page, and when you see what goes wrong, release a
                better one as a new company. A drone can't be changed once released, and a failed company costs you
                nothing but the lesson. Game time runs fast (a game day in about a minute), so you see how a drone
                does in minutes.</p>

            @if($clock && $clock['full'])
                <div class="alert alert-warning mt-3" style="white-space: normal">
                    The world is full right now: the engines are behind even with the game clock at its slowest. A
                    script you release now waits in the launch queue ({{ $clock['waiting'] }} waiting) and launches as
                    soon as there is room.
                </div>
            @endif

            @if(count($errors))
                <div class="alert alert-danger mt-3">
                    <ul class="mb-0">
                        @foreach($errors->all() as $error)
                            <li>{{$error}}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ url('release') }}" class="mt-3">
                {{ csrf_field() }}
                @if($copiedFrom)
                    {{-- Where this code comes from (#324 step 16) --}}
                    <input type="hidden" name="copied_from" value="{{ $copiedFrom }}">
                    @if($copiedWord)<input type="hidden" name="copied_word" value="{{ $copiedWord }}">@endif
                    <p><small>Copied from <a href="{{ url('spacecraft/' . $copiedFrom) }}">drone {{ $copiedFrom }}</a>{{ $copiedWord ? ' (the function ' . $copiedWord . ')' : '' }}: the new drone's page will say so.</small></p>
                @endif
                <div class="form-group">
                    <label for="company_name">Company name</label>
                    <input class="form-control" id="company_name" name="company_name" type="text" maxlength="45"
                           value="{{ old('company_name') }}" required>
                </div>
                <p><small>Start from a starter script:
                    @foreach($starters as $name => $what)
                        <a href="{{ url('release?starter=' . $name) }}" title="{{ $what }}">{{ ucfirst($name) }}</a>@if(!$loop->last) · @endif
                    @endforeach
                    (each is explained in its comments; change it to make it your own)</small></p>
                <p><small>Your company starts at Earth with one drone, 100 fuel and {{ number_format(config('forge.starting_credits')) }}
                    credits, and no equipment: your script buys what it needs. <code>equip(mining_kit)</code> buys and unpacks
                    a Mining Kit (Engine, Worm and Refinery, to mine and refine), <code>equip(factory_kit)</code> a Factory Kit
                    (Engine, Smelter, Chemical Reactor and Spider, to make things); each costs 2,000 at Earth. Or buy modules
                    one at a time, or none at all. Each starter equips itself.</small></p>
                <div class="form-group">
                    <label for="script">Script (Lua)</label>
                    <textarea class="form-control" id="script" name="script" rows="16" spellcheck="false"
                              style="font-family: monospace"
                              maxlength="{{ config('forge.max_script_length') }}" required>{{ old('script', $starter ?? "function launched()\n  print(\"Hello from orbit\")\nend") }}</textarea>
                </div>
                <button class="button button-primary" type="submit">Release</button>
            </form>

            @if($clock && $clock['waiting'] > 0)
                {{-- The launch queue (#324 step 20) --}}
                <p class="mt-4" id="launch-queue"><small>{{ $clock['waiting'] }} release(s) waiting to launch: they go
                    in order as soon as the world has room.</small></p>
            @endif
        </div>
    </section>
@endsection
