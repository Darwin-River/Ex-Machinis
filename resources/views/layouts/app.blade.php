<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>

<!-- Google Tag Manager -->
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','GTM-PX4K9BW');</script>
<!-- End Google Tag Manager -->

    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="Ex Machinis: a free multiplayer space game where you program drones in Lua to mine, build and trade across our solar system.">
    <meta name="theme-color" content="#0b0f1a">

    <title>@yield('pageTitle') - {{ config('app.name') }}</title>

    {{-- Our own design (#524): Bootstrap 4 for the grid, resources/assets/sass/site/ for the rest --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;700&family=JetBrains+Mono:wght@400;700&display=swap">
    @vite('resources/assets/sass/app.scss')

    <link rel="icon" href="/images/favicon.svg" type="image/svg+xml">
    <link rel="alternate" type="application/rss+xml" title="Ex Machinis strategy blog" href="/blog/feed">

    {{-- Page-specific styles (inside #app, Vue would drop <style> tags) --}}
    @stack('styles')
</head>

<body>

<!-- Google Tag Manager (noscript) -->
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-PX4K9BW"
height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
<!-- End Google Tag Manager (noscript) -->

<a class="skip-link" href="#main">Skip to content</a>

<div id="app" class="page">
    <header class="site-header">
        <nav class="navbar navbar-expand-lg navbar-dark container">
            <a class="navbar-brand" href="/" aria-label="Ex Machinis home">
                <img src="/images/logo.png" alt="Ex Machinis" width="203" height="16">
            </a>
            <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#site-nav"
                    aria-controls="site-nav" aria-expanded="false" aria-label="Menu">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="site-nav">
                <ul class="navbar-nav mr-auto">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="nav-learn" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">Learn</a>
                        <div class="dropdown-menu" aria-labelledby="nav-learn">
                            <a class="dropdown-item" href="{{ url('getting-started') }}">Getting started</a>
                            <a class="dropdown-item" href="{{ url('blog') }}">Strategy blog</a>
                            <a class="dropdown-item" href="{{ url('protocols') }}">Protocols</a>
                            <a class="dropdown-item" href="{{ url('queries') }}">Queries</a>
                        </div>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="nav-world" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">World</a>
                        <div class="dropdown-menu" aria-labelledby="nav-world">
                            <a class="dropdown-item" href="{{ url('market') }}">Market</a>
                            <a class="dropdown-item" href="{{ url('market/history') }}">Market history</a>
                            <a class="dropdown-item" href="{{ url('market/shares') }}">Stock exchange</a>
                            <a class="dropdown-item" href="{{ url('map') }}">Map</a>
                            <a class="dropdown-item" href="{{ url('leaderboard') }}">Leaderboard</a>
                            <a class="dropdown-item" href="{{ url('events') }}">Events</a>
                        </div>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="nav-directory" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">Directory</a>
                        <div class="dropdown-menu" aria-labelledby="nav-directory">
                            <a class="dropdown-item" href="{{ url('companies') }}">Companies</a>
                            <a class="dropdown-item" href="{{ url('spacecraft') }}">Spacecraft</a>
                            <a class="dropdown-item" href="{{ url('resources') }}">Resources</a>
                            <a class="dropdown-item" href="{{ url('locations') }}">Locations</a>
                            <a class="dropdown-item" href="{{ url('buys') }}">Buy orders</a>
                            <a class="dropdown-item" href="{{ url('sells') }}">Sell orders</a>
                        </div>
                    </li>
                    <li class="nav-item"><a class="nav-link" href="{{ url('about-us') }}">About</a></li>
                </ul>
                <ul class="navbar-nav nav-actions">
                    <li class="nav-item"><a class="nav-link nav-support" href="{{ url('support') }}" title="Keep the lights on">&#9829;<span class="d-lg-none"> Keep the lights on</span></a></li>
                    <li class="nav-item"><a class="button button-primary button-sm" href="{{ url('release') }}">Release a drone</a></li>
                </ul>
            </div>
        </nav>
    </header>

    <main id="main">
        @yield('content')
    </main>

    <footer class="site-footer">
        <div class="container">
            <div class="row">
                <div class="col-md-5 mb-4">
                    {{-- #522: one ask, for running costs only --}}
                    <h2 class="footer-title">Keep the lights on</h2>
                    <p>Ex Machinis is free to play, with no ads and nothing to buy. If you enjoy it, chip in to cover the server and the tools that build it.</p>
                    <p><a class="button button-primary" href="{{ url('support') }}">Keep the lights on</a></p>
                </div>
                <div class="col-6 col-md-3 offset-md-1 mb-4">
                    <h2 class="footer-title">Play</h2>
                    <ul class="footer-links">
                        <li><a href="{{ url('release') }}">Release a drone</a></li>
                        <li><a href="{{ url('getting-started') }}">Getting started</a></li>
                        <li><a href="{{ url('blog') }}">Strategy blog</a></li>
                        <li><a href="{{ url('leaderboard') }}">Leaderboard</a></li>
                    </ul>
                </div>
                <div class="col-6 col-md-3 mb-4">
                    <h2 class="footer-title">Community</h2>
                    <ul class="footer-links">
                        <li><a href="https://github.com/Darwin-River/Ex-Machinis">Source code</a></li>
                        <li><a href="https://github.com/Darwin-River/Ex-Machinis/blob/public-forge/CONTRIBUTING.md">Contribute</a></li>
                        <li><a href="https://github.com/Darwin-River/Ex-Machinis/issues">Report a problem</a></li>
                        <li><a href="/blog/feed">Blog RSS feed</a></li>
                    </ul>
                </div>
            </div>
            <p class="footer-legal">Ex Machinis is free and open source under the <a href="https://github.com/Darwin-River/Ex-Machinis/blob/public-forge/LICENSE">MIT licence</a>. &copy; Darwin River Software LLC and the Ex Machinis contributors.</p>
        </div>
    </footer>
</div>

<!-- Scripts -->
@vite('resources/assets/js/app.js')

</body>
</html>
