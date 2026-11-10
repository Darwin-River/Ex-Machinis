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
                        <br/><h2>Our Tiny Company</h2>
                        <p><b>Darwin River Software LLC</b> is a small company that was established to support the creation of engaging educational application. The organization is built on an open soruce model in which contributions from supporters and committed developers, are used to guide and support the creation of free educational software. Ex Machinis is our effort to create a multiplayer space game that encourages and inspires the development of fundamental programing skills as tool for success.</p>

                        <br/><h2>Our Tallented Team</h2>
                        <p>Ex Machinis is being developed and maintained by a small team of dedicated and highly motivated developers.  You can follow our efforts on <a href="https://github.com/Darwin-River/Ex-Machinis">GitHub</a>.</p><br/>
                        <ul>
                          <li>Carlos Rodriguez Nuñuez</li>
                          <li>Fernando Rivas</li>
                          <li>David Rozak</li>
                        </ul>

                        <br/><h2>Our Awesome Sponsors</h2>
                        <p>Thank you to everyone who has supported us with encouragement, feedback, and funding. We wouldn't be here without you! Ex Machinis stays free; you can help <a href="{{ url('support') }}">keep the lights on</a>.</p><br/>
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
