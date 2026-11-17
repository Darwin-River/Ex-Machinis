{{-- "This change did X" (#772): each recent push against the code before it --}}
@php($shown = array_values(array_filter($pushes['log'] ?? [], fn ($p) => $p['effect'] !== null)))
@if($shown)
    <div class="table-responsive" id="push-effects">
        <table class="table table-sm">
            <thead><tr><th>Push</th><th>Compared over</th><th class="text-right">Trading net a game day</th>
                <th class="text-right">Sales a game day</th><th class="text-right">Failed actions a game day</th><th>So</th></tr></thead>
            <tbody>
            @foreach($shown as $p)
                @php($e = $p['effect'])
                <tr><td>{{ $p['number'] }}, {{ $p['when'] }}</td>
                    @if($e['ready'])
                        <td>{{ $e['span_days'] }} game {{ $e['span_days'] == 1 ? 'day' : 'days' }} each side</td>
                        <td class="text-right">{{ number_format($e['before']['net']) }} → <b>{{ number_format($e['after']['net']) }}</b></td>
                        <td class="text-right">{{ number_format($e['before']['sales']) }} → <b>{{ number_format($e['after']['sales']) }}</b></td>
                        <td class="text-right">{{ $e['before']['failed'] }} → <b>{{ $e['after']['failed'] }}</b></td>
                        <td style="white-space: normal">{{ $e['verdict'] }}</td>
                    @else
                        <td colspan="5" style="white-space: normal">{{ ucfirst($e['note']) }}</td>
                    @endif
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <p><small>Each push against the code before it: the same span on each side of it (up to {{ \App\Services\CodePush::EFFECT_DAYS }} game days,
        never back past the push before), from its trades and its drones' actions. Prices and crowds move too, so a change shows best when it is large.</small></p>
@endif
