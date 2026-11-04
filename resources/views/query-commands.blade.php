@extends('layouts.app')
@php $title = 'Queries' ; @endphp
@section('pageTitle', $title)
@section('content')

    <section class="section section-xs section-first bg-default text-md-left">

        <div class="container" id="container">
            <h1>{{$title}}</h1>

            <p>Queries let a drone look things up in the game's database: what is in its hold, prices and orders where it is, deposits, trips, the game day and more. In Lua, <code>query(id, ...)</code> runs one and returns its rows, each a table of its columns (by position, and by name). The extra parameters (N1, N2... and strings S1, S2...) go after the ID in the order the notation below lists them, without the L and R, which were FORTH's results buffer: <code>[N R L 210 query]</code> becomes <code>query(210, n)</code>. Most questions have a named function already (<code>units(fuel)</code>, <code>best_offer(r)</code>, <code>today()</code>...): see the players' manual.</p>

            <p>When results are returned from a query, they are added to the results arrray (R) in the order indicated, with the data from each field occupying the number of bytes specified within the square brackets. Click a query to see how to call it.</p>

            <query-commands-table :queries-list="{{json_encode($queryCommands)}}" keyword-parameter="{{$keyword}}"></query-commands-table>
        </div>
    </section>
@endsection
