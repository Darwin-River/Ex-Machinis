@extends('layouts.app')
@php $title = 'Class group: ' . $group['name'] ; @endphp
@section('pageTitle', $title)
@section('content')
    <x-page-header :title="$title" />
    <section class="section section-xs section-first text-md-left">
        <div class="container">
            @include('_flash_message')
            @include('account._errors')
            @if($fresh)
                <div class="alert alert-warning" style="white-space: normal">Print or copy this list now: the passwords
                    won't be shown again. You can give any pupil a new password later.</div>
                <table class="table table-sm" style="max-width: 32rem">
                    <thead><tr><th scope="col">Name</th><th scope="col">Password</th></tr></thead>
                    <tbody>
                    @foreach($group['accounts'] as $pupil)
                        <tr><td>{{ $pupil['name'] }}</td><td><code>{{ $pupil['password'] }}</code></td></tr>
                    @endforeach
                    </tbody>
                </table>
                <p><a href="{{ url('account/classes/' . $group['id']) }}">The group's page</a></p>
            @else
                @if($reset = session('reset'))
                    <div class="alert alert-info" style="white-space: normal">{{ $reset['name'] }}'s new password:
                        <code>{{ $reset['password'] }}</code> (it won't be shown again)</div>
                @endif
                <p>{{ count($group['pupils']) }} pupils. Give a pupil a new password if they lose theirs: the old one
                    stops working.</p>
                <table class="table table-sm" style="max-width: 32rem">
                    <tbody>
                    @foreach($group['pupils'] as $pupil)
                        <tr><td>{{ $pupil }}</td><td class="text-right">
                            <form method="POST" action="{{ url('account/classes/' . $group['id'] . '/reset') }}">
                                {{ csrf_field() }}
                                <input type="hidden" name="pupil" value="{{ $pupil }}">
                                <button class="button button-secondary button-sm" type="submit">New password</button>
                            </form>
                        </td></tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
            <p><a href="{{ url('account') }}">Your account</a></p>
        </div>
    </section>
@endsection
