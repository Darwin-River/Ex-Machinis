@extends('layouts.app')
@php $title = $user->name ; @endphp
@section('pageTitle', $title)
@section('content')
    <section class="breadcrumbs-modern box-transform-wrap bg-image context-dark"
             style="background-image: url('/images/bg-breadcrumbs.jpg');">
        <div class="container">
            <div class="breadcrumbs-modern-body">
                <h5 class="breadcrumbs-modern-subtitle"></h5>
                <h1 class="breadcrumbs-modern-title">{{$title}}</h1>
            </div>
            <ul class="breadcrumbs-path breadcrumbs-modern-path">
                <li><a href="/">Home</a></li>
                <li><a href="/companies">Companies</a></li>
                <li class="active">{{$title}}</li>
            </ul>
        </div>
        <div class="box-transform" style="background-image: url('/images/bg-breadcrumbs.jpg');"></div>
        <div class="comets comets-left"></div>
        <div class="comets comets-center"></div>
        <div class="comets comets-top"></div>
    </section>
    <section class="section section-xs section-first bg-default text-md-left">
        <div class="container grid-demonstration" id="container">
            <div class="description-fields">
                <div class="row row-xxl row-30 row-md-60 ">
                    <div class="col-12 col-lg-12 col mb-0">
                        <b>Id</b>: <span style="font-weight: normal">{{$user->user_id}}</span><br/>
                    </div>
                    <div class="col-12 col-lg-12 col mb-0">
                        <b>Founded on</b>: <span
                                style="font-weight: normal">{{ \Carbon\Carbon::parse($user->created_at)->format('l, F j, Y') }}</span><br/>

                    </div>
                    <div class="col-12 col-lg-12 col ">

                        <b>Credits: </b><span style="font-weight: normal">{{$user->credits}}</span><br/>

                    </div>
                </div>
            </div>
            <div class="row row-xxl row-30 row-md-60">
                <div class="col">
                    <h2 class="wow fadeInLeft">Spacecraft</h2>
                </div>
            </div>

            <div class="table-custom-responsive">
                <table class="table-ticket">
                    @if(sizeof($spacecraft) == 0)
                        <tr>
                            <td colspan="4"> No spacecraft present.</td>
                        </tr>
                    @endif
                    @foreach($spacecraft as $agent)
                        <tr>
                            <td>
                                <a href="{{url('/spacecraft/'.$agent->agent_id)}}">{{$agent->name}}</a>
                            </td>
                            <td>
                            <span
                                    class="rd-nav-icon mdi mdi-border-outside"
                                    title="hull"></span> {{$agent->hull?$agent->hull->name:'no hull'}}
                            </td>
                            <td>
                                <span class="rd-nav-icon mdi mdi-map-marker" title="location"></span> <a
                                        href="{{url('objects/'.$agent->location->object_id)}}">{{$agent->location->object_name}}</a>
                            </td>
                            <td><span class="rd-nav-icon mdi mdi-package-down" title="cargo"></span>
                                @if(!$agent->hull || !$agent->hull->cargo_capacity)
                                    <span class="text-nowrap">no cargo capacity</span>
                                @else
                                    <span class="text-nowrap">{{( $agent->getCurrentCargoWeight())}}
                                        / {{$agent->hull->cargo_capacity}} Kg</span>
                                @endif

                            </td>
                        </tr>
                    @endforeach
                </table>
            </div>
        </div>


    </section>
@endsection
