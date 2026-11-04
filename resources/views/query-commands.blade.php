@extends('layouts.app')
@php $title = 'Queries' ; @endphp
@section('pageTitle', $title)
@section('content')

    <section class="section section-xs section-first bg-default text-md-left">

        <div class="container" id="container">
            <h1>{{$title}}</h1>

            <p>Queries let a drone look things up in the game's database: what is in its hold, prices and orders where it is, deposits, trips, the game day and more. Each description starts with the Lua that asks it: <code>ask(id, ...)</code>, with the query's parameters after its ID, returns its rows, each a table of its columns (by position, and by name), or an empty table if the query fails; <code>query(id, ...)</code> is the same but returns <code>nil</code> and a code when it fails. For example, <code>ask(210, fuel)</code>. Most questions have a named function already (<code>units(fuel)</code>, <code>best_offer(r)</code>, <code>today()</code>...): see the players' manual.</p>

            <p>The columns of each row come in the order the description lists them. Click a query to see how to call it.</p>

            <query-commands-table :queries-list="{{json_encode($queryCommands)}}" keyword-parameter="{{$keyword}}"></query-commands-table>
        </div>
    </section>
@endsection
