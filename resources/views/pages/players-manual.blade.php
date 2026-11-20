@extends('layouts.app')
@section('pageTitle', 'Manual')
@push('styles')
    <style>
        /* The theme centres text and hides list markers: not for a manual */
        .players-manual, .players-manual p, .players-manual li, .players-manual h1, .players-manual h2,
        .players-manual h3, .players-manual h4, .players-manual td, .players-manual th { text-align: left !important; }
        .players-manual ul li, .players-manual ol li { display: list-item !important; }
        .players-manual ul > li::before, .players-manual ol > li::before { content: none !important; }
        .players-manual h1 { margin-top: 0; }
        .players-manual ul { list-style: disc !important; padding-left: 1.5rem !important; margin: 0.75rem 0; }
        .players-manual ol { list-style: decimal !important; padding-left: 1.5rem !important; margin: 0.75rem 0; }
        .players-manual li { margin: 0.2rem 0; }
        .players-manual ul ul { margin: 0.2rem 0; }
        .players-manual h2 { margin-top: 2.5rem; }
        .players-manual h3, .players-manual h4 { margin-top: 1.75rem; }
        .players-manual pre { background: rgba(255, 255, 255, 0.06); padding: 1rem; overflow-x: auto; }
        .players-manual pre code { background: transparent !important; color: #e8e8f0 !important; padding: 0; white-space: pre; }
        .players-manual table { margin: 1rem 0; border-collapse: collapse; display: block; overflow-x: auto; }
        .players-manual th, .players-manual td { border: 1px solid rgba(255, 255, 255, 0.2); padding: 0.4rem 0.75rem; }
        .players-manual img { max-width: 100%; height: auto; }
        .players-manual blockquote { border-left: 3px solid rgba(255, 255, 255, 0.3); padding-left: 1rem; }
        /* On a phone the manual is very long: a button back to its contents from anywhere (#884) */
        .manual-jump { position: fixed; right: 1rem; bottom: 1rem; z-index: 1020; background: #0b0f1a; color: #e8e8f0 !important;
            border: 1px solid rgba(255, 255, 255, 0.35); border-radius: 999px; padding: 0.45rem 0.9rem; font-size: 0.9rem;
            text-decoration: none !important; box-shadow: 0 4px 16px rgba(0, 0, 0, 0.5); }
        .manual-jump:hover, .manual-jump:focus { border-color: #ffb547; }
        .players-manual h2, .players-manual h3 { scroll-margin-top: 5rem; }
    </style>
@endpush
@section('content')
    <!-- Breadcrumbs-->
    <x-page-header>
        <x-slot:title>Players' manual</x-slot:title>
        <li><a href="{{ url('getting-started') }}">Getting started</a></li>
        <li class="active">Manual</li>
    </x-page-header>
    <section class="section section-lg">
        <div class="container">
            <div class="row">
                <div class="col-md-12 col-lg-10 col-xl-9 single-post players-manual">
                    {{-- documents/players-manual.md, rendered (see HomeController::playersManual) --}}
                    {!! $manual !!}
                </div>
                <a class="manual-jump" href="#table-of-contents">&uarr; Contents</a>
            </div>
        </div>
    </section>
@endsection
