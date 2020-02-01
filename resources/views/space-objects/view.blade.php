@extends('layouts.app')
@php $title = $object->object_name ; @endphp
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
                <li><a href="/objects">Astronomical Objects</a></li>
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
            <div class="row row-xxl row-30 row-md-60 ">
                <div class="col-12 col-lg-6 col mb-0 mb-lg-4">
                    <b>Type: </b>{{$object->object_type}}<br/>
                    <b>Epoch: </b>{{$object->epoch?date('m/d/Y', strtotime($object->epoch)):'-'}}<br/>
                    <b>Semimajor Axis: </b> {{$object->semimajor_axis?$object->semimajor_axis:'-'}}<br/>
                    <b>Eccentricity: </b> {{$object->eccentricity?$object->eccentricity:'-'}}<br/>
                    <b>Periapsis Argument: </b>{{$object->periapsis_argument?$object->periapsis_argument:'-'}}<br/>

                </div>
                <div class="col-12 col-lg-6 mb-0 mb-lg-4">
                    <b>Central
                        Body: </b>{!! $object->centralBody?"<a href='".url('/objects/'.$object->central_body_object_id)."'>".$object->centralBody->object_name."</a>":'-'!!}
                    <br/>
                    <b>Mean Anomally: </b> {{$object->mean_anomaly?$object->mean_anomaly:'-'}}<br/>
                    <b>Inclination: </b>{{$object->inclination?$object->inclination:'-'}}<br/>
                    <b>Ascending Node: </b> {{$object->ascending_node_longitude?$object->ascending_node_longitude:'-'}}
                    <br/>
                    <b>Mean Angular Motion: </b>{{$object->mean_angular_motion?$object->mean_angular_motion:'-'}}<br/>
                </div>
                <div class="col-12 col-lg-4 ">


                </div>
            </div>
            <div class="row row-xxl row-30 row-md-60">
                <div class="col">
                    <h2 class="wow fadeInLeft">Extractable Resources's Abundance</h2>
                </div>
            </div>
            <div class="row row-xxl row-30 row-md-60 mt-3">
                @if(sizeof($object->abundancies)==0)
                    <div class="col">
                        <p class="wow fadeInLeft">
                            No resources found.
                        </p>
                    </div>
                @else
                    @php $abundancies = $object->abundancies; @endphp
                    @foreach($abundancies as $abundancy)
                        <div class="col-4">
                            <div class="counter-modern">
                                <h2 class="counter-modern-number"><span
                                            class="counter">{{$abundancy->multiplier}}</span>
                                </h2>
                                <div class="counter-modern-title"><a
                                            href="{{url('/resources/'.$abundancy->resourceObject->id)}}">{{$abundancy->resourceObject->name}}</a>
                                    <br/><span title="id" class="rd-nav-icon mdi mdi-pound"></span> <a
                                            href="{{url('/resources/'.$abundancy->resourceObject->id)}}">{{$abundancy->resource}}</a>
                                </div>
                            </div>
                        </div>
                    @endforeach

                @endif
            </div>
            <div class="row row-xxl row-30 row-md-60">
                <div class="col">
                    <h2 class="wow fadeInLeft">Local Spacecraft</h2>
                </div>
            </div>
            <div class="table-custom-responsive">
                <table class="table-ticket">
                    @if(sizeof($localSpacecraft) == 0)
                        <tr>
                            <td colspan="4"> No spacecraft present.</td>
                        </tr>
                    @endif
                    @foreach($localSpacecraft as $agent)
                        <tr>
                            <td>
                                <span class="rd-nav-icon mdi mdi-pound" title="id"></span> <a
                                        href="{{url('/spacecraft/'.$agent->agent_id)}}">{{$agent->agent_id}}</a>
                            </td>
                            <td>
                                <a
                                        href="{{url('/spacecraft/'.$agent->agent_id)}}">{{$agent->name}}</a>
                            </td>
                            <td>
                            <span
                                    class="rd-nav-icon mdi mdi-border-outside"
                                    title="hull"></span> {{$agent->hull?$agent->hull->name:'-'}}
                            </td>
                            <td>
                            <span
                                    class="rd-nav-icon mdi mdi-office-building"
                                    title="company"></span> <a
                                        href="{{url('/companies/'.$agent->user_id)}}">{{$agent->user->name}}</a>
                            </td>
                        </tr>
                    @endforeach
                </table>
            </div>
            <div class="row row-xxl row-30 row-md-60">
                <div class="col">
                    <h2 class="wow fadeInLeft">Orbiting Bodies</h2>

                </div>
            </div>
            <div class="row">
                <div class="col">
                    <div class="table-responsive">
                        <table class="table-custom table-custom-bordered">
                            <tr>
                                <th>
                                    Id
                                </th>
                                <th>
                                    Name
                                </th>
                                <th>
                                    Type
                                </th>
                                <th>
                                    Local Spacecraft
                                </th>
                            </tr>
                            @if(sizeof($orbitingBodies) == 0)
                                <tr>
                                    <td colspan="4">
                                        No bodies orbiting.
                                    </td>
                                </tr>
                            @endif
                            @foreach($orbitingBodies as $body)
                                <tr>
                                    <td>
                                        <a href="{{url('objects/'.$body->object_id)}}">{{$body->object_id}}</a>
                                    </td>
                                    <td>
                                        <a href="{{url('objects/'.$body->object_id)}}">{{$body->object_name}}</a>
                                    </td>
                                    <td>
                                        {{$body->object_type}}
                                    </td>
                                    <td>
                                        {{$body->local_spacecraft}}
                                    </td>
                                </tr>
                            @endforeach
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </section>
@endsection