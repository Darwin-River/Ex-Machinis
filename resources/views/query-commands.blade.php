@extends('layouts.app')
@php $title = 'Query Commands' ; @endphp
@section('pageTitle', $title)
@section('content')

    <section class="section section-xs section-first bg-default text-md-left">

        <div class="container" id="container">
            <h1>{{$title}}</h1>
            <query-commands-table :queries-list="{{json_encode($queryCommands)}}"></query-commands-table>
        </div>
    </section>
@endsection