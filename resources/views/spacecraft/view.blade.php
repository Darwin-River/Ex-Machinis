@extends('layouts.app')
@php $title = "Agent ".$agent->name ; @endphp
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
                <li><a href="/spacecraft">Spacecraft</a></li>
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
                    <div class="col-12 col-sm-6 col mb-0">
                        <b>Id</b>: {{$agent->agent_id}}<br/>
                        <b>Location</b>: <a
                                href="{{url('objects/'.$agent->object_id)}}">{{$agent->location->object_name}}</a><br/>

                        <b>Owner</b>: <a href="{{url('companies/'.$agent->user_id)}}">{{$agent->user->name}}</a><br/>
                    </div>
                    <div class="col-12 col-sm-6 col ">
                        <b>Hull</b>: {{$agent->hull->name}}<br/>
                        <b>Max Cargo</b>: {{$agent->hull->cargo_capacity}} kg.<br/>

                        <b>Current Cargo</b>: {{$totalWeight}} kg.<br/>
                    </div>
                </div>
            </div>
            <div class="row row-xxl row-30 row-md-60">
                <div class="col">
                    <h2 class="wow fadeInLeft">Cargo Manifest</h2>
                </div>
            </div>
            <div class="table-custom-responsive">
                <table class="table-ticket">
                    @if(sizeof($cargoManifest) == 0)
                        <tr>
                            <td colspan="4"> No cargo loaded</td>
                        </tr>
                    @endif
                    @foreach($cargoManifest as $cargoPlacement)
                        <tr>
                            <td>
                                <a href="{{url('/resourecs/'.$cargoPlacement->resource_id)}}">{{$cargoPlacement->name}}</a>
                            </td>
                            <td>
                                @if($cargoPlacement->locked)
                                    <span class="icon mdi mdi-lock" title="resource in use"></span>
                                @endif
                            </td>
                            <td><span class="rd-nav-icon mdi mdi-package-down" title="cargo"></span>
                                {{$cargoPlacement->new_quantity}} units
                            </td>
                            <td>
                            <td><span class="rd-nav-icon mdi mdi-weight" title="weight"></span>
                                {{$cargoPlacement->new_quantity*$cargoPlacement->mass}} kg
                            </td>
                        <!--     <td>
                                Unit Mass: {{$cargoPlacement->mass}} kg

                            </td>-->
                        </tr>
                    @endforeach
                </table>
            </div>
            <div class="row row-xxl row-30 row-md-60">
                <div class="col">
                    <h2 class="wow fadeInLeft">Observed Events</h2>
                </div>
            </div>
            <in-game-events-table
                    :results-per-page="{{Config::get('constants.options.results_per_page')}}" :limit-results="10"
                    :agent-id="{{$agent->agent_id}}"></in-game-events-table>
        </div>
    </section>

@endsection