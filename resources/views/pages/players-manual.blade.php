@extends('layouts.app')
@section('pageTitle', 'Getting Started')
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
        .players-manual table { margin: 1rem 0; border-collapse: collapse; display: block; overflow-x: auto; }
        .players-manual th, .players-manual td { border: 1px solid rgba(255, 255, 255, 0.2); padding: 0.4rem 0.75rem; }
        .players-manual img { max-width: 100%; height: auto; }
        .players-manual blockquote { border-left: 3px solid rgba(255, 255, 255, 0.3); padding-left: 1rem; }
    </style>
@endpush
@section('content')
    <!-- Breadcrumbs-->
    <section class="breadcrumbs-modern box-transform-wrap bg-image context-dark"
             style="background-image: url('/images/bg-breadcrumbs.jpg');">
        <div class="container">
            <div class="breadcrumbs-modern-body">
                <h5 class="breadcrumbs-modern-subtitle"></h5>
                <h1 class="breadcrumbs-modern-title">Getting Started</h1>
            </div>
            <ul class="breadcrumbs-path breadcrumbs-modern-path">
                <li><a href="/">Home</a></li>
                <li class="active">Getting Started</li>
            </ul>
        </div>
        <div class="box-transform" style="background-image: url('/images/bg-breadcrumbs.jpg');"></div>
        <div class="comets comets-left"></div>
        <div class="comets comets-center"></div>
        <div class="comets comets-top"></div>
    </section>
    <section class="section section-lg bg-default">
        <div class="container">
            <div class="row">
                <div class="col-md-12 col-lg-10 col-xl-9 single-post players-manual">
                    {{-- documents/players-manual.md, rendered (see HomeController::playersManual) --}}
                    {!! $manual !!}
                </div>
            </div>
        </div>
    </section>
@endsection
