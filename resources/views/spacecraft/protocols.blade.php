@extends('layouts.app')
@php $title = 'Protocols' ; @endphp
@section('pageTitle', $title)
@section('content')


    <section class="section section-xs section-first bg-default text-md-left">

        <div class="container" id="container">
            <h1>{{$title}}</h1>

            <p>Protocols allow your spacecraft to mine resources, manufacture products, trade, and move between locations.  They're invoked via the FORTH-based "perform" command, which pulls one or more parameters from the stack.  The first parameter is always the ID of the protocol you want to perform. However, the command may require additional parameters (N1, N2, N3, etc) to further direct its actions.  For example, you can instruct your spacecraft to move (Protocol ID 5) to Earth (Object ID 3) by sending it the text "&lt;run&gt; 3 5 perform &lt;/run&gt;."  Many commands require specific resources to complete.</p>

            <protocols-table :protocols-list="{{json_encode($protocols)}}" keyword-parameter="{{$keyword}}"></protocols-table>

        </div>
    </section>
@endsection
