{{-- "Since you last looked" (#771): what changed for you, the share moves, and the action failing most with a hint --}}
@php($card = auth()->check() ? \App\Services\SinceLooked::for(auth()->user()) : null)
@if($card)
    <div class="alert alert-info" id="since-looked" style="white-space: normal">
        <b>Since you last looked</b> ({{ $card['since'] }}{{ $card['capped'] ? ', the last ' . \App\Services\PlayerBoards::DIGEST_MAX_DAYS . ' game days' : '' }}):
        <ul class="mb-1">
            @foreach($card['companies'] as $c)
                <li><a href="{{ url('companies/' . $c['company_id']) }}">{{ $c['name'] }}</a>{{ $c['seedling'] ? ' (seedling)' : '' }}
                    sold {{ number_format($c['sales']) }} and bought {{ number_format($c['purchases']) }} credits' worth
                    @if(isset($c['share_change']) && $c['share_change'] !== null); its shares are worth {{ number_format($c['share_now']) }},
                        {{ $c['share_change'] >= 0 ? 'up' : 'down' }} {{ abs($c['share_change']) }}%@endif
                    @if(!empty($c['to_bank'])); it gave the bank {{ number_format($c['to_bank']) }} over its cap @endif
                    @if(($c['ended'] ?? null) === 'closed'). You closed it @elseif($c['ended'] ?? null). It has ended @endif.</li>
            @endforeach
            @if($card['dividends'])<li>Your shares paid you {{ number_format($card['dividends']) }} credits in dividends.</li>@endif
            @if($card['bank_paid'])<li>The bank paid you {{ number_format($card['bank_paid']) }} credits for shares you sold it.</li>@endif
            @foreach($card['share_fills'] as $f)
                <li>You {{ $f['side'] }} {{ number_format($f['units']) }} {{ $f['name'] }} shares at {{ number_format($f['price']) }}
                    {{ $f['side'] === 'sold' ? 'to' : 'from' }} {{ $f['with'] }}.</li>
            @endforeach
            @if($card['failing'])
                <li><strong>To fix:</strong> <a href="{{ url('companies/' . $card['failing']['company_id']) }}#health">{{ $card['failing']['name'] }}</a>:
                    {{ $card['failing']['what'] }} failed {{ $card['failing']['times'] }} of {{ $card['failing']['tries'] }} times over the last game day
                    ({{ $card['failing']['why'] }}).@if($card['failing']['hint']) Try: {{ $card['failing']['hint'] }}.@endif</li>
            @endif
            @foreach($card['news'] as $n)<li>News: {{ $n['text'] }}</li>@endforeach
        </ul>
        <form method="POST" action="{{ url('account/seen') }}" class="d-inline">
            {{ csrf_field() }}<button class="button button-secondary button-sm" type="submit">Got it</button>
        </form>
    </div>
@endif
