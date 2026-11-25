@extends('layouts.app')
@php $title = 'Make an account' ; @endphp
@section('pageTitle', $title)
@section('content')
    <x-page-header :title="$title" />
    <section class="section section-xs section-first text-md-left">
        <div class="container">
            @include('_flash_message')
            @php($draft = \App\Services\GuestDrive::draft())
            @if(config('forge.new_rules'))@include('partials.ladder', ['here' => 'account'])@endif
            @if($draft)
                {{-- The drone a guest test drove (#1042): signing up keeps it --}}
                <div class="alert alert-info" id="keep-drone" style="white-space: normal"><b>Keep your drone.</b> A free account saves the
                    {{ ucfirst($draft['starter']) }} you test drove, and the next page founds it as a startup: a live company of your own.</div>
            @endif
            <p>An account is a name, a password and an e-mail address: one account per address. We send a link to the
                address; opening it verifies your account@if(config('forge.player_share_credits') > 0) and brings it
                {{ number_format(config('forge.player_share_credits')) }} credits for buying shares in companies@endif. Your
                account gathers everything your companies and shares earn you.</p>
            <p><b>Don't use your real name.</b> Your account's name is public: it shows on the leaderboards. Your e-mail
                address is private: only you see it. We use it to verify your account and to send the notices you choose on your
                account page (on at first; turn any of them off there).</p>
            @include('account._errors')
            <form method="POST" action="{{ url('signup') }}" class="mt-3" style="max-width: 28rem">
                {{ csrf_field() }}
                <div class="form-group">
                    <label for="name">Name</label>
                    <input class="form-control" id="name" name="name" type="text" maxlength="32" value="{{ old('name') }}"
                           autocomplete="username" required>
                </div>
                <div class="form-group">
                    <label for="email">E-mail address</label>
                    <input class="form-control" id="email" name="email" type="email" maxlength="255" value="{{ old('email') }}"
                           autocomplete="email" required>
                </div>
                <div class="form-group">
                    <label for="password">Password <small class="text-muted">(at least {{ config('forge.player_password_min') }} characters)</small></label>
                    {{-- Asked once, with Show to check it (#1041) --}}
                    <div style="display: flex; gap: 8px">
                        <input class="form-control" id="password" name="password" type="password"
                               minlength="{{ config('forge.player_password_min') }}" maxlength="200" autocomplete="new-password" required>
                        <button class="button button-secondary button-sm" type="button" id="show-password" aria-controls="password" aria-pressed="false"
                                onclick="var p = document.getElementById('password'); var shown = p.type === 'password'; p.type = shown ? 'text' : 'password'; this.setAttribute('aria-pressed', shown); this.textContent = shown ? 'Hide' : 'Show';">Show</button>
                    </div>
                </div>
                <button class="button button-primary" type="submit">{{ $draft ? 'Keep my drone' : 'Make my account' }}</button>
            </form>
            <p class="mt-3">Have one? <a href="{{ url('login') }}">Log in</a>.</p>
        </div>
    </section>
@endsection
