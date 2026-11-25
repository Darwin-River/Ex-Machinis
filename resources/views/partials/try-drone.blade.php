{{-- A drone to watch, change, test drive and keep (#1042, part of #1039), on the home page and /try.
     $script, $starter; $replay (optional): the fixed replay (Replay::of); $drive (optional): a guest test drive's result --}}
@php($replay = $replay ?? null)
@php($drive = $drive ?? null)
@include('partials.lua-editor')
@once
@push('styles')
<style>
    .try-drone { display: grid; grid-template-columns: minmax(0, 7fr) minmax(0, 5fr); gap: 16px; align-items: start; }
    .try-drone .try-code textarea { font: 13px/1.55 "JetBrains Mono", ui-monospace, monospace; min-height: 18rem; }
    /* A starter runs to hundreds of lines: the editor scrolls inside, so Test drive stays in sight */
    .try-drone .try-code .cm-editor { height: 24rem; }
    .try-drone .try-code { min-width: 0; }
    @media (max-width: 575px) { .try-drone .try-code .cm-editor { height: 18rem; } }
    .try-panel { background: #0f1424; border: 1px solid #263052; border-radius: 10px; padding: 12px 14px; white-space: normal; }
    .try-panel h3 { font-size: 1rem; margin: 0 0 6px; }
    .try-lines { list-style: none; margin: 0; padding: 0; font: 12.5px/1.6 "JetBrains Mono", ui-monospace, monospace; color: #c3c9d8; max-height: 22rem; overflow-y: auto; }
    .try-lines li { display: grid; grid-template-columns: 3.6em 1fr; gap: 8px; }
    .try-lines li > span:first-child { color: #9aa3b8; text-align: right; font-variant-numeric: tabular-nums; }
    .try-lines li.is-refused { color: #f0b429; }
    .try-actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-top: 10px; }
    .try-note { color: #9aa3b8; font-size: 13.5px; margin: 6px 0 0; }
    @media (prefers-reduced-motion: no-preference) {
        .try-lines.is-playing li { opacity: 0; transform: translateY(4px); animation: try-in .35s ease forwards; }
        @keyframes try-in { to { opacity: 1; transform: none; } }
    }
    @media (max-width: 991px) { .try-drone { grid-template-columns: 1fr; } }
</style>
@endpush
@endonce
<form method="POST" action="{{ url('try') }}" class="try-drone" id="try-drone">
    {{ csrf_field() }}
    <input type="hidden" name="starter" value="{{ $starter }}">
    <div class="try-code">
        <label for="try-script" class="sr-only">Your drone's Lua code</label>
        <textarea v-pre class="form-control" id="try-script" name="script" rows="16" spellcheck="false" data-lua-editor
                  aria-describedby="try-hint">{{ $script }}</textarea>
        <p class="try-note" id="try-hint">Change a setting at the top (the one marked <code>TRY FIRST</code> is a good start), or choose a route below, then press <b>Test drive</b>.</p>
        @if($errors->has('script'))<p class="text-danger mb-0" role="alert" v-pre>{{ $errors->first('script') }}</p>@endif
        <div class="try-actions">
            <button class="button button-primary" type="submit" id="try-run">Test drive</button>
            <button class="button button-secondary" type="submit" formaction="{{ url('try/keep') }}" id="try-keep">Keep my drone &rarr;</button>
        </div>
    </div>
    <div class="try-panel" id="result" aria-live="polite">
        @if($drive)
            <h3>Your test drive</h3>
            <p class="try-note" style="margin: 0 0 6px">As a new startup's drone, from {{ $drive['place'] }}, against the world as it is now. Nothing was bought, sold or saved. Times are game hours:minutes.</p>
            @if($drive['lines'])
                {{-- Each line in order: an action starts with its game time; what it would say or set as its status has none --}}
                <ol class="try-lines" v-pre>
                    @foreach($drive['lines'] as $line)
                        @php([$at, $text] = preg_match('/^(\d+:\d\d) (.*)$/s', $line, $m) ? [$m[1], $m[2]] : ['', $line])
                        <li class="{{ str_contains($text, ': refused (') ? 'is-refused' : '' }}"><span>{{ $at }}</span><span>{{ $text }}</span></li>
                    @endforeach
                </ol>
            @else
                <p class="mb-1">It would do nothing.</p>
            @endif
            @if($drive['error'])<p class="mb-1 mt-2" v-pre><b>It would crash:</b> {{ $drive['error'] }}. That's how you learn: fix the line and test drive it again.</p>@endif
            <p class="try-note">It stopped because {{ $drive['why'] }}. Like it? <b>Keep my drone</b> saves it, and the next page starts it for real.</p>
        @elseif($replay)
            <h3 id="replay">What it did</h3>
            <p class="try-note" style="margin: 0 0 6px">This code, test driven as a new startup's drone from {{ $replay['place'] }} (recorded {{ \Carbon\Carbon::parse($replay['recorded'])->format('M j, Y') }}). Times are game hours:minutes.</p>
            <ol class="try-lines is-playing" id="replay-lines" v-pre>
                @foreach($replay['lines'] as $i => $line)
                    <li style="animation-delay: {{ number_format($i * 0.45, 2) }}s"><span>{{ $line['at'] }}</span><span>{{ $line['text'] }}</span></li>
                @endforeach
            </ol>
            <p class="try-note">Your turn: change it, and see what your version would do.</p>
        @else
            <h3 id="replay">What would it do?</h3>
            <p class="mb-0">Press <b>Test drive</b>: your drone runs this code against the live world as a new startup's drone would,
                and you see each trip, dig and sale it would make. Nothing is bought, sold or saved.</p>
        @endif
    </div>
</form>
