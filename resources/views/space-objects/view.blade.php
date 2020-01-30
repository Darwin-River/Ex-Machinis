@extends('layouts.app')
@php $title = $object->object_name ; @endphp
@section('pageTitle', $title)
@section('content')
    <section class="section section-xs section-first bg-default text-md-left">

        <div class="container grid-demonstration" id="container">
            <h1>{{$title}}</h1>
            <div class="row row-xxl row-30 row-md-60 mt-3">
                <div class="col-12 col-lg-4 col mb-0 mb-lg-4">
                    <b>Type: </b>{{$object->object_type}}<br/>
                    <b>Mean Anomally: </b> {{$object->mean_anomaly?$object->mean_anomaly:'-'}}<br/>
                    <b>Eccentricity: </b> {{$object->eccentricity?$object->eccentricity:'-'}}<br/>
                    <b>Mean Angular Motion: </b>{{$object->mean_angular_motion?$object->mean_angular_motion:'-'}}<br/>
                </div>
                <div class="col-12 col-lg-4 mb-0 mb-lg-4">
                    <b>Central
                        Body: </b>{!! $object->centralBody?"<a href='".url('/objects/'.$object->central_body_object_id)."'>".$object->centralBody->object_name."</a>":'-'!!}
                    <br/>
                    <b>Semimajor Axis: </b> {{$object->semimajor_axis?$object->semimajor_axis:'-'}}<br/>
                    <b>Ascending Node: </b> {{$object->ascending_node_longitude?$object->ascending_node_longitude:'-'}}
                    <br/>
                </div>
                <div class="col-12 col-lg-4 ">
                    <b>Epoch: </b>{{$object->epoch?date('m/d/Y', strtotime($object->epoch)):'-'}}<br/>
                    <b>Inclination: </b>{{$object->inclination?$object->inclination:'-'}}<br/>
                    <b>Periapsis Argument: </b>{{$object->periapsis_argument?$object->periapsis_argument:'-'}}<br/>
                </div>
            </div>
            <div class="row row-xxl row-30 row-md-60">
                <div class="col">
                    <h2 class="wow fadeInLeft">Extractable Resources</h2>
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
                                <div class="counter-modern-title">{{$abundancy->resourceObject->name}}
                                    <br/>Id: {{$abundancy->resource}}</div>
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

        </div>

    </section>
@endsection