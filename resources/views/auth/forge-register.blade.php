@extends('layouts.app')
@php $title = 'Open an Account' ; @endphp
@section('pageTitle', $title)
@section('content')
    <section class="section section-xs section-first bg-default text-md-left">
        <div class="container" id="container">
            <h1>{{$title}}</h1>
            <p>With an account you can release scripts: each one starts a new company with its own drone.</p>
            @if(config('forge.invite_only'))
                <p>Accounts are by invitation for now: you need an invitation code.</p>
            @endif

            <div class="row">
                <div class="col-sm-9 col-md-7 col-lg-5">
                    @if(count($errors))
                        <div class="alert alert-danger">
                            <ul class="mb-0">
                                @foreach($errors->all() as $error)
                                    <li>{{$error}}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    <form method="POST" action="{{ route('register') }}">
                        {{ csrf_field() }}
                        @if(config('forge.invite_only'))
                            <div class="form-group">
                                <label for="invite_code">Invitation code</label>
                                <input class="form-control" id="invite_code" name="invite_code" type="text"
                                       value="{{ old('invite_code') }}" required>
                            </div>
                        @endif
                        <div class="form-group">
                            <label for="name">Your name</label>
                            <input class="form-control" id="name" name="name" type="text" value="{{ old('name') }}" required>
                        </div>
                        <div class="form-group">
                            <label for="email">E-mail</label>
                            <input class="form-control" id="email" name="email" type="email" value="{{ old('email') }}" required>
                        </div>
                        <div class="form-group">
                            <label for="password">Password</label>
                            <input class="form-control" id="password" name="password" type="password" required>
                        </div>
                        <div class="form-group">
                            <label for="password_confirmation">Password again</label>
                            <input class="form-control" id="password_confirmation" name="password_confirmation" type="password" required>
                        </div>
                        <button class="button button-primary" type="submit">Open my account</button>
                    </form>
                </div>
            </div>
        </div>
    </section>
@endsection
