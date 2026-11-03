@extends('layouts.app')
@php $title = 'Protocols' ; @endphp
@section('pageTitle', $title)
@section('content')


    <section class="section section-xs section-first bg-default text-md-left">

        <div class="container" id="container">
            <h1>{{$title}}</h1>

            <p>Protocols allow your spacecraft to mine resources, manufacture products, trade, and move between locations. A drone runs one with <code>perform(id, ...)</code>: the protocol's ID, then its parameters (N1, N2, N3...) in the order the notation lists them. For example, <code>[10 5001 perform]</code>, travel (protocol 5001, which uses the engine) to the Moon (location 10), is <code>perform(5001, 10)</code>, or simply <code>go(moon)</code>. Most protocols have a named function in the drone's library (<code>mine</code>, <code>refine</code>, <code>make</code>, <code>go</code>, <code>push</code>, <code>pull</code>...). Many protocols require specific modules and resources. Click a protocol to see what it needs, what it uses up and makes, and how long it takes.</p>

            <protocols-table :protocols-list="{{json_encode($protocols)}}" keyword-parameter="{{$keyword}}"></protocols-table>

        </div>
    </section>
@endsection
