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
            {{-- Outside the collapsing menu, so it shows on a phone too; last on a wide screen --}}
            <div class="order-lg-last ml-auto ml-lg-3 mr-2 mr-lg-0">@include('partials.emote-button')</div>
            <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#site-nav"
                    aria-controls="site-nav" aria-expanded="false" aria-label="Menu">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="site-nav">
                @php($navPlayer = auth()->check() && auth()->user()->login !== null ? auth()->user() : null)
                @php($navVisitor = config('forge.new_rules') && !$navPlayer)
                {{-- The next step on the Player Ladder (#1039, #1041): the menu's button, kept a minute with the strip's --}}
                @php($navNext = config('forge.new_rules') && $navPlayer ? \App\Services\Pipeline::cached($navPlayer)['path'] : null)
                @php($navNextStep = $navNext && $navNext['next'] ? \App\Services\Pipeline::step($navNext, $navNext['next']) : null)
                @if($navVisitor)
                {{-- A visitor's menus (#1045): watch and try a drone, explore the world, learn the game --}}
                <ul class="navbar-nav mr-auto">
                    <li class="nav-item"><a class="nav-link" href="{{ url('/') }}#home-live" id="nav-watch">Watch</a></li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="nav-explore" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">Explore</a>
                        <div class="dropdown-menu" aria-labelledby="nav-explore">
                            <h6 class="dropdown-header">Market</h6>
                            <a class="dropdown-item" href="{{ url('market') }}">Goods</a>
                            <a class="dropdown-item" href="{{ url('market/house') }}">House</a>
                            <a class="dropdown-item" href="{{ url('market/history') }}">Price history</a>
                            <a class="dropdown-item" href="{{ url('market/shares') }}">Stock exchange</a>
                            <a class="dropdown-item" href="{{ url('market/loans') }}">Loans</a>
                            <a class="dropdown-item" href="{{ url('market/trade') }}">Trading desk</a>
                            <a class="dropdown-item" href="{{ url('dashboard') }}">Economy dashboard</a>
                            <h6 class="dropdown-header">Companies</h6>
                            <a class="dropdown-item" href="{{ url('leaderboard') }}">Leaderboard</a>
                            <a class="dropdown-item" href="{{ url('players') }}">Players</a>
                            <a class="dropdown-item" href="{{ url('companies') }}">All companies</a>
                            <a class="dropdown-item" href="{{ url('startups/race') }}">Startup race</a>
                            <a class="dropdown-item" href="{{ url('spacecraft') }}">Drones</a>
                            <a class="dropdown-item" href="{{ url('lineage') }}">Lineage</a>
                            <a class="dropdown-item" href="{{ url('events') }}">Events</a>
                            <a class="dropdown-item" href="{{ url('map') }}">Map</a>
                        </div>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="nav-learn" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">Learn</a>
                        <div class="dropdown-menu" aria-labelledby="nav-learn">
                            <a class="dropdown-item" href="{{ url('try') }}">Fly your first drone (no account)</a>
                            <a class="dropdown-item" href="{{ url('getting-started') }}">First startup (5 minutes)</a>
                            <a class="dropdown-item" href="{{ url('release') }}">Found a startup</a>
                            <a class="dropdown-item" href="{{ url('manual') }}">Manual</a>
                            <a class="dropdown-item" href="{{ url('blog') }}">Strategy blog</a>
                            <a class="dropdown-item" href="{{ url('badges') }}">Badges</a>
                            <div class="dropdown-divider"></div>
                            {{-- These open GitHub: said so (#885) --}}
                            <a class="dropdown-item" href="https://github.com/Ludocia/ExMachinis/blob/main/documents/lua-functions.md" rel="noopener">Lua functions <small>(on GitHub) &#8599;</small></a>
                            <a class="dropdown-item" href="https://github.com/Ludocia/ExMachinis/blob/main/documents/api.md" rel="noopener">API <small>(on GitHub) &#8599;</small></a>
                            <a class="dropdown-item" href="{{ url('technology') }}">Tech map</a>
                            <a class="dropdown-item" href="{{ url('resources') }}">Resources</a>
                            <a class="dropdown-item" href="{{ url('protocols') }}">Protocols</a>
                            <a class="dropdown-item" href="{{ url('queries') }}">Queries</a>
                            <a class="dropdown-item" href="{{ url('locations') }}">Locations</a>
                        </div>
                    </li>
                @else
                {{-- Menus by what a player is trying to do (#843): play, trade, see who does well, look things up --}}
                <ul class="navbar-nav mr-auto">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="nav-play" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">Play</a>
                        <div class="dropdown-menu" aria-labelledby="nav-play">
                            {{-- In the order of the path through the game (#987, #1043) --}}
                            @if(config('forge.new_rules'))
                                <h6 class="dropdown-header">Start</h6>
                                <a class="dropdown-item" href="{{ url('try') }}">Try a drone</a>
                            @endif
                            {{-- The five-minute guide and the manual are two pages (#719) --}}
                            <a class="dropdown-item" href="{{ url('getting-started') }}">First startup (5 minutes)</a>
                            <a class="dropdown-item" href="{{ url('release') }}">{{ config('forge.new_rules') ? 'Found a startup' : 'Release a drone' }}</a>
                            @if(config('forge.new_rules'))
                                <a class="dropdown-item" href="{{ url('startups/race') }}">Startup race</a>
                                <h6 class="dropdown-header">Own</h6>
                                <a class="dropdown-item" href="{{ url('account') }}">Your companies and shares</a>
                                <h6 class="dropdown-header">Invest</h6>
                                <a class="dropdown-item" href="{{ url('market/shares/first') }}">Your first share</a>
                                <a class="dropdown-item" href="{{ url('market/shares') }}">Stock exchange</a>
                                <a class="dropdown-item" href="{{ url('market/trade') }}">Trading desk</a>
                                <h6 class="dropdown-header">Lend</h6>
                                <a class="dropdown-item" href="{{ url('market/loans/first') }}">Your first loan</a>
                                <a class="dropdown-item" href="{{ url('market/loans') }}">Loan requests</a>
                            @endif
                        </div>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="nav-market" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">Market</a>
                        <div class="dropdown-menu" aria-labelledby="nav-market">
                            {{-- The same pages, in the same order, as the market's own tabs --}}
                            <a class="dropdown-item" href="{{ url('market') }}">Goods</a>
                            <a class="dropdown-item" href="{{ url('market/house') }}">House</a>
                            <a class="dropdown-item" href="{{ url('market/history') }}">Price history</a>
                            <a class="dropdown-item" href="{{ url('market/shares') }}">Stock exchange</a>
                            <a class="dropdown-item" href="{{ url('market/loans') }}">Loans</a>
                            <a class="dropdown-item" href="{{ url('market/trade') }}">Trading desk</a>
                            <div class="dropdown-divider"></div>
                            <a class="dropdown-item" href="{{ url('dashboard') }}">Economy dashboard</a>
                        </div>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="nav-companies" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">Companies</a>
                        <div class="dropdown-menu" aria-labelledby="nav-companies">
                            <a class="dropdown-item" href="{{ url('leaderboard') }}">Leaderboard</a>
                            @if(config('forge.new_rules'))<a class="dropdown-item" href="{{ url('players') }}">Players</a>@endif
                            <a class="dropdown-item" href="{{ url('companies') }}">All companies</a>
                            <a class="dropdown-item" href="{{ url('spacecraft') }}">Drones</a>
                            <a class="dropdown-item" href="{{ url('lineage') }}">Lineage</a>
                            <a class="dropdown-item" href="{{ url('events') }}">Events</a>
                            <a class="dropdown-item" href="{{ url('map') }}">Map</a>
                        </div>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="nav-reference" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">Reference</a>
                        <div class="dropdown-menu" aria-labelledby="nav-reference">
                            <a class="dropdown-item" href="{{ url('manual') }}">Manual</a>
                            <a class="dropdown-item" href="{{ url('badges') }}">Badges</a>
                            {{-- These open GitHub: said so (#885) --}}
                            <a class="dropdown-item" href="https://github.com/Ludocia/ExMachinis/blob/main/documents/lua-functions.md" rel="noopener">Lua functions <small>(on GitHub) &#8599;</small></a>
                            <a class="dropdown-item" href="https://github.com/Ludocia/ExMachinis/blob/main/documents/api.md" rel="noopener">API <small>(on GitHub) &#8599;</small></a>
                            <div class="dropdown-divider"></div>
                            <a class="dropdown-item" href="{{ url('technology') }}">Tech map</a>
                            <a class="dropdown-item" href="{{ url('resources') }}">Resources</a>
                            <a class="dropdown-item" href="{{ url('protocols') }}">Protocols</a>
                            <a class="dropdown-item" href="{{ url('queries') }}">Queries</a>
                            <a class="dropdown-item" href="{{ url('locations') }}">Locations</a>
                            <div class="dropdown-divider"></div>
                            <a class="dropdown-item" href="{{ url('blog') }}">Strategy blog</a>
                        </div>
                    </li>
                @endif
                    <li class="nav-item"><a class="nav-link" href="{{ url('about-us') }}">About</a></li>
                </ul>
                <ul class="navbar-nav nav-actions">
                    <li class="nav-item"><a class="nav-link nav-support" href="{{ url('support') }}" title="Keep the lights on">&#9829;<span class="d-lg-none"> Keep the lights on</span></a></li>
                    @if(config('forge.new_rules'))
                        {{-- Player accounts (#636) --}}
                        @if(auth()->check() && auth()->user()->login !== null)
                            <li class="nav-item"><a class="nav-link" href="{{ url('account') }}">{{ auth()->user()->login }}</a></li>
                        @else
                            <li class="nav-item"><a class="nav-link" href="{{ url('login') }}">Log in</a></li>
                        @endif
                    @endif
                    {{-- One button: for a visitor, try a drone (#1045); for a player, their next step (#1041); else the action's one name (#719) --}}
                    @if($navVisitor)
                        <li class="nav-item"><a class="button button-primary button-sm" href="{{ url('try') }}" id="nav-cta">Join the Economy</a></li>
                    @elseif($navNextStep)
                        <li class="nav-item"><a class="button button-primary button-sm" href="{{ $navNextStep['url'] }}" id="nav-cta">Next: {{ $navNextStep['action'] }}</a></li>
                    @else
                        <li class="nav-item"><a class="button button-primary button-sm" href="{{ url('release') }}" id="nav-cta">{{ config('forge.new_rules') ? 'Found a startup' : 'Release a drone' }}</a></li>
                    @endif
                </ul>
            </div>
        </nav>
    </header>
    @include('partials.game-clock')
    @include('partials.company-now')
    {{-- The next step and the rules so far (#932) --}}
    @include('partials.next-strip')

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
                        <li><a href="{{ url('release') }}">{{ config('forge.new_rules') ? 'Found a startup' : 'Release a drone' }}</a></li>
                        <li><a href="{{ url('getting-started') }}">First startup (5 minutes)</a></li>
                        <li><a href="{{ url('market') }}">Market</a></li>
                        <li><a href="{{ url('leaderboard') }}">Company leaderboard</a></li>
                        @if(config('forge.new_rules'))<li><a href="{{ url('players') }}">Players</a></li>@endif
                        <li><a href="{{ url('manual') }}">Manual</a></li>
                        <li><a href="{{ url('blog') }}">Strategy blog</a></li>
                    </ul>
                </div>
                <div class="col-6 col-md-3 mb-4">
                    <h2 class="footer-title">Community</h2>
                    <ul class="footer-links">
                        <li><a href="https://github.com/Ludocia/ExMachinis">Source code</a></li>
                        <li><a href="https://github.com/Ludocia/ExMachinis/blob/main/CONTRIBUTING.md">Contribute</a></li>
                        <li><a href="https://github.com/Ludocia/ExMachinis/issues">Report a problem</a></li>
                        <li><a href="/blog/feed">Blog RSS feed</a></li>
                    </ul>
                </div>
            </div>
            <p class="footer-legal">Ex Machinis is free and open source under the <a href="https://github.com/Ludocia/ExMachinis/blob/main/LICENSE">MIT licence</a>. &copy; the Ex Machinis contributors.</p>
        </div>
    </footer>
</div>

@include('partials.emote-dialog')

<!-- Scripts -->
@vite('resources/assets/js/app.js')
{{-- A wide table on a phone says it scrolls sideways (#716): a cue above it while it overflows --}}
<style>
    /* Links in the footer's text read as links (#744) */
    .site-footer a:not(.button) { text-decoration: underline; text-underline-offset: 2px; }
    .ex-scrolls::before { content: 'Scroll sideways for more \2192'; display: block; position: sticky; left: 0; width: max-content;
          font-size: 12px; color: #9aa3b8; padding: 0 0 4px; }
</style>
<script>
(function () {
    const mark = () => document.querySelectorAll('.table-custom-responsive, .table-responsive, .table-scroll').forEach((box) => {
        box.classList.toggle('ex-scrolls', box.scrollWidth > box.clientWidth + 4);
    });
    document.addEventListener('DOMContentLoaded', () => { mark(); setTimeout(mark, 1500); });
    window.addEventListener('resize', mark);
})();
</script>

</body>
</html>
