@extends('layouts.app')
@php $title = $object->object_name ; @endphp
@section('pageTitle', $title)
@section('content')
    <x-page-header>
        <x-slot:title>{{$title}}</x-slot:title>
        <li><a href="/locations">Locations</a></li>
        <li class="active">{{$title}}</li>
    </x-page-header>
    <section class="section section-xs section-first text-md-left">

        <div class="container" id="container">

            <div class="row row-30 description-fields">
                <div class="col-12 col-lg-6 col mb-0 mb-lg-4">

                    <b>Type: </b>{{$object->object_type}}<br/>

                    <b>Epoch: </b>{{$object->epoch?date('m/d/Y', strtotime($object->epoch)):'-'}}<br/>

                    <b>Semimajor Axis: </b> {{$object->semimajor_axis?$object->semimajor_axis:'-'}}<br/>

                    <b>Eccentricity: </b> {{$object->eccentricity?$object->eccentricity:'-'}}<br/>

                    <b>Periapsis Argument: </b>{{$object->periapsis_argument?$object->periapsis_argument:'-'}}
                    <br/>


                </div>
                <div class="col-12 col-lg-6">

                    <b>Central
                        Body: </b>{!! $object->centralBody?"<a href='".url('/locations/'.$object->central_body_object_id)."'>".$object->centralBody->object_name."</a>":'-'!!}
                    <br/>

                    <b>Mean Anomally: </b> {{$object->mean_anomaly?$object->mean_anomaly:'-'}}<br/>

                    <b>Inclination: </b>{{$object->inclination?$object->inclination:'-'}}<br/>

                    <b>Ascending Node: </b> {{$object->ascending_node_longitude?$object->ascending_node_longitude:'-'}}
                    <br/>

                    <b>Mean Angular
                        Motion: </b>{{$object->mean_angular_motion?$object->mean_angular_motion:'-'}}
                    <br/>

                </div>

            </div>
            <div class="row row-30">
                <div class="col">
                    <h2 class="">Extractable Resources</h2>
                </div>
            </div>
            <div class="row row-30 mt-3">
                @if(sizeof($object->abundancies)==0)
                    <div class="col">
                        <p class="">
                            No resources found.
                        </p>
                    </div>
                @else
                    @php $abundancies = $object->abundancies; @endphp
                    @foreach($abundancies as $abundancy)
                        <div class="col-4">
                            <div class="counter-modern">
                                <div class="counter-modern-title">
                                    <a
                                            href="{{url('/resources/'.$abundancy->resourceObject->id)}}">{{$abundancy->resourceObject->name}}</a>
                                </div>
                                <h2 class="counter-modern-number"><span
                                            class="counter">{{$abundancy->multiplier}}</span>
                                </h2>
                                <div class="counter-modern-title">
                                    <span class="meta-label">id</span> <a
                                            href="{{url('/resources/'.$abundancy->resourceObject->id)}}">{{$abundancy->resource}}</a>
                                </div>
                            </div>
                        </div>
                    @endforeach

                @endif
            </div>
            @if(!empty($deposits))
            <div class="row row-30">
                <div class="col">
                    <h2 class="">Deposits</h2>
                    <p>What is left to mine here now. Each deposit refills every game day, up to its capacity; a drone asks with <code>deposit ( mineral -- n )</code>.</p>
                    <div class="table-custom-responsive">
                        <table class="table-custom table-custom-primary table-deposits">
                            <tr><th>Mineral</th><th>Left</th><th>Capacity</th><th>Refills a day</th></tr>
                            @foreach($deposits as $d)
                                <tr>
                                    <td><a href="{{ url('/resources/' . $d['resource']) }}">{{ $d['name'] }}</a></td>
                                    <td>{{ number_format($d['left']) }}</td>
                                    <td>{{ number_format($d['capacity']) }}</td>
                                    <td>{{ number_format($d['refill_per_day']) }}</td>
                                </tr>
                            @endforeach
                        </table>
                    </div>
                </div>
            </div>
            @endif
            <div class="row row-30">
                <div class="col">
                    <h2 class="">Local Spacecraft</h2>
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
                                <span class="meta-label">id</span> <a
                                        href="{{url('/spacecraft/'.$agent->agent_id)}}">{{$agent->agent_id}}</a>
                            </td>
                            <td>
                                <a
                                        href="{{url('/spacecraft/'.$agent->agent_id)}}">{{$agent->name}}</a>
                            </td>
                            <td>
                            <span class="meta-label">hull</span> {{$agent->hull?$agent->hull->name:'-'}}
                            </td>
                            <td>
                            <span class="meta-label">company</span> <a
                                        href="{{url('/companies/'.$agent->user_id)}}">{{$agent->user->name}}</a>
                            </td>
                        </tr>
                    @endforeach
                </table>
            </div>
            <div class="row row-30">
                <div class="col">
                    <h2 class="">Orbiting Bodies</h2>

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
                                        <a href="{{url('locations/'.$body->object_id)}}">{{$body->object_id}}</a>
                                    </td>
                                    <td>
                                        <a href="{{url('locations/'.$body->object_id)}}">{{$body->object_name}}</a>
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
