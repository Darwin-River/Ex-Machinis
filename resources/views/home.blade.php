@extends('layouts.app')
@section('pageTitle', 'Home')
@section('content')
    <!-- Section welcome-->
    <section class="section section-md bg-default context-dark section-height-1 box-transform-wrap bg-image"
             style="background-image: url('/images/bg-index.jpg');">
        <div class="container">
            <div class="row">
                <div class="col offset-md-1">
                    <div class="post-erin">
                        <h5 class="post-erin-subtitle">welcome to</h5>
                        <h1 class="post-erin-title">Ex Machinis — a multiplayer space game<br>
                          <span class="font-weight-light">where you <b>program</b> fleets of <b>self-replicating spacecraft</b></span><br>
                          <span class="font-weight-light">to exploit the riches of our solar system</span>
                        </h1><a class="button button-2 button-lg button-white-2 button-icon button-icon-left"
                                href="getting-started"><span
                                    class="icon mdi mdi-arrow-right"></span><span>Learn more</span></a>
                    </div>
                </div>
            </div>
        </div>
        <div class="box-transform" style="background-image: url('/images/bg-index.jpg');"></div>
        <div class="comets comets-left comets-long"></div>
        <div class="comets comets-center comets-long"></div>
        <div class="comets comets-top comets-long"></div>
    </section>


    <div class="container">
        <div class="row">
            <div class="col-md-8 col-md-offset-2">
                <div class="panel panel-default">
                    <!--<div class="panel-heading">test</div>-->

                    <div class="panel-body">
                        @if (session('status'))
                            <div class="alert alert-success">
                                {{ session('status') }}
                            </div>
                        @endif


                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
