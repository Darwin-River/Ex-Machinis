@extends('layouts.app')
@php $title = 'Your account' ; @endphp
@section('pageTitle', $title)
@section('content')
    <x-page-header :title="$title" />
    <section class="section section-xs section-first text-md-left">
        <div class="container">
            @include('_flash_message')
            @include('account._errors')
            <h2 class="h4">{{ $account['name'] }}</h2>
            @if(!$account['verified'])
                <div class="alert alert-warning" style="white-space: normal">
                    Verify your e-mail address ({{ $account['email'] }}): open the link we sent to it. Verifying brings
                    your account its {{ number_format(config('forge.player_share_credits')) }} credits for buying shares.
                    <form method="POST" action="{{ url('account/verify') }}" class="mt-2">
                        {{ csrf_field() }}
                        <button class="button button-secondary button-sm" type="submit">Send the link again</button>
                    </form>
                </div>
            @endif
            <table class="table table-sm" style="max-width: 32rem">
                <tr><th scope="row">Credits</th><td class="text-right">{{ number_format($account['credits']) }}</td></tr>
                <tr><th scope="row">Credits for buying shares</th><td class="text-right">{{ number_format($account['share_credits']) }}</td></tr>
                @if($account['email'])
                    <tr><th scope="row">E-mail (only you see it)</th><td class="text-right">{{ $account['email'] }}{{ $account['verified'] ? '' : ' (not verified)' }}</td></tr>
                @endif
                @if($account['class_group'])
                    <tr><th scope="row">Class group</th><td class="text-right">{{ $account['class_group']['name'] }}</td></tr>
                @endif
            </table>
            <p>Your credits are what your shares and companies have earned you. The credits for buying shares came with
                your account: they can only buy shares.</p>

            <h3 class="h5 mt-4">The API</h3>
            <p>Programs act for your account through the <a href="{{ url('getting-started') }}">API</a> with a token, sent
                as <code>Authorization: Bearer</code> and the token. A new token replaces the old one.</p>
            @if($token = session('token'))
                <div class="alert alert-info" style="white-space: normal">Your new token (copy it now: it won't be shown
                    again): <code>{{ $token }}</code></div>
            @endif
            <form method="POST" action="{{ url('account/token') }}">
                {{ csrf_field() }}
                <button class="button button-secondary button-sm" type="submit">Make a new API token</button>
            </form>

            @if(!$account['class_group'] && $account['verified'])
                <h3 class="h5 mt-4">Class groups</h3>
                <p>Teaching a class? Make a class group: accounts for your pupils, named after the group ("Orbit 1",
                    "Orbit 2"...) with passwords you hand out. Your pupils need no e-mail addresses and give no names,
                    and the group trades shares only within itself, with its own leaderboards.</p>
                @if(count($account['teaches']))
                    <ul>
                        @foreach($account['teaches'] as $group)
                            <li><a href="{{ url('account/classes/' . $group['id']) }}">{{ $group['name'] }}</a>: {{ $group['pupils'] }} pupils</li>
                        @endforeach
                    </ul>
                @endif
                <form method="POST" action="{{ url('account/classes') }}" class="form-inline">
                    {{ csrf_field() }}
                    <label class="mr-2" for="group_name">Group name</label>
                    <input class="form-control mr-2 mb-2" id="group_name" name="group_name" type="text" maxlength="25"
                           value="{{ old('group_name') }}" required>
                    <label class="mr-2" for="pupils">Pupils</label>
                    <input class="form-control mr-2 mb-2" id="pupils" name="pupils" type="number" min="1"
                           max="{{ config('forge.class_group_max') }}" value="{{ old('pupils', 30) }}" required>
                    <button class="button button-secondary button-sm mb-2" type="submit">Make the group</button>
                </form>
            @endif

            <form method="POST" action="{{ url('logout') }}" class="mt-4">
                {{ csrf_field() }}
                <button class="button button-secondary button-sm" type="submit">Log out</button>
            </form>
        </div>
    </section>
@endsection
