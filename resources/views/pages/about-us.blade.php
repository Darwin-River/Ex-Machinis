@extends('layouts.app')
@php $title = 'About Us' ; @endphp
@section('pageTitle', $title)
@section('content')
    <!-- Breadcrumbs-->
    <x-page-header>
        <x-slot:title>{{$title}}</x-slot:title>
        <li class="active">{{$title}}</li>
    </x-page-header>
    <section class="section section-xl text-md-left">
        <div class="container">
            <div class="row">
                <div class="col-md-12 col-lg-10 col-xl-9 single-post">
                    <div class="post-big">
                        <h2>What Ex Machinis is</h2>
                        <p>Ex Machinis is a free multiplayer space game you play by writing code. You program drones in Lua, release them into a shared, real-time solar system, and watch them mine, build, trade and replicate on their own. The aim is to build the most valuable company, and along the way to learn to program: every company is an experiment, and every failure shows you what your code didn't foresee.</p>

                        <h2>Free and open source</h2>
                        <p>The game is free to play, with no accounts, no ads and nothing to buy, and its code is open source under the <a href="https://github.com/Darwin-River/Ex-Machinis/blob/main/LICENSE">MIT licence</a>: anyone can read it, change it, host their own world, or <a href="https://github.com/Darwin-River/Ex-Machinis/blob/main/CONTRIBUTING.md">help build it</a>.</p>

                        <h2>Who builds it</h2>
                        <p>Ex Machinis is built by its maintainers and contributors, in the open on <a href="https://github.com/Darwin-River/Ex-Machinis">GitHub</a>. Its core was written by:</p>
                        <ul>
                          <li>Carlos Rodríguez Núñez</li>
                          <li>Fernando Rivas</li>
                          <li>David Rozak</li>
                        </ul>

                        <h2>Our supporters</h2>
                        <p>Thank you to everyone who has supported us with encouragement, feedback, and funding. We wouldn't be here without you! Ex Machinis stays free; you can help <a href="{{ url('support') }}">keep the lights on</a>.</p>
                        <ul>
                          <li>Rodney Bonner</li>
                          <li>Epoch Nine</li>
                          <li>Radical Wave</li>
                          <li>Jason Starttraveler</li>
                          <li>Tovirus</li>
                          <li>Simon Jones</li>
                          <li>Joachim Duquesne</li>
                          <li>Dennis Schlueter</li>
                          <li>Jörg Reisig</li>
                          <li>Wonkyness2</li>
                        </ul>

                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
