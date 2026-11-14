@extends('layouts.app')
@php $title = 'Make an account' ; @endphp
@section('pageTitle', $title)
@section('content')
    <x-page-header :title="$title" />
    <section class="section section-xs section-first text-md-left">
        <div class="container">
            @include('_flash_message')
            <p>An account is a name, a password and an e-mail address: one account per address. We send a link to the
                address; opening it verifies your account and brings it
                {{ number_format(config('forge.player_share_credits')) }} credits for buying shares in companies. Your
                account gathers everything your companies and shares earn you.</p>
            <p><b>Don't use your real name.</b> Your account's name is public: it shows on the leaderboards. Your e-mail
                address is private: only you see it, and we use it only to verify your account.</p>
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
                    <label for="password">Password</label>
                    <input class="form-control" id="password" name="password" type="password"
                           minlength="{{ config('forge.player_password_min') }}" maxlength="200" autocomplete="new-password" required>
                </div>
                <div class="form-group">
                    <label for="password_confirmation">The password again</label>
                    <input class="form-control" id="password_confirmation" name="password_confirmation" type="password"
                           maxlength="200" autocomplete="new-password" required>
                </div>
                <button class="button button-primary" type="submit">Make my account</button>
            </form>
            <p class="mt-3">Have one? <a href="{{ url('login') }}">Log in</a>.</p>
        </div>
    </section>
@endsection
