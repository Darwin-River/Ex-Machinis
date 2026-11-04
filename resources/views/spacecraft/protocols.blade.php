@extends('layouts.app')
@php $title = 'Protocols' ; @endphp
@section('pageTitle', $title)
@section('content')


    <section class="section section-xs section-first bg-default text-md-left">

        <div class="container" id="container">
            <h1>{{$title}}</h1>

            <p>Protocols allow your spacecraft to mine resources, manufacture products, trade, and move between locations. Each description starts with the Lua that runs it: the library function, where there is one, such as <code>mine(kunspar)</code> or <code>push(N, R, D)</code>, and <code>perform(id, ...)</code>, which runs any protocol by its ID with its parameters after it. For example, travel (protocol 5001) to the Moon (location 10) is <code>travel(moon)</code>, or <code>perform(5001, 10)</code>. Many protocols require specific modules and resources. Click a protocol to see what it needs, what it uses up and makes, and how long it takes.</p>

            <protocols-table :protocols-list="{{json_encode($protocols)}}" keyword-parameter="{{$keyword}}"></protocols-table>

        </div>
    </section>
@endsection
