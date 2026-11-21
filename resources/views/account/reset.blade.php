@extends('layouts.app')
@php $title = 'Choose a new password' ; @endphp
@section('pageTitle', $title)
@section('content')
    <x-page-header :title="$title" />
    <section class="section section-xs section-first text-md-left">
        <div class="container">
            @include('_flash_message')
            <p>For the account <b>{{ $name }}</b>. Setting it logs the account out everywhere else and cancels its API token.</p>
            @include('account._errors')
            <form method="POST" action="{{ $action }}" class="mt-3" style="max-width: 28rem">
                {{ csrf_field() }}
                <input type="hidden" name="username" value="{{ $name }}" autocomplete="username">
                <div class="form-group">
                    <label for="password">New password</label>
                    <input class="form-control" id="password" name="password" type="password"
                           minlength="{{ config('forge.player_password_min') }}" maxlength="200" autocomplete="new-password" required>
                </div>
                <div class="form-group">
                    <label for="password_confirmation">The password again</label>
                    <input class="form-control" id="password_confirmation" name="password_confirmation" type="password"
                           maxlength="200" autocomplete="new-password" required>
                </div>
                <button class="button button-primary" type="submit">Set my password</button>
            </form>
        </div>
    </section>
@endsection
