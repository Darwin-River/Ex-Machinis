{{-- The player's path (#987): account, code drones, invest, lend. $here (optional): the step this page is --}}
@php($path = $path ?? \App\Services\Pipeline::for(auth()->user()))
@php($here = $here ?? null)
{{-- $skip (optional): steps not shown here, by key (#1058: the home page, where the visitor is already watching, leaves out step 0) --}}
@php($skip = $skip ?? [])
@once
@push('styles')
<style>
    .pipeline { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin: 0 0 2rem; padding: 0; list-style: none; counter-reset: none; }
    .pipeline-step { position: relative; background: #111729; border: 1px solid rgba(255,255,255,0.1); border-radius: 10px; padding: 14px 16px; display: flex; flex-direction: column; }
    .pipeline-step.is-next { border-color: #4cc9f0; box-shadow: 0 0 0 1px #4cc9f0 inset; }
    .pipeline-step.is-here { border-color: #ffb547; }
    .pipeline-step .n { color: #4cc9f0; font-weight: 700; font-size: 12px; letter-spacing: .1em; text-transform: uppercase; }
    .pipeline-step.is-done .n { color: #7ee2a8; }
    .pipeline-step h2, .pipeline-step h3 { font-size: 1.1rem; margin: 4px 0 6px; }
    .pipeline-step p { margin: 0 0 8px; color: #c3c9d8; font-size: 14.5px; white-space: normal; text-align: left; }
    .pipeline-step .status { color: #7ee2a8; font-size: 13.5px; }
    .pipeline-step .go { margin-top: auto; font-weight: 600; font-size: 14.5px; }
    .pipeline-bar { height: 6px; background: rgba(255,255,255,0.1); border-radius: 3px; overflow: hidden; margin: 2px 0 4px; }
    .pipeline-bar span { display: block; height: 100%; background: #4cc9f0; }
    .pipeline-bar + small { display: block; color: #9aa3b8; font-size: 12.5px; margin-bottom: 8px; }
    @media (max-width: 991px) { .pipeline { grid-template-columns: 1fr 1fr; } }
    @media (max-width: 480px) { .pipeline { grid-template-columns: 1fr; } }
</style>
@endpush
@endonce
<ol class="pipeline" id="pipeline" aria-label="Your path through the game">
    @foreach(array_filter($path['steps'], fn ($st) => !in_array($st['key'], $skip, true)) as $s)
        @php($cls = trim(($s['done'] && $path['player'] ? 'is-done ' : '') . ($path['next'] === $s['key'] ? 'is-next ' : '') . ($here === $s['key'] ? 'is-here' : '')))
        <li class="pipeline-step {{ $cls }}" id="pipeline-{{ $s['key'] }}" @if($here === $s['key']) aria-current="step" @endif>
            <div class="n">Step {{ $s['n'] }}@if($s['done'] && $path['player']) &middot; done @elseif($path['next'] === $s['key']) &middot; next @endif @if($here === $s['key']) &middot; you are here @endif</div>
            <h3>{{ $s['title'] }}</h3>
            <p>{{ $s['text'] }}</p>
            @if($s['status'])<p class="status">{{ $s['status'] }}</p>@endif
            @if($s['progress'])
                <div class="pipeline-bar" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $s['progress']['of'] }}" aria-valuenow="{{ $s['progress']['now'] }}"
                     aria-label="{{ $s['progress']['label'] }}"><span style="width: {{ $s['progress']['of'] > 0 ? round(100 * $s['progress']['now'] / $s['progress']['of']) : 0 }}%"></span></div>
                <small>{{ $s['progress']['label'] }}</small>
            @endif
            @if($here !== $s['key'])<a class="go" href="{{ $s['url'] }}">{{ $s['action'] }} &rarr;</a>@endif
        </li>
    @endforeach
</ol>
