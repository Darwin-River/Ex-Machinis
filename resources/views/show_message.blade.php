@extends('layouts.app')
@section('pageTitle', 'Notification')
@section('content')
    <section class="section parallax-container" data-parallax-img="images/bg-parallax-1.jpg">
        <div class="material-parallax parallax"><img src="images/bg-parallax-1.jpg" alt=""
                                                     style="display: block; transform: translate3d(-50%, 2px, 0px);">
        </div>
        <div class="parallax-content section-md context-dark">
            <div class="container">
                <div class="row row-20 align-items-center justify-content-center">
                    @include('_flash_message')
                </div>
            </div>
        </div>
        <div class="comets comets-left"></div>
        <div class="comets comets-center"></div>
        <div class="comets comets-top"></div>
    </section>






@endsection