{{-- Badges (#1048): recognition only, earned from the game's records. $badges: Badges::of(); $title (optional);
     $id: the list's id. Each shows its name, its tier, how it is earned and when --}}
@php($title = $title ?? 'Badges')
@once
@push('styles')
<style>
    .badges { display: flex; flex-wrap: wrap; gap: 8px; margin: 0 0 1.25rem; padding: 0; list-style: none; }
    .badge-chip { display: inline-flex; align-items: center; gap: 6px; background: #111729; border: 1px solid #263052; border-radius: 999px;
        padding: 4px 12px 4px 6px; font-size: 14px; color: #e6e9f2; }
    .badge-chip i { width: 18px; height: 18px; border-radius: 50%; display: inline-block; flex: none;
        background: radial-gradient(circle at 35% 35%, #ffd27a, #c98500); box-shadow: inset 0 0 0 2px rgba(0,0,0,.25); }
    .badge-chip.tier-bronze i { background: radial-gradient(circle at 35% 35%, #f0b07a, #9c5a2a); }
    .badge-chip.tier-silver i { background: radial-gradient(circle at 35% 35%, #f2f4f8, #8a93ad); }
    .badge-chip.tier-gold i { background: radial-gradient(circle at 35% 35%, #ffe9a3, #d9a400); }
    .badge-chip small { color: #9aa3b8; }
</style>
@endpush
@endonce
@if($badges)
    <h2 class="h5 mb-2" id="{{ $id }}-title">{{ $title }} <small style="color: #9aa3b8">({{ count($badges) }})</small></h2>
    <ul class="badges" id="{{ $id }}" aria-labelledby="{{ $id }}-title">
        @foreach($badges as $b)
            <li class="badge-chip {{ $b['tier_name'] ? 'tier-' . $b['tier_name'] : '' }}" id="badge-{{ $b['key'] }}"
                title="{{ $b['text'] }}{{ $b['threshold'] ? ' (' . number_format($b['threshold']) . ' ' . $b['unit'] . ')' : '' }} Earned {{ $b['when'] }}.">
                <i aria-hidden="true"></i><span>{{ $b['name'] }}@if($b['tier_name']) <small>{{ $b['tier_name'] }}</small>@endif</span>
            </li>
        @endforeach
    </ul>
@endif
