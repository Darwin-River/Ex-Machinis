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
                <li><b>On the stack:</b> <code>{{ $query->parameters > 0 ? implode(' ', array_map(fn ($i) => 'N' . $i, range(1, $query->parameters))) . ' ' : '' }}R L {{ $query->id }} query</code>, where R is the address of the array that receives the results and L its size in bytes.</li>
                <li><b>Result:</b> <code>query</code> leaves 1 when it worked, -2 if the database query failed, or -3 if there is no query with this ID.</li>
            </ul>

            <p>Results are written to the array in the order shown in the description, each field taking the number of bytes in its square brackets. Text is stored as a counted string (read it with <code>count type</code>) and numbers as two-byte values (read them with <code>@</code>).</p>
        </div>
    </section>
@endsection
