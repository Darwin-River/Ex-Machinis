{{-- The band at the top of each page (#524): its title, an optional line
     under it, and the breadcrumb trail (the slot: <li> items after Home) --}}
@props(['title', 'subtitle' => null])
<section class="page-header">
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
    </div>
</section>
