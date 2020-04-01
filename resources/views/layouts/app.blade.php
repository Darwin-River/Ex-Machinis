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
    <link href="{{ mix('css/app.css') }}?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/css/app.css') ?>"
          rel="stylesheet">


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
                                <li class="rd-nav-item"><a class="rd-nav-link" href="/"><span
                                                class="rd-nav-icon mdi mdi-home"></span><span
                                                class="rd-nav-title">Home</span></a>
                                    <!-- RD Navbar Dropdown-->
                                </li>

                            <!-- Hide login menu for now.
                                <li class="rd-nav-item "><a class="rd-nav-link " href="#"><span
                                                class="rd-nav-icon mdi mdi-account"></span><span
                                                class="rd-nav-title">User</span></a>

                                    <ul class="rd-menu rd-navbar-dropdown">
                                        @guest
                                <li class="rd-dropdown-item"><a
                                            href="{{url('login')}}">Login</a></li>
                                        @endguest
                            @auth
                                <h4 class="rd-megamenu-title">{{Auth::user()->name}}</h4>
                                            <li class="rd-dropdown-item">
                                                <a href="{{ route('logout') }}"
                                                   onclick="event.preventDefault();
                                                     document.getElementById('logout-form').submit();">
                                                    Logout
                                                </a>
                                            </li>
                                        @endauth
                                    </ul>
                                </li> -->


                                <li class="rd-nav-item d-none d-lg-block"><a class="rd-nav-link "
                                                                             href="{{url('players-manual')}}"><span
                                                class="rd-nav-icon mdi mdi-dictionary"></span><span
                                                class="rd-nav-title">Documentation</span></a>
                                    <!-- RD Navbar Dropdown-->
                                    <ul class="rd-menu rd-navbar-dropdown">
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('players-manual')}}">Player's
                                                Manual</a></li>
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
                                                                             href="{{'objects'}}"><span
                                                class="rd-nav-icon mdi mdi-database-search"></span><span
                                                class="rd-nav-title">Data</span></a>
                                    <!-- RD Navbar Dropdown-->
                                    <ul class="rd-menu rd-navbar-dropdown">
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('objects')}}">Space Objects</a></li>
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('companies')}}">Companies</a></li>
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('spacecraft')}}">Spacecraft</a></li>

                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('resources')}}">Resources</a></li>
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('events')}}">Events</a></li>
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('buys')}}">Buy Orders</a></li>
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('sells')}}">Sell Orders</a></li>
                                    </ul>
                                </li>
                                <!--  <li class="rd-nav-item active"><a class="rd-nav-link" href="contacts.html"><span
                                                  class="rd-nav-icon mdi mdi-email"></span><span class="rd-nav-title">Contacts</span></a>
                                  </li> -->
                            </ul>

                            <!-- MAIN MENU -->
                            <ul class="rd-navbar-list-link">


                                <li class="d-none d-lg-inline-block"><a class="" href="">Documentation</a><span
                                            class="rd-navbar-submenu-toggle"></span>
                                    <!-- RD Navbar Dropdown-->
                                    <ul class="rd-menu rd-navbar-dropdown oriented-down">
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('players-manual')}}">Player's
                                                Manual</a></li>
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
                                <li class="d-none d-lg-inline-block"><a class="" href="{{url('objects')}}">Data</a><span
                                            class="rd-navbar-submenu-toggle"></span>
                                    <!-- RD Navbar Dropdown-->
                                    <ul class="rd-menu rd-navbar-dropdown oriented-down">
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('objects')}}">Space
                                                Objects</a></li>
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('companies')}}">Companies</a></li>
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('spacecraft')}}">Spacecraft</a></li>
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('resources')}}">Resources</a></li>
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('events')}}">Events</a></li>
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('buys')}}">Buy Orders</a></li>
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('sells')}}">Sell Orders</a></li>
                                    </ul>
                                </li>

                                <li class="d-block d-lg-none"><a href="{{url('players-manual')}}">Player's Manual</a>
                                </li>
                                <li class="d-block d-lg-none"><a href="{{url('protocols')}}">Protocols</a></li>
                                <li class="d-block d-lg-none"><a href="{{url('queries')}}">Queries</a></li>
                            <!-- Hide for now.
                                <li class="d-block d-lg-none"><a href="{{url('forth-dictionary')}}">FORTH Dictionary</a></li>
                                -->
                                <li class="d-block d-lg-none"><a href="{{url('objects')}}">Space
                                        Objects</a></li>
                                <li class="d-block d-lg-none"><a href="{{url('companies')}}">Companies</a></li>
                                <li class="d-block d-lg-none"><a href="{{url('spacecraft')}}">Spacecraft</a></li>
                                <li class="d-block d-lg-none"><a href="{{url('resources')}}">Resources</a></li>
                                <li class="d-block d-lg-none"><a href="{{url('events')}}">Events</a></li>
                                <li class="d-block d-lg-none"><a href="{{url('buys')}}">Buy Orders</a></li>
                                <li class="d-block d-lg-none"><a href="{{url('sells')}}">Sell Orders</a></li>
                                <li><a href="{{url('about-us')}}">About Us</a></li>
                                <li><a href="{{url('register')}}" class="text-warning">Join the Game</a></li>
                            <!-- Hide login prompt for now.
                                @guest
                                <li class="float-lg-right"><a href="{{url('login')}}">Login</a></li>
                                @endguest
                            @auth
                                <li class="float-lg-right">
                                    <a href="{{ route('logout') }}"
                                           onclick="event.preventDefault();
                                                     document.getElementById('logout-form').submit();">
                                            Logout
                                        </a>
                                    </li>
                                    <form id="logout-form" action="{{ route('logout') }}" method="POST"
                                          style="display: none;">
                                        {{ csrf_field() }}
                                        </form>
