@extends('layouts.app')
@php $title = 'Queries' ; @endphp
@section('pageTitle', $title)
@section('content')

    <x-page-header :title="$title" />
    <section class="section section-xs section-first text-md-left">

        <div class="container" id="container">

            <p>Queries let a drone look things up in the game's database: what is in its hold, prices and orders where it is, deposits, trips, the game day and more. Each description starts with the drone library's function that asks it, such as <code>sell_orders(N)</code> or <code>trip_to(P)</code>, which returns plain values or records with named fields (see the players' manual, chapter 8). Then comes <code>ask(id, ...)</code>, which asks any query by its ID, with its parameters after the ID, and returns its rows, each a table of its columns (by position, and by name), or an empty table if the query fails; <code>query(id, ...)</code> is the same but returns <code>nil</code> and a code when it fails.</p>

            <p>The columns of each row come in the order the description lists them. Click a query to see how to call it.</p>

            <query-commands-table :queries-list="{{json_encode($queryCommands)}}" keyword-parameter="{{$keyword}}"></query-commands-table>
        </div>
    </section>
@endsection
