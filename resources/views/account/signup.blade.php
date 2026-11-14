@extends('layouts.app')
@php $title = 'Make an account' ; @endphp
@section('pageTitle', $title)
@section('content')
    <x-page-header :title="$title" />
    <section class="section section-xs section-first text-md-left">
        <div class="container">
            @include('_flash_message')
            <p>An account is a name and a password: no e-mail. It starts with
                {{ number_format(config('forge.player_share_credits')) }} credits for buying shares in companies, and it
                gathers everything your companies and shares earn you.</p>
            <p><b>Don't use your real name.</b> Your account's name is public: it shows on the leaderboards.</p>
            @include('account._errors')
            <form method="POST" action="{{ url('signup') }}" class="mt-3" style="max-width: 28rem">
                {{ csrf_field() }}
                <div class="form-group">
                    <label for="name">Name</label>
                    <input class="form-control" id="name" name="name" type="text" maxlength="32" value="{{ old('name') }}"
                           autocomplete="username" required>
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
