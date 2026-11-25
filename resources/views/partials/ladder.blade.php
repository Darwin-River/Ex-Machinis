{{-- The Player Ladder's progress line (#1043, part of #1039): the eight steps as short bars, done, here and ahead, with this
     page's step named. $here: the step this page is; $path (optional): Pipeline::for() already worked out --}}
@php($path = $path ?? \App\Services\Pipeline::for(auth()->user()))
@php($here = $here ?? null)
@php($hereStep = $here ? \App\Services\Pipeline::step($path, $here) : null)
@once
@push('styles')
<style>
    .ladder { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 12px; margin: 0 0 1.25rem; }
    .ladder ol { display: flex; gap: 4px; margin: 0; padding: 0; list-style: none; }
    .ladder li > a, .ladder li > span { display: block; width: 26px; height: 6px; border-radius: 3px; background: #263052; }
    .ladder li.is-done > a, .ladder li.is-done > span { background: #7ee2a8; }
    .ladder li.is-here > a, .ladder li.is-here > span { background: #4cc9f0; width: 40px; }
    .ladder li a:focus-visible { outline: 2px solid #ffb547; outline-offset: 2px; }
    .ladder-label { color: #9aa3b8; font-size: 14px; }
    .ladder-label b { color: #c3c9d8; }
    @media (max-width: 480px) { .ladder li > a, .ladder li > span { width: 18px; } .ladder li.is-here > a, .ladder li.is-here > span { width: 28px; } }
</style>
@endpush
@endonce
<nav class="ladder" id="ladder" aria-label="Your path through the game">
    <ol>
        @foreach($path['steps'] as $s)
            @php($cls = $here === $s['key'] ? 'is-here' : ($s['done'] ? 'is-done' : ''))
            <li class="{{ $cls }}" id="ladder-{{ $s['key'] }}">
                @if($here === $s['key'])
                    <span aria-current="step" title="Step {{ $s['n'] }}: {{ $s['title'] }} (you are here)"></span>
                @else
                    <a href="{{ $s['url'] }}" title="Step {{ $s['n'] }}: {{ $s['title'] }}{{ $s['done'] ? ' (done)' : '' }}"><span class="sr-only">Step {{ $s['n'] }}: {{ $s['title'] }}{{ $s['done'] ? ', done' : '' }}</span></a>
                @endif
            </li>
        @endforeach
    </ol>
    <span class="ladder-label">@if($hereStep)Step {{ $hereStep['n'] }} of {{ count($path['steps']) - 1 }}: <b>{{ $hereStep['title'] }}</b>@else{{ count(array_filter(array_column($path['steps'], 'done'))) }} of {{ count($path['steps']) }} steps done @endif</span>
</nav>
