@extends('layouts.app')
@section('pageTitle', 'Page not found')
@section('content')
    {{-- A page that doesn't exist keeps the site's look and points somewhere useful (#868) --}}
    <x-page-header :title="'Page not found'" :subtitle="'There is nothing at this address. It may have moved, or the link may be mistyped.'">
        <li class="active">Not found</li>
    </x-page-header>
    <section class="section section-xs section-first">
        <div class="container">
            <p>Try one of these:</p>
            <ul>
                <li><a href="{{ url('/') }}">The home page</a></li>
                <li><a href="{{ url('getting-started') }}">Your first startup</a>, the five-minute guide</li>
                <li><a href="{{ url('startups/race') }}">The startup race</a></li>
                <li><a href="{{ url('market') }}">The market</a></li>
                <li><a href="{{ url('manual') }}">The players' manual</a></li>
            </ul>
        </div>
    </section>
@endsection