@endauth -->
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
            <h3>Support us on Patreon and enjoy novel content.</h3>
            <div class="row row-80">
                <div class="col-sm-6 col-lg-4">
                    <h4>Lieutenant</h4>
                    <p class="text-width-xl-90">Receive exclusive access to the regularly updated <b>Player's Manual</b> when you support this project at $2 per month.</p>
                    <br/>
                    <a href="https://www.patreon.com/bePatron?u=6799833"
                       data-patreon-widget-type="become-patron-button">Become a Patron!</a>
                    <br/>
                </div>

                <div class="col-sm-6 col-lg-4">
                    <h4>Commander</h4>
                    <p class="text-width-xl-90">Benefit from advanced <b>game-play tips</b> and <b>programming
                            tutorials</b> when you contribute at least $4 a month.</p>
                    <br/>
                    <a href="https://www.patreon.com/bePatron?u=6799833"
                       data-patreon-widget-type="become-patron-button">Become a Patron!</a>
                    <br/>
                </div>

                <div class="col-sm-6 col-lg-4">
                    <h4>Admiral</h4>
                    <p class="text-width-xl-90">Enjoy <b>behind-the-scenes access</b> to our development plans and
                        visions when you help us grow at $6 a month.</p>
                    <br/>
                    <a href="https://www.patreon.com/bePatron?u=6799833"
                       data-patreon-widget-type="become-patron-button">Become a Patron!</a>

                </div>
                <!--
                <div class="col-sm-12 col-lg-4">
                    <h4>Join the game</h4>

                    <p>Submit your e-mail to receive access to your own spacecraft.<br/>
                        <small>Or else send a mail to <a href="mailto:registrar@advolition.com?subject=register">registrar@advolition.com</a>
                            with <b>register</b> as subject.</small></p>

                    <form class="rd-form rd-mailform form-sm" data-form-output="form-output-global"
                          data-form-type="subscribe" method="post" action="/web-register" novalidate="novalidate">

                        <div class="form-wrap mt-2">
                            <input class="form-input form-control-has-validation" id="subscribe-form-2-email"
                                   type="email" name="email" data-constraints="@Email @Required"><span
                                    class="form-validation"></span>
                            <label class="form-label rd-input-label" for="subscribe-form-2-email">E-mail</label>
                        </div>
                        <div class="form-wrap">
                            <input class="form-input form-control-has-validation" id="subscribe-form-2-name"
                                   type="text" name="name" data-constraints="@Required"><span
                                    class="form-validation"></span>
                            <label class="form-label rd-input-label" for="subscribe-form-2-name">A name for your
                                company</label>
                        </div>
                        <br/>
                        <button class="button button-sm button-primary" type="submit">Submit</button>
                    </form>
                </div>
-->
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
<script src="{{ mix('/js/app.js') }}?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/js/app.js') ?>"></script>
<script src="/js/core.min.js"></script>
<script src="/js/script.js"></script>
<script async src="https://c6.patreon.com/becomePatronButton.bundle.js"></script>
<script>
    $(document).ready(function () {

    })
</script>

</body>
</html>
