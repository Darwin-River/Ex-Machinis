{{--
    The economy at a glance (#698): the health banner and the tiles, on the
    home page and at the top of /dashboard. Takes $dash (Dashboard::compact()
    or ::full()) and $dashLink (whether to link to the whole dashboard).
--}}
@once
@push('styles')
    <style>
        .econ-banner { border-left: 4px solid #4cc9f0; background: rgba(76, 201, 240, 0.08); padding: 10px 14px; margin: 0 0 14px;
              border-radius: 0 6px 6px 0; white-space: normal; }
        .econ-banner.econ-warn { border-left-color: #ffd166; background: rgba(255, 209, 102, 0.1); }
        .econ-tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; margin: 0 0 12px; }
        .econ-tile { background: #111729; border: 1px solid rgba(255,255,255,0.1); border-radius: 8px; padding: 12px 14px; min-width: 0; }
        .econ-tile .econ-l { font-size: 12px; text-transform: uppercase; letter-spacing: .06em; color: #9aa3b8; }
        .econ-tile .econ-v { font-size: 1.6rem; font-weight: 700; font-variant-numeric: tabular-nums; line-height: 1.3; }
        .econ-tile .econ-d { font-size: 13px; color: #c3c9d8; white-space: normal; }
        .econ-down { color: #ff8a7a; }
        .econ-up { color: #5ad19a; }
        @media (max-width: 420px) { .econ-tiles { grid-template-columns: 1fr 1fr; gap: 8px; } .econ-tile .econ-v { font-size: 1.3rem; } }
    </style>
@endpush
@endonce
@php
    $t = $dash['tiles'];
    $b = $dash['banner'];
    $pct = function (?int $p) {
        if ($p === null) return '';
        return '<span class="' . ($p < 0 ? 'econ-down' : 'econ-up') . '">' . ($p > 0 ? '+' : ($p < 0 ? '−' : '')) . abs($p) . '%</span>';
    };
    $signed = fn (int $v) => ($v > 0 ? '+' : ($v < 0 ? '−' : '')) . number_format(abs($v));
@endphp
<div class="econ" id="economy">
    @php($stale = \App\Services\Dashboard::staleNote($dash['as_of_t'] ?? null))
    @if($stale)
        <div class="econ-banner econ-warn" id="economy-stale" role="status">{{ $stale }}</div>
    @endif
    <div @class(['econ-banner', 'econ-warn' => $b['quiet'] || $b['slowed']]) id="economy-banner" role="status">
        {{ $b['text'] }}
    </div>
    <div class="econ-tiles" id="economy-tiles">
        <div class="econ-tile"><div class="econ-l">Trades, {{ \App\Services\GameDate::ofDay((int) $b['day']) }} (last full day)</div>
            <div class="econ-v">{{ number_format($t['trades']['value']) }}</div>
            <div class="econ-d">@if($t['trades']['norm'] !== null){!! $pct($t['trades']['change_percent']) !!} on the norm ({{ number_format($t['trades']['norm']) }} a day)@else the first full day @endif</div></div>
        <div class="econ-tile"><div class="econ-l">Credits traded, {{ \App\Services\GameDate::ofDay((int) $b['day']) }}</div>
            <div class="econ-v">{{ number_format($t['credits']['value']) }}</div>
            <div class="econ-d">@if($t['credits']['norm'] !== null){!! $pct($t['credits']['change_percent']) !!} on the norm ({{ number_format($t['credits']['norm']) }} a day)@else&nbsp;@endif</div></div>
        <div class="econ-tile"><div class="econ-l">Working drones</div>
            <div class="econ-v">{{ number_format($t['drones']['working']) }}</div>
            <div class="econ-d">{{ number_format($t['drones']['wrecks']) }} wrecks ({{ round($t['drones']['wreck_share'] * 100) }}% of all)@if($t['drones']['change'] !== null);
                {{ $signed($t['drones']['change']) }} on 7 game days ago @endif</div></div>
        <div class="econ-tile"><div class="econ-l">Matured companies</div>
            <div class="econ-v">{{ number_format($t['matured']['count']) }}</div>
            <div class="econ-d">worth {{ number_format($t['matured']['cash_out']) }} cr at cash-out; {{ $signed($t['matured']['this_week']) }} matured in the last 7 game days</div></div>
        <div class="econ-tile"><div class="econ-l">Matured companies' profit, 7 days</div>
            <div class="econ-v {{ $t['profit']['week'] < 0 ? 'econ-down' : '' }}">{{ $signed($t['profit']['week']) }}</div>
            <div class="econ-d">{{ $signed($t['profit']['last_week']) }} the 7 days before</div></div>
        <div class="econ-tile"><div class="econ-l">Players</div>
            <div class="econ-v">{{ number_format($t['players']['count']) }}</div>
            <div class="econ-d">{{ $signed($t['players']['new_this_week']) }} joined in the last 7 real days</div></div>
    </div>
    @if($dashLink ?? false)
        <p><a href="{{ url('dashboard') }}">See the whole economy</a> &middot; <span class="text-muted">as of {{ $dash['as_of'] }} ({{ \App\Services\Dashboard::agoText($dash['as_of_t'] ?? null) }}), worked out again every few real minutes</span></p>
    @endif
</div>
