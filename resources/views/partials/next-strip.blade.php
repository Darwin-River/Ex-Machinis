{{-- The strip under the menu (#932): a logged-in player's next step on the path (#987), and the rules revealed in layers as each is earned --}}
@php($stripUser = auth()->user())
@if(config('forge.new_rules') && $stripUser && $stripUser->login !== null)
    @php($strip = \Illuminate\Support\Facades\Cache::remember('next-strip:' . $stripUser->user_id, 60, function () use ($stripUser) {
        $path = \App\Services\Pipeline::for($stripUser);
        return ['path' => $path, 'rules' => \App\Services\Pipeline::rules($path)];
    }))
    @php($nextKey = $strip['path']['next'])
    @php($next = $nextKey ? collect($strip['path']['steps'])->firstWhere('key', $nextKey) : null)
    @php($openRules = collect($strip['rules'])->where('open', true))
    @php($lockedLayer = collect($strip['rules'])->firstWhere('open', false))
    <div class="next-strip" id="next-strip" role="region" aria-label="Your next step" style="background: #0f1424; border-bottom: 1px solid rgba(255,255,255,0.1); padding: 5px 0; font-size: 0.9rem">
        <div class="container" style="display: flex; flex-wrap: wrap; align-items: center; gap: 4px 14px">
            @if($next)
                <span id="next-step"><b style="color: #4cc9f0">Next:</b>
                    @switch($nextKey)
                        @case('code') mature a startup into a company of your own. @break
                        @case('invest') buy a share in another player's company, or sell one of yours to go public. @break
                        @case('lend') back a company's loan request. @break
                        @default {{ $next['title'] }}.
                    @endswitch
                    <a href="{{ $next['url'] }}">{{ $next['action'] }} &rarr;</a></span>
                @if($next['progress'] && $nextKey === 'lend') {{-- a startup's bar is in the strip above (#770) --}}
                    <span style="display: inline-flex; align-items: center; gap: 6px; color: #9aa3b8">
                        <span role="progressbar" aria-valuemin="0" aria-valuemax="{{ $next['progress']['of'] }}" aria-valuenow="{{ $next['progress']['now'] }}" aria-label="{{ $next['progress']['label'] }}"
                              style="display: inline-block; width: 110px; height: 8px; background: #263052; border-radius: 99px; overflow: hidden"><i style="display: block; height: 100%; width: {{ round(100 * $next['progress']['now'] / max(1, $next['progress']['of'])) }}%; background: #4cc9f0"></i></span>
                        {{ $next['progress']['label'] }}</span>
                @endif
            @endif
            <details id="rules-so-far" style="display: inline-block">
                <summary style="cursor: pointer; color: #c3c9d8">The rules so far ({{ $openRules->sum(fn ($l) => count($l['rules'])) }} of {{ collect($strip['rules'])->sum(fn ($l) => count($l['rules'])) }})</summary>
                <div style="padding: 6px 0 4px; white-space: normal">
                    @foreach($openRules as $layer)
                        <b>{{ $layer['title'] }}</b>
                        <ul class="mb-1" style="padding-left: 1.2rem; list-style: disc">
                            @foreach($layer['rules'] as $rule)<li>{{ $rule }}</li>@endforeach
                        </ul>
                    @endforeach
                    @if($lockedLayer)<p class="mb-0" style="color: #9aa3b8">More rules appear here {{ $lockedLayer['unlock'] }}: {{ strtolower($lockedLayer['title']) }}. All of them are in the <a href="{{ url('manual') }}">manual</a>.</p>@endif
                </div>
            </details>
        </div>
    </div>
@endif
