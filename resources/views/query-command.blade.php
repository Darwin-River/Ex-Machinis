@extends('layouts.app')
@php $title = $query->name; @endphp
@section('pageTitle', $title)
@section('content')
    <section class="section section-xs section-first bg-default text-md-left">
        <div class="container" id="container">
            <p><a href="{{ url('/queries') }}">Queries</a> / {{ $query->id }}</p>
            <h1>{{ $title }}</h1>

            <p>{{ $query->description }}</p>

            <h4 class="mt-4">Running it</h4>
            <ul>
                <li><b>Query ID:</b> {{ $query->id }}</li>
                <li><b>Query parameters:</b> {{ $query->parameters }}</li>
                <li><b>In Lua:</b> <code>ask({{ $query->id }}{{ $query->parameters > 0 ? ', ' . implode(', ', array_map(fn ($i) => 'N' . $i, range(1, $query->parameters))) : '' }})</code>, with the parameters in the order the description gives them.</li>
                <li><b>Result:</b> a table of rows, each a table of its columns in the order the description lists them: <code>rows[1][1]</code> is the first row's first column, and a column can also be read by its name in the query. No rows is an empty table. <code>ask</code> returns an empty table if the query fails; <code>query({{ $query->id }}, ...)</code> returns <code>nil</code> and a code instead: -1 for too few parameters, -2 if the database query failed, -3 if there is no query with this ID.</li>
            </ul>

            <p>Numbers come back as plain Lua numbers, whatever their size, and text as strings. An empty column (SQL's NULL) comes back as <code>false</code>.</p>
        </div>
    </section>
@endsection
