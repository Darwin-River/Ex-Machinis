<!DOCTYPE html>
<html class="wide wow-animation" lang="{{ app()->getLocale() }}">
<head>
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
                                <li class="rd-nav-item "><a class="rd-nav-link " href="#"><span
                                                class="rd-nav-icon mdi mdi-account"></span><span
                                                class="rd-nav-title">User</span></a>
                                    <!-- RD Navbar Dropdown-->
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
                                </li>

                                <li class="rd-nav-item d-none d-lg-block"><a class="rd-nav-link "
                                                                             href="{{'astronomical-objects'}}"><span
                                                class="rd-nav-icon mdi mdi-database-search"></span><span
                                                class="rd-nav-title">Data</span></a>
                                    <!-- RD Navbar Dropdown-->
                                    <ul class="rd-menu rd-navbar-dropdown">
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('astronomical-objects')}}">Astronomical
                                                Objects</a></li>
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('spacecraft')}}">Spacecraft
                                                Search</a></li>
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('company')}}">Company Search</a>
                                        </li>
                                    </ul>
                                </li>
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
                                                                        href="{{url('forth-dictionary')}}">FORTH
                                                Dictionary</a></li>
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('protocols')}}">Protocols</a></li>
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('query-commands')}}">Query
                                                Commands</a></li>
                                    </ul>
                                </li>
                                <!--  <li class="rd-nav-item active"><a class="rd-nav-link" href="contacts.html"><span
                                                  class="rd-nav-icon mdi mdi-email"></span><span class="rd-nav-title">Contacts</span></a>
                                  </li> -->
                            </ul>

                            <!-- MAIN MENU -->
                            <ul class="rd-navbar-list-link">

                                <li class="d-none d-lg-inline-block"><a class="" href="{{url('astronomical-objects')}}">Data</a><span
                                            class="rd-navbar-submenu-toggle"></span>
                                    <!-- RD Navbar Dropdown-->
                                    <ul class="rd-menu rd-navbar-dropdown oriented-down">
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('astronomical-objects')}}">Astronomical
                                                Objects</a></li>
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('spacecraft')}}">Spacecraft
                                                Search</a></li>
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('company')}}">Company
                                                Search</a></li>
                                    </ul>
                                </li>
                                <li class="d-none d-lg-inline-block"><a class="" href="">Documentation</a><span
                                            class="rd-navbar-submenu-toggle"></span>
                                    <!-- RD Navbar Dropdown-->
                                    <ul class="rd-menu rd-navbar-dropdown oriented-down">
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('players-manual')}}">Player's
                                                Manual</a></li>
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('forth-dictionary')}}">FORTH
                                                Dictionary</a></li>
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('protocols')}}">Protocols</a></li>
                                        <li class="rd-dropdown-item"><a class="rd-dropdown-link"
                                                                        href="{{url('query-commands')}}">Query
                                                Commands</a></li>
                                    </ul>
                                </li>
                                <li class="d-block d-lg-none"><a href="{{url('astronomical-objects')}}">Astronomical
                                        Objects</a></li>
                                <li class="d-block d-lg-none"><a href="{{url('players-manual')}}">Player's Manual</a>
                                </li>
                                <li class="d-block d-lg-none"><a href="{{url('forth-dictionary')}}">FORTH Dictionary</a>
                                </li>
                                <li class="d-block d-lg-none"><a href="{{url('spacecraft')}}">Spacecraft Search</a></li>
                                <li class="d-block d-lg-none"><a href="{{url('protocols')}}">Protocols</a></li>
                                <li class="d-block d-lg-none"><a href="{{url('query-commands')}}">Query Commands</a>
                                </li>
                                <li class="d-block d-lg-none"><a href="{{url('company')}}">Company Search</a></li>


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
                                @endauth
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
            <div class="row row-40">
                <div class="col-sm-6 col-lg-4">
                    <h4>About the Game</h4>
                    <p class="text-width-xl-90">Ex Machinis is a multiplayer space game in which players program fleets
                        of remotely-piloted spacecraft to trade and manufacture goods and materials.</p>
                </div>
                <!--<div class="col-sm-6 col-lg-2">
                    <h4>Quick Links</h4>
                    <ul class="footer-classic-list-link">
                        <li><a href="#">News</a></li>
                        <li><a href="#">Documentary</a></li>
                        <li><a href="#">Sighting</a></li>
                        <li><a href="#">Incidents</a></li>
                        <li><a href="#">ISS Tracking</a></li>
                        <li><a href="#">Researching</a></li>
                    </ul>
                </div>-->
                <div class="col-sm-6 col-lg-4">
                    <h4>Help Us Succeed</h4>
                    <p>Support us in Patreon:<br/>
                        <a href="https://www.patreon.com/exmachinis">https://www.patreon.com/exmachinis</a>
                    </p>
                    <div class="row row-20">
                        <div class="col-6 col-sm-12 align-self-center">
                            <ul class="list-inline list-social inset-lg-top-10">
                                <li><a class="icon mdi mdi-twitter" href="https://twitter.com/exmachinis"></a></li>
                                <li><a class="icon mdi mdi-patreon"
                                       href="https://www.patreon.com/exmachinis"></a></li>
                                <li><a class="icon mdi mdi-github-circle"
                                       href="https://github.com/Darwin-River/Ex-Machinis"></a></li>
                            </ul>
                        </div>
                    </div>
                </div>
                <div class="col-sm-12 col-lg-4">
                    <h4>Join the game</h4>
                    <p>Sign up now submitting your e-mail to receive access to your own spacecraft.<br/>
                        <small>Or else send a mail to <a href="mailto:registrar@advolition.com?subject=register">registrar@advolition.com</a>
                            with <b>register</b> as subject.</small></p>
                    <form class="rd-form rd-mailform form-sm" data-form-output="form-output-global"
                          data-form-type="subscribe" method="post" action="/web-register" novalidate="novalidate">
                        <div class="form-wrap">
                            <input class="form-input form-control-has-validation" id="subscribe-form-2-name"
                                   type="text" name="name" data-constraints="@Required"><span
                                    class="form-validation"></span>
                            <label class="form-label rd-input-label" for="subscribe-form-2-name">User Name</label>
                        </div>
                        <div class="form-wrap mt-2">
                            <input class="form-input form-control-has-validation" id="subscribe-form-2-email"
                                   type="email" name="email" data-constraints="@Email @Required"><span
                                    class="form-validation"></span>
                            <label class="form-label rd-input-label" for="subscribe-form-2-email">E-mail</label>
                        </div>
                        <button class="button button-sm button-primary" type="submit">Submit</button>
                    </form>
                </div>
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
<script>
    $(document).ready(function () {

    })
</script>
</body>
</html>
