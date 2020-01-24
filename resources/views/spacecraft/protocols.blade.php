@extends('layouts.app')
@php $title = 'Protocols' ; @endphp
@section('pageTitle', $title)
@section('content')


    <section class="section section-xs section-first bg-default text-md-left">

        <div class="container" id="container">
            <h1>{{$title}}</h1>

            <protocols-table :protocols-list="{{json_encode($protocols)}}"></protocols-table>

        </div>
    </section>
@endsection