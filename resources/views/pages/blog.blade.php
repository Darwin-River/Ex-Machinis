@extends('layouts.app')
@section('pageTitle', 'Strategy Blog')
@push('styles')
    <style>
        /* The theme centres text and hides list markers: not for a manual */
        .blog-post, .blog-post p, .blog-post li, .blog-post h1, .blog-post h2,
        .blog-post h3, .blog-post h4, .blog-post td, .blog-post th { text-align: left !important; }
        .blog-post ul li, .blog-post ol li { display: list-item !important; }
        .blog-post ul > li::before, .blog-post ol > li::before { content: none !important; }
        .blog-post h1 { margin-top: 0; }
        .blog-post ul { list-style: disc !important; padding-left: 1.5rem !important; margin: 0.75rem 0; }
        .blog-post ol { list-style: decimal !important; padding-left: 1.5rem !important; margin: 0.75rem 0; }
        .blog-post li { margin: 0.2rem 0; }
        .blog-post ul ul { margin: 0.2rem 0; }
        .blog-post h2 { margin-top: 2.5rem; }
        .blog-post h3, .blog-post h4 { margin-top: 1.75rem; }
        .blog-post pre { background: rgba(255, 255, 255, 0.06); padding: 1rem; overflow-x: auto; }
        .blog-post pre code { background: transparent !important; color: #e8e8f0 !important; padding: 0; white-space: pre; }
        .blog-post table { margin: 1rem 0; border-collapse: collapse; display: block; overflow-x: auto; }
        .blog-post th, .blog-post td { border: 1px solid rgba(255, 255, 255, 0.2); padding: 0.4rem 0.75rem; }
        .blog-post img { max-width: 100%; height: auto; }
        .blog-post .blog-date { opacity: 0.7; font-size: 0.9rem; }
        .blog-post .blog-entry { margin-top: 2rem; }
        .blog-post .blog-nav { margin-top: 3rem; }
        .blog-post blockquote { border-left: 3px solid rgba(255, 255, 255, 0.3); padding-left: 1rem; }
    </style>
@endpush
@section('content')
    <section class="breadcrumbs-modern box-transform-wrap bg-image context-dark"
             style="background-image: url('/images/bg-breadcrumbs.jpg');">
        <div class="container">
            <div class="breadcrumbs-modern-body">
                <h5 class="breadcrumbs-modern-subtitle"></h5>
                <h1 class="breadcrumbs-modern-title">Strategy Blog</h1>
            </div>
            <ul class="breadcrumbs-path breadcrumbs-modern-path">
                <li><a href="/">Home</a></li>
                <li class="active">Strategy Blog</li>
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
                <div class="col-md-12 col-lg-10 col-xl-9 single-post blog-post">
                    <p>How the most successful companies in our playtests were written: the strategy, the code behind it, and the players who made it work. Every code example loads on a drone as it is.</p>
                    {{-- documents/blog/*.md (see BlogController) --}}
                    @foreach ($posts as $slug => $post)
                        <article class="blog-entry">
                            <h3><a href="{{ url('blog/' . $slug) }}">{{ $post['title'] }}</a></h3>
                            <p class="blog-date">{{ $post['date'] }}</p>
                            <p>{{ $post['summary'] }}</p>
                        </article>
                    @endforeach
                </div>
            </div>
        </div>
    </section>
@endsection
