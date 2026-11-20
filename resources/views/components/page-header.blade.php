{{-- The band at the top of each page (#524): its title, an optional line
     under it, and the breadcrumb trail (the slot: <li> items after Home).
     Behind it, a space scene for the part of the site the page is in (#737),
     rendered by tools/render (render.py header) into html/images/headers/.
     tabs: a part of the site's tab bar under the title ('market') --}}
@props(['title', 'subtitle' => null, 'scene' => null, 'tabs' => null])
@php
    $scenes = [
        'market' => 'market', 'buys' => 'market', 'sells' => 'market', 'resource-orders' => 'market',
        'resources' => 'resources', 'technology' => 'resources',
        'spacecraft' => 'spacecraft', 'protocols' => 'spacecraft', 'lineage' => 'spacecraft', 'machine' => 'spacecraft',
        'locations' => 'locations', 'map' => 'locations',
        'companies' => 'companies', 'players' => 'companies', 'leaderboard' => 'companies', 'dashboard' => 'companies',
        'getting-started' => 'learn', 'manual' => 'learn', 'queries' => 'learn', 'release' => 'learn', 'events' => 'learn',
        'about-us' => 'about', 'support' => 'about', 'blog' => 'about',
        'account' => 'account', 'login' => 'account', 'signup' => 'account',
    ];
    $scene = $scene ?? ($scenes[request()->segment(1)] ?? 'default');
    $sceneImage = is_file(public_path("images/headers/$scene.webp")) ? url("images/headers/$scene.webp") : null;
@endphp
<section class="page-header{{ $sceneImage ? ' has-scene' : '' }}"
         @if ($sceneImage) data-scene="{{ $scene }}" style="--scene: url('{{ $sceneImage }}')" @endif>
    <div class="container">
        @if (trim($slot) !== '')
            <nav aria-label="Breadcrumb">
                <ol class="crumbs">
                    <li><a href="/">Home</a></li>
                    {{ $slot }}
                </ol>
            </nav>
        @endif
        <h1 class="page-title">{{ $title }}</h1>
        @if ($subtitle)
            <p class="page-subtitle">{{ $subtitle }}</p>
        @endif
        {{-- A part of the site's own tabs, in the header (#840: one header everywhere, tabs included) --}}
        @if ($tabs === 'market')
            <div class="page-tabs">@include('partials.market-tabs')</div>
        @endif
    </div>
</section>
