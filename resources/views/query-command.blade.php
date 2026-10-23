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
                <li><b>Result:</b> <code>query</code> leaves the number of rows it stored in the array (0 if the array is too small for one row), -2 if the database query failed or found nothing, or -3 if there is no query with this ID.</li>
            </ul>

            <p>Results are written to the array in the order shown in the description, one row after another, and only whole rows. Text is stored as a counted string (a length byte and the characters: read it with <code>count type</code>) and numbers as two-byte values (read them with <code>@</code>). A row of numbers only is 2 bytes per column, so row n (from 0) starts at R + n &times; that size.</p>
        </div>
    </section>
@endsection
