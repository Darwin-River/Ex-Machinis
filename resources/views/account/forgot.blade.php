@extends('layouts.app')
@php $title = 'Forgot your password?' ; @endphp
@section('pageTitle', $title)
@section('content')
    <x-page-header :title="$title" />
    <section class="section section-xs section-first text-md-left">
        <div class="container">
            @include('_flash_message')
            <p>Give your account's name or its e-mail address. If the address is verified, we send it a link to choose a new
                password. The link works once, for an hour.</p>
            @include('account._errors')
            <form method="POST" action="{{ url('password/forgot') }}" class="mt-3" style="max-width: 28rem">
                {{ csrf_field() }}
                <div class="form-group">
                    <label for="account">Name or e-mail address</label>
                    <input class="form-control" id="account" name="account" type="text" maxlength="255" value="{{ old('account') }}"
                           autocomplete="username" required>
                </div>
                <button class="button button-primary" type="submit">Send me a link</button>
            </form>
            <p class="mt-3">In a class? Your teacher can give you a new password. Never verified your address? We can't
                send it a link: make a new account.</p>
            <p><a href="{{ url('login') }}">Back to Log in</a></p>
        </div>
    </section>
@endsection
