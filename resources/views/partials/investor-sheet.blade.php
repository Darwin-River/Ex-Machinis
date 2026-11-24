{{-- The investor sheet (#994): what to weigh before buying a matured company's shares or backing its loans --}}
@php($n = fn ($v) => number_format((int) $v))
<section class="investor-sheet" id="investor-sheet" aria-labelledby="investor-sheet-title"
         style="background: #0f1424; border: 1px solid rgba(255,255,255,0.12); border-radius: 10px; padding: 14px 18px; margin: 0 0 1.5rem; white-space: normal">
    <h2 class="h5" id="investor-sheet-title" style="margin: 0 0 8px">Investor sheet</h2>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 10px 22px">
        <div id="sheet-price">
            <small style="color: #9aa3b8; text-transform: uppercase; letter-spacing: .06em">Price and value</small><br>
            Close-out value <b>{{ $n($sheet['price']['cash_out']) }}</b> a share.
            @if($sheet['price']['last'] !== null)
                Last traded at <b>{{ $n($sheet['price']['last']) }}</b> ({{ $sheet['price']['last_when'] }}),
                {{ $sheet['price']['premium_percent'] >= 0 ? $sheet['price']['premium_percent'] . '% above' : abs($sheet['price']['premium_percent']) . '% below' }} it.
            @else
                No trade between players yet.
            @endif
            <br>Idle credits <b>{{ $n($sheet['idle']['credits']) }}</b>@if($sheet['idle']['percent_of_value'] !== null) ({{ $sheet['idle']['percent_of_value'] }}% of its value)@endif.
        </div>
        <div id="sheet-payout">
            <small style="color: #9aa3b8; text-transform: uppercase; letter-spacing: .06em">What it pays out</small><br>
            Its code declares <b>{{ rtrim(rtrim(number_format($sheet['payout']['declared_percent'], 1), '0'), '.') }}%</b> of each day's profit as dividends;
            it paid <b>{{ $n($sheet['payout']['dividends_paid_30']) }}</b> over 30 game days.
            @if($sheet['payout']['payouts_asked_30'] > 0)
                <br>Payouts: <b>{{ $n($sheet['payout']['payouts_paid_30']) }}</b> paid of {{ $n($sheet['payout']['payouts_asked_30']) }} asked @if($sheet['payout']['payouts_pending']) ({{ $sheet['payout']['payouts_pending'] }} waiting)@endif.
            @endif
        </div>
        <div id="sheet-owes">
            <small style="color: #9aa3b8; text-transform: uppercase; letter-spacing: .06em">What it owes</small><br>
            @if(!$sheet['loans'] && !$sheet['requests'] && !$sheet['contracts'])
                No loans, loan requests or open contracts.
            @endif
            @foreach($sheet['loans'] as $l)
                Loan from {{ $l['lender_name'] }}@if($l['insider']) <span class="badge" style="background: #f0b429; color: #111">insider</span>@endif:
                {{ $n($l['owed']) }} owed, due {{ $l['due'] }}@if($l['defaulted']) <b style="color: #ff6b6b">in default</b>@endif.<br>
            @endforeach
            @foreach($sheet['requests'] as $r)
                Asks to borrow {{ $n($r['left']) }} at {{ $r['rate'] }}% ({{ $r['per_year'] }}% a game year), due {{ $r['due'] }}.
                <a href="{{ url('market/loans') }}">Back it</a><br>
            @endforeach
            @foreach($sheet['contracts'] as $k)
                Contract {{ $k['side'] === 'posted' ? 'to buy' : 'to deliver' }} {{ $n($k['units']) }} {{ $k['name'] }} ({{ $n($k['delivered']) }} delivered), due {{ $k['due'] }}.<br>
            @endforeach
        </div>
        <div id="sheet-insiders">
            <small style="color: #9aa3b8; text-transform: uppercase; letter-spacing: .06em">Insiders</small><br>
            @foreach($sheet['insiders'] as $i){{ $loop->first ? '' : ', ' }}<a href="{{ url('players/' . rawurlencode($i['name'])) }}" v-pre>{{ $i['name'] }}</a> ({{ $i['founder'] ? 'founder, ' : '' }}{{ $n($i['units']) }} shares)@endforeach.
            @if($sheet['related'])
                <br><b style="color: #f0b429">Dealt with insiders' companies:</b>
                @foreach($sheet['related'] as $d){{ $loop->first ? '' : ', ' }}<a href="{{ url('companies/' . $d['company_id']) }}" v-pre>{{ $d['name'] }}</a> ({{ $n($d['credits']) }} credits)@endforeach.
            @else
                <br>No dealings with its insiders' other companies.
            @endif
        </div>
        @if($sheet['founder'])
            <div id="sheet-founder">
                <small style="color: #9aa3b8; text-transform: uppercase; letter-spacing: .06em">Its founder's record</small><br>
                @if(!$sheet['founder']['companies'])
                    This is {{ $sheet['founder']['login'] }}'s first company.
                @else
                    {{ $sheet['founder']['login'] }} has founded {{ count($sheet['founder']['companies']) }} other {{ count($sheet['founder']['companies']) === 1 ? 'company' : 'companies' }}:
                    {{ $sheet['founder']['matured'] }} matured, {{ $sheet['founder']['ended'] }} ended, {{ $sheet['founder']['defaults'] }} loan {{ $sheet['founder']['defaults'] === 1 ? 'default' : 'defaults' }}.
                    <details><summary>Each one</summary>
                        <ul class="mb-0" style="padding-left: 1.2rem; list-style: disc">
                            @foreach($sheet['founder']['companies'] as $fc)
                                <li><a href="{{ url('companies/' . $fc['company_id']) }}" v-pre>{{ $fc['name'] }}</a>:
                                    {{ $fc['matured'] ? 'matured' : 'a startup' }}@if($fc['how']); {{ lcfirst($fc['how']) }}@elseif(!$fc['ended']), still running @endif @if($fc['defaults']); {{ $fc['defaults'] }} loan {{ $fc['defaults'] === 1 ? 'default' : 'defaults' }}@endif.</li>
                            @endforeach
                        </ul>
                    </details>
                @endif
            </div>
        @endif
    </div>
    <p class="mb-0 mt-2"><small>Read its <a href="#code">code</a> too: it decides what the company does with your money. See <a href="{{ url('manual#part-v-finance') }}">Finance</a> in the manual.</small></p>
</section>
