@extends('layouts.app')
@php $title = 'Queries' ; @endphp
@section('pageTitle', $title)
@section('content')

    <section class="section section-xs section-first bg-default text-md-left">

        <div class="container" id="container">
            <h1>{{$title}}</h1>

            <p>Queries, which are invoked by the FORTH-based "query" command, allow spacecraft to retrieve data from the central database.  The query command pulls at least three parameters from the stack to direct its actions. The first parameter is the ID of the query that is being invoked.  Next, the command pulls a pair of values from the stack that indicate the length (L) and address (R) of the array that will receive the query results.  Additional numbers (N1, N2, etc) and string pointers (S1, S2, etc) may be required to further define the search.</p>

            <p>When results are returned from a query, they are added to the results arrray (R) in the order indicated, with the data from each field occupying the number of bytes specified within the square brackets.</p>

            <query-commands-table :queries-list="{{json_encode($queryCommands)}}"></query-commands-table>
        </div>
    </section>
@endsection
