@extends('layouts.app')
@php $title = 'About Us' ; @endphp
@section('pageTitle', $title)
@section('content')
    <!-- Breadcrumbs-->
    <section class="breadcrumbs-modern box-transform-wrap bg-image context-dark"
             style="background-image: url(/images/bg-breadcrumbs.jpg);">
        <div class="container">
            <div class="breadcrumbs-modern-body">
                <h5 class="breadcrumbs-modern-subtitle"></h4><p>
                    <h1 class="breadcrumbs-modern-title">{{$title}}</h1>
            </div>
            <ul class="breadcrumbs-path breadcrumbs-modern-path">
                <li><a href="/">Home</a></li>
                <li class="active">{{$title}}</li>
            </ul>
        </div>
        <div class="box-transform" style="background-image: url(/images/bg-breadcrumbs.jpg);"></div>
        <div class="comets comets-left"></div>
        <div class="comets comets-center"></div>
        <div class="comets comets-top"></div>
    </section>
    <section class="section section-xl  text-md-left">
        <div class="container">
            <div class="row">
                <div class="col-md-12 col-lg-10 col-xl-9 single-post">
                    <div class="post-big">
                        <p>
                            Ex Machinis is an online multiplayer space simulation designed to expose players to
                            programming and robotics. In the game, which takes place against the backdrop of our
                            real-time solar system, players program remotely piloted spacecraft to mine, manufacture,
                            and trade goods and services.
                        </p>
                        <p>
                            The game uses augmented reality to project a parallel universe upon our own. In the game's
                            re-imagined post-war history, world leaders embraced space exploration and colonization as a
                            means of protecting mankind form the global threats that emerged in the wake of the second
                            world war.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
