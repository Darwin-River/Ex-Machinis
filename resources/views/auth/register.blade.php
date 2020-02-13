@extends('layouts.app')
@php $title = 'Join the Game' ; @endphp
@section('pageTitle', $title)
@section('content')
    <section class="section section-xs section-first bg-default text-md-left">
        <div class="container" id="container">
            <h1>{{$title}}</h1>
            <p class="text-left">Please provide an e-mail address and your space company name to start.</p>
            <div class="row justify-content-center justify-content-md-start mt-0">
                <div class="col-sm-9 col-md-7 col-lg-5 col-xl-4">
                    @if(count($errors))
                        <div class="form-group mt-1">
                            <div class="alert alert-danger">
                                <ul>
                                    @foreach($errors->all() as $error)
                                        <li>{{$error}}</li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    @endif
                    <form class="app"  method="POST"
                          action="{{ url('web-register') }}">
                        {{ csrf_field() }}

                        <div class="form-wrap {{ $errors->has('email') ? ' has-error' : '' }}">
                            <label class="form-label-outside" for="email">E-mail</label>
                            <input class="form-input form-control-has-validation form-control-last-child"
                                   id="email" type="email" name="email" data-constraints="@Email @Required"
                                   value="{{ old('email') }}" required><span
                                    class="form-validation"></span>
                            @if ($errors->has('email'))
                                <span class="help-block">
                                        <strong>{{ $errors->first('email') }}</strong>
                                    </span>
                            @endif
                        </div>
                        <div class="form-wrap {{ $errors->has('name') ? ' has-error' : '' }}">
                            <label class="form-label-outside" for="name">Name for your Space Company</label>
                            <input class="form-input form-control-has-validation form-control-last-child"
                                   id="name" type="text" name="name"
                                   data-constraints="@Required" required><span class="form-validation"></span>
                            @if ($errors->has('name'))
                                <span class="help-block">
                                        <strong>{{ $errors->first('name') }}</strong>
                                    </span>
                            @endif
                        </div>
                        <div class="group-sm group-justify justify-content-sm-between">
                            <button class="button button-primary" type="submit">Sign Up</button>

                        </div>
                    </form>
                </div>
            </div>

        </div>
    </section>


@endsection
