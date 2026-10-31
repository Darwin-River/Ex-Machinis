<!DOCTYPE html>
<html class="wide wow-animation" lang="{{ app()->getLocale() }}">
<head>

<!-- Google Tag Manager -->
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','GTM-PX4K9BW');</script>
<!-- End Google Tag Manager -->

    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport"
          content="width=device-width, height=device-height, initial-scale=1.0, maximum-scale=1.0, user-scalable=0">

    <!-- CSRF Token -->
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('pageTitle') - {{ config('app.name') }}</title>

    <!-- Styles -->
    <link rel="stylesheet" type="text/css"
          href="//fonts.googleapis.com/css?family=Open+Sans:400,400i,600,700,700i%7CYanone+Kaffeesatz:300,700%7CNova+Mono">
    @vite('resources/assets/sass/app.scss')


    <meta name="format-detection" content="telephone=no">
    <link rel="icon" href="/images/favicon.ico" type="image/x-icon">
    <!--[if lt IE 10]>
    <div style="background: #212121; padding: 10px 0; box-shadow: 3px 3px 5px 0 rgba(0,0,0,.3); clear: both; text-align:center; position: relative; z-index:1;">
        <a href="http://windows.microsoft.com/en-US/internet-explorer/"><img
                src="images/ie8-panel/warning_bar_0000_us.jpg" border="0" height="42" width="820"
                alt="You are using an outdated browser. For a faster, safer browsing experience, upgrade for free today."></a>
    </div>
    <script src="js/html5shiv.min.js"></script>
    <![endif]-->

    {{-- Page-specific styles (inside #app, Vue would drop <style> tags) --}}
    @stack('styles')
</head>

<body>

<!-- Google Tag Manager (noscript) -->
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-PX4K9BW"
height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
<!-- End Google Tag Manager (noscript) -->

<div class="preloader">
    <div class="preloader-wrap"><a class="brand" href="/"><img class="brand-logo-dark"
                                                               src="/images/logo.png" alt="loading"
            /><img
                    class="brand-logo-light" src="/images/logo.png" alt=""/></a>
        <div class="fountainG-wrap">
            <div class="fountainG_1 fountainG"></div>
            <div class="fountainG_2 fountainG"></div>
            <div class="fountainG_3 fountainG"></div>
            <div class="fountainG_4 fountainG"></div>
            <div class="fountainG_5 fountainG"></div>
            <div class="fountainG_6 fountainG"></div>
            <div class="fountainG_7 fountainG"></div>
            <div class="fountainG_8 fountainG"></div>
        </div>
    </div>
</div>
<div id="app" class="page">
    <header class="section page-header">
        <!-- RD Navbar-->
        <div class="rd-navbar-wrap">
            <nav class="rd-navbar rd-navbar-classic" data-layout="rd-navbar-fixed" data-sm-layout="rd-navbar-fixed"
                 data-md-layout="rd-navbar-fixed" data-md-device-layout="rd-navbar-fixed"
                 data-lg-device-layout="rd-navbar-fixed" data-lg-layout="rd-navbar-static"
                 data-xl-layout="rd-navbar-static" data-xl-device-layout="rd-navbar-static"
                 data-xxl-layout="rd-navbar-static" data-xxl-device-layout="rd-navbar-static" data-lg-stick-up="false"
                 data-xl-stick-up="false" data-xxl-stick-up="false" data-body-class="body-style-1">
                <div class="rd-navbar-collapse-toggle rd-navbar-fixed-element-1"
                     data-rd-navbar-toggle=".rd-navbar-collapse"><span></span></div>
                <div class="rd-navbar-aside-outer">
                    <div class="rd-navbar-aside">
                        <!-- RD Navbar Panel-->
                        <div class="rd-navbar-panel">
                            <!-- RD Navbar Toggle-->
                            <button class="rd-navbar-toggle" data-rd-navbar-toggle=".rd-navbar-nav-wrap"><span></span>
                            </button>
                            <!-- RD Navbar Brand-->
                            <div class="rd-navbar-brand"><a class="brand" href="/"><img class="brand-logo-dark"
                                                                                        src="/images/logo.png" alt=""
                                                                                        width="200"/><img
                                            class="brand-logo-light" src="/images/logo.png" alt="ex-machinis logo"/></a>
                            </div>
                        </div>
                        <div class="rd-navbar-aside-info rd-navbar-collapse">
                            <p class="rd-navbar-quote d-none d-sm-block">“Give me a lever… and I shall move the world” -
                                Archimedes</p>
                            <div>
                                <ul class="list-social-modern">
                                    <li><a class="icon mdi mdi-twitter" href="https://twitter.com/exmachinis"
                                           title="Twitter"></a></li>
                                    <li><a class="icon mdi mdi-patreon" href="https://www.patreon.com/exmachinis"
                                           title="Patreon"></a>
                                    </li>
                                    <li title="Github"><a class="icon mdi mdi-github-circle"
                                                          href="https://github.com/Darwin-River/Ex-Machinis"></a></li>
                                    <!--  <li><a class="icon mdi mdi-facebook" href="#"></a></li> -->
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="rd-navbar-main-outer">
                    <div class="rd-navbar-main">
                        <div class="rd-navbar-nav-wrap">
                            <!-- RD Navbar Nav-->
                            <ul class="rd-navbar-nav">
                              <!--
                                <li class="rd-nav-item"><a class="rd-nav-link" href="/"><span
                                                class="rd-nav-icon mdi mdi-home"></span><span
                                                class="rd-nav-title">Home</span></a>

                                </li>
                              -->




                                <li class="rd-nav-item d-none d-lg-block"><a class="rd-nav-link "
                                                                             href="{{url('release')}}"><span
                                                class="rd-nav-icon mdi mdi-rocket"></span><span
                                                class="rd-nav-title">Release</span></a></li>
                                <li class="rd-nav-item d-none d-lg-block"><a class="rd-nav-link "
                                                                             href="{{url('getting-started')}}"><span
                                                class="rd-nav-icon mdi mdi-satellite-uplink"></span><span
                                                class="rd-nav-title">Documentation</span></a>
                                    <!-- RD Navbar Dropdown-->
                                    <ul class="rd-menu rd-navbar-dropdown">
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('getting-started')}}">Getting Started</a></li>
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('blog')}}">Strategy Blog</a></li>
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('protocols')}}">Protocols</a></li>
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('queries')}}">Queries</a></li>
                                    <!-- Hiding the FORTH Dictionary for now.
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('forth-dictionary')}}">FORTH
                                                Dictionary</a></li>
                                        -->
                                    </ul>
                                </li>
                                <li class="rd-nav-item d-none d-lg-block"><a class="rd-nav-link "
                                                                             href="{{'companies'}}"><span
                                                class="rd-nav-icon mdi mdi-factory"></span><span
                                                class="rd-nav-title">Infrastructure</span></a>
                                    <!-- RD Navbar Dropdown-->
                                    <ul class="rd-menu rd-navbar-dropdown">
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('companies')}}">Companies</a></li>
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('spacecraft')}}">Spacecraft</a></li>
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('resources')}}">Resources</a></li>
                                    </ul>
                                </li>
                                <li class="rd-nav-item d-none d-lg-block"><a class="rd-nav-link "
                                                                             href="{{'buys'}}"><span
                                                class="rd-nav-icon mdi mdi-chart-line"></span><span
                                                class="rd-nav-title">Markets</span></a>
                                    <!-- RD Navbar Dropdown-->
                                    <ul class="rd-menu rd-navbar-dropdown">
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('market')}}">Market</a></li>
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('market/history')}}">Market history</a></li>
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('map')}}">Map</a></li>
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('leaderboard')}}">Leaderboard</a></li>
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('machine')}}">Machine</a></li>
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('buys')}}">Buy Orders</a></li>
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('sells')}}">Sell Orders</a></li>
                                    </ul>
                                </li>
                                <li class="rd-nav-item d-none d-lg-block"><a class="rd-nav-link "
                                                                             href="{{'locations'}}"><span
                                                class="rd-nav-icon mdi mdi-moon-waning-crescent"></span><span
                                                class="rd-nav-title">Environment</span></a>
                                    <!-- RD Navbar Dropdown-->
                                    <ul class="rd-menu rd-navbar-dropdown">
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('locations')}}">Locations</a></li>
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('events')}}">Events</a></li>
                                    </ul>
                                </li>
                                <!--  <li class="rd-nav-item active"><a class="rd-nav-link" href="contacts.html"><span
                                                  class="rd-nav-icon mdi mdi-email"></span><span class="rd-nav-title">Contacts</span></a>
                                  </li> -->
                            </ul>

                            <!-- MAIN MENU -->
                            <ul class="rd-navbar-list-link">


                                <li class="d-none d-lg-inline-block"><a class="" href="">Command and Control</a><span
                                            class="rd-navbar-submenu-toggle"></span>
                                    <!-- RD Navbar Dropdown-->
                                    <ul class="rd-menu rd-navbar-dropdown oriented-down">
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('getting-started')}}">Getting Started</a></li>
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('blog')}}">Strategy Blog</a></li>
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('protocols')}}">Protocols</a></li>
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('queries')}}">Queries</a></li>
                                    <!-- Hiding the FORTH Dictionary for now.
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('forth-dictionary')}}">FORTH
                                                Dictionary</a></li>
                                        -->
                                    </ul>
                                </li>
                                <li class="d-none d-lg-inline-block"><a class="" href="{{url('companies')}}">Infrastructure</a><span
                                            class="rd-navbar-submenu-toggle"></span>
                                    <!-- RD Navbar Dropdown-->
                                    <ul class="rd-menu rd-navbar-dropdown oriented-down">
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('companies')}}">Companies</a></li>
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('spacecraft')}}">Spacecraft</a></li>
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('resources')}}">Resources</a></li>
                                    </ul>
                                </li>
                                <li><a class="" href="{{url('buys')}}">Markets</a><span
                                            class="rd-navbar-submenu-toggle"></span>
                                    <!-- RD Navbar Dropdown-->
                                    <ul class="rd-menu rd-navbar-dropdown oriented-down">
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('market')}}">Market</a></li>
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('market/history')}}">Market history</a></li>
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('map')}}">Map</a></li>
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('leaderboard')}}">Leaderboard</a></li>
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('machine')}}">Machine</a></li>
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('buys')}}">Buy Orders</a></li>
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('sells')}}">Sell Orders</a></li>
                                    </ul>
                                </li>
                                <li><a href="{{url('locations')}}">Environnmental</a><span
                                            class="rd-navbar-submenu-toggle"></span>
                                    <!-- RD Navbar Dropdown-->
                                    <ul class="rd-menu rd-navbar-dropdown oriented-down">
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('locations')}}">Locations</a></li>
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('events')}}">Events</a></li>
                                    </ul>
                                </li>
                                <!--
                                <li class="d-block d-lg-none"><a href="{{url('players-manual')}}">Player's Manual</a>
                                </li>
                                <li class="d-block d-lg-none"><a href="{{url('protocols')}}">Protocols</a></li>
                                <li class="d-block d-lg-none"><a href="{{url('queries')}}">Queries</a></li>
                              -->
                            <!-- Hide for now.
                                <li class="d-block d-lg-none"><a href="{{url('forth-dictionary')}}">FORTH Dictionary</a></li>
                                -->
                                <!--
                                <li class="d-block d-lg-none"><a href="{{url('locations')}}">Locations</a></li>
                                <li class="d-block d-lg-none"><a href="{{url('companies')}}">Companies</a></li>
                                <li class="d-block d-lg-none"><a href="{{url('spacecraft')}}">Spacecraft</a></li>
                                <li class="d-block d-lg-none"><a href="{{url('resources')}}">Resources</a></li>
                                <li class="d-block d-lg-none"><a href="{{url('events')}}">Events</a></li>
                                <li class="d-block d-lg-none"><a href="{{url('buys')}}">Buy Orders</a></li>
                                <li class="d-block d-lg-none"><a href="{{url('sells')}}">Sell Orders</a></li>
                              -->
                                <li><a href="{{url('about-us')}}">About Us</a></li>
                              
                            </ul>
                        </div>
                        <!-- RD Navbar Search-->
                        <!--  <div class="rd-navbar-search">
                              <button class="rd-navbar-search-toggle rd-navbar-fixed-element-2"
                                      data-rd-navbar-toggle=".rd-navbar-search"><span></span></button>
                              <form class="rd-search" action="search-results.html"
                                    data-search-live="rd-search-results-live" method="GET">
                                  <div class="form-wrap">
                                      <label class="form-label" for="rd-navbar-search-form-input">Search</label>
                                      <input class="rd-navbar-search-form-input form-input"
                                             id="rd-navbar-search-form-input" type="text" name="s" autocomplete="off"/>
                                      <div class="rd-search-results-live" id="rd-search-results-live"></div>
                                  </div>
                                  <button class="rd-search-form-submit mdi mdi-magnify" type="submit"></button>
                              </form>
                          </div> -->
                    </div>
                </div>
            </nav>
        </div>
    </header>


    @yield('content')

    <footer class="section footer-classic bg-gray-700-invariable text-md-left">
        <div class="container">
            <h3>Support us on Patreon and join the game!</h3>

            <div class="row row-80">
                <div class="col-sm-6 col-lg-4">
                    <h4>Lieutenant</h4>
                    <p class="text-width-xl-90">Receive early updates on <b>game mechanics</b> when you support this project with at least $2 per month.</p>
                    <br/>
                    <a href="https://www.patreon.com/bePatron?u=6799833"
                       data-patreon-widget-type="become-patron-button">Become a Patron!</a>
                    <br/>
                </div>

                <div class="col-sm-6 col-lg-4">
                    <h4>Commander</h4>
                    <p class="text-width-xl-90">Be the first to learn about novel <b>training missions</b> when you contribute at least $4 a month.</p>
                    <br/>
                    <a href="https://www.patreon.com/bePatron?u=6799833"
                       data-patreon-widget-type="become-patron-button">Become a Patron!</a>
                    <br/>
                </div>

                <div class="col-sm-6 col-lg-4">
                    <h4>Admiral</h4>
                    <p class="text-width-xl-90">Enjoy behind-the-scenes access to our <b>development plans</b> when you help us grow at $6 or more a month.</p>
                    <br/>
                    <a href="https://www.patreon.com/bePatron?u=6799833"
                       data-patreon-widget-type="become-patron-button">Become a Patron!</a>

                </div>

                <p><b>Thank's for helping us succeed!</b></p>
            </div>

        </div>
        <div class="container">
            <!-- Rights-->
            <p class="rights"><span>©&nbsp; </span><span class="copyright-year">2019</span><span>&nbsp;</span><span>All rights reserved</span><span>.&nbsp;</span><!--<a
                        href="terms-of-use.html">Privacy Policy</a>--></p>
        </div>
    </footer>
</div>

<!-- Global Mailform Output-->
<div class="snackbars" id="form-output-global"></div>

<!-- Scripts -->
@vite('resources/assets/js/app.js')
{{-- Deferred so they run after the app bundle (a module script) has mounted
     Vue on #app; the theme sets up the navbar and other widgets on that DOM. --}}
<script src="/js/core.min.js" defer></script>
<script src="/js/script.js" defer></script>
<script async src="https://c6.patreon.com/becomePatronButton.bundle.js"></script>

</body>
</html>
