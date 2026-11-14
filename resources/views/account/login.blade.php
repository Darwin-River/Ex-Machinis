@extends('layouts.app')
@php $title = 'Log in' ; @endphp
@section('pageTitle', $title)
@section('content')
    <x-page-header :title="$title" />
    <section class="section section-xs section-first text-md-left">
        <div class="container">
            @include('_flash_message')
            @include('account._errors')
            <form method="POST" action="{{ url('login') }}" class="mt-3" style="max-width: 28rem">
                {{ csrf_field() }}
                <div class="form-group">
                    <label for="name">Name</label>
                    <input class="form-control" id="name" name="name" type="text" maxlength="64" value="{{ old('name') }}"
                           autocomplete="username" required>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input class="form-control" id="password" name="password" type="password" maxlength="200"
                           autocomplete="current-password" required>
                </div>
                <button class="button button-primary" type="submit">Log in</button>
            </form>
            <p class="mt-3">New here? <a href="{{ url('signup') }}">Make an account</a>. In a class? Your teacher has
                your name and password.</p>
        </div>
    </section>
@endsection
