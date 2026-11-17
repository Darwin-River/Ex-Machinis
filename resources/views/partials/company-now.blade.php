{{-- "Your company now" (#770): each live seedling's progress to maturing, a forecast, and a Mature button; matured companies about to dissolve --}}
@php($now = auth()->check() ? \App\Services\CompanyNow::for(auth()->user()) : null)
@if($now)
    <div class="company-now" id="company-now" role="region" aria-label="Your companies now" style="background: #111729; border-bottom: 1px solid rgba(255,255,255,0.1); padding: 6px 0; font-size: 0.9rem">
        <div class="container">
            @foreach($now['seedlings'] as $s)
                <div class="cn-row" style="display: flex; flex-wrap: wrap; align-items: center; gap: 4px 10px; margin: 2px 0">
                    <a href="{{ url('companies/' . $s['company']) }}" class="cn-name">{{ $s['name'] }}</a>
                    <span class="cn-bar" aria-hidden="true" style="display: inline-block; width: 120px; height: 8px; background: #263052; border-radius: 99px; overflow: hidden"><i style="display: block; height: 100%; width: {{ $s['percent'] }}%; background: #5ad19a"></i></span>
                    <span class="cn-figs">{{ number_format($s['credits']) }} / {{ number_format($s['need']) }}</span>
                    @if($s['can_mature'])
                        <form method="POST" action="{{ url('seedlings/' . $s['company'] . '/mature') }}" class="d-inline">
                            {{ csrf_field() }}<button class="button button-primary button-sm cn-mature" type="submit">Mature</button>
                        </form>
                        @if($s['at_cap'])<span class="cn-warn" style="color: #f0b429">at its cap: what it earns now goes to the bank</span>@endif
                    @elseif($s['minutes'] !== null)
                        <span class="cn-eta" style="color: #9aa3b8">matures in about {{ $s['minutes'] < 90 ? $s['minutes'] . ' real min' : round($s['minutes'] / 60, 1) . ' real hours' }}</span>
                    @else
                        <span class="cn-eta" style="color: #9aa3b8">not earning yet</span>
                    @endif
                </div>
            @endforeach
            @foreach($now['alerts'] as $a)
                <div class="cn-row" style="display: flex; flex-wrap: wrap; align-items: center; gap: 4px 10px; margin: 2px 0"><a href="{{ url('companies/' . $a['company']) }}" class="cn-name">{{ $a['name'] }}</a>
                    <span class="cn-warn" style="color: #f0b429">{{ $a['text'] }}</span></div>
            @endforeach
        </div>
    </div>
@endif
