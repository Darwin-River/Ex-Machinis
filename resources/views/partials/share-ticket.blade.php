{{-- The share order ticket (#845), on the Trading desk and every matured company's page: Buy or sell now takes the best
     route across players' orders, an offering and the bank and says which before you press; Name my price posts an order
     at a price, or one that follows the bank's, and says what fills at once and what stands.
     $tk: company, name, p (PlayerShares::prices), held (null when logged out) --}}
@include('partials.market-styles')
@php($tkId = (int) $tk['company'])
<div class="share-ticket" id="ticket" data-company="{{ $tkId }}" data-quote="{{ url('companies/' . $tkId . '/share-quote') }}">
    {{-- A visitor sees a sample (#905): live quotes, nothing to press but Log in --}}
    @php($sample = $tk['held'] === null)
    @if($sample)
        <p class="st-sample-note"><a href="{{ url('login') }}">Log in</a> to trade {{ $tk['name'] }}'s shares. Until then this is a
            sample with live prices: change the numbers to see what an order would do.</p>
    @endif
        <div class="st-tabs" role="tablist" aria-label="How to trade">
            <button type="button" role="tab" class="st-tab" id="st-tab-now" aria-selected="true" aria-controls="st-now">Buy or sell now</button>
            <button type="button" role="tab" class="st-tab" id="st-tab-price" aria-selected="false" aria-controls="st-price">Name my price</button>
        </div>

        {{-- Now: the best route, in price order, all of it or none --}}
        <form method="POST" action="{{ url('companies/' . $tkId . '/share-now') }}" id="st-now" role="tabpanel" aria-labelledby="st-tab-now">
            {{ csrf_field() }}
            <input type="hidden" name="limit" id="st-now-limit" value="">
            <div class="st-row">
                <select class="mk-select" name="side" id="st-now-side" aria-label="Buy or sell">
                    <option value="buy">Buy</option><option value="sell" @if(($tk['side'] ?? null) === 'sell') selected @endif>Sell</option></select>
                <input class="mk-input" type="number" name="units" id="st-now-units" min="1" value="{{ (int) ($tk['units'] ?? 1) }}" aria-label="Shares" style="width: 6em">
                <span>shares now</span>
                <button class="mk-button" type="submit" id="st-now-go" disabled>Buy now</button>
            </div>
            <div class="st-quick" aria-label="Quick amounts">
                <button type="button" class="st-q" data-units="1">1</button>
                <button type="button" class="st-q" data-units="10">10</button>
                <button type="button" class="st-q" data-units="max" id="st-max">As many as I can</button>
            </div>
            <div class="st-say" id="st-now-say" role="status" aria-live="polite">Working it out…</div>
        </form>

        {{-- Name my price: a standing order, fixed or following the bank's price (#766) --}}
        <form method="POST" action="{{ url('companies/' . $tkId . '/share-orders') }}" id="st-price" role="tabpanel" aria-labelledby="st-tab-price" hidden>
            {{ csrf_field() }}
            <div class="st-row">
                <select class="mk-select" name="side" id="st-price-side" aria-label="Buy or sell"><option value="buy">Buy</option><option value="sell">Sell</option></select>
                <input class="mk-input" type="number" name="units" id="st-price-units" min="1" value="1" aria-label="Shares" style="width: 6em">
                <span>shares at</span>
                <input class="mk-input" type="number" name="price" id="st-price-price" min="{{ $tk['p']['low'] }}" max="{{ $tk['p']['high'] }}"
                       value="{{ $tk['p']['sells_at'] }}" aria-label="Price a share" style="width: 7em">
            </div>
            <div class="st-row">
                <label for="st-price-peg">or follow</label>
                <select class="mk-select" name="peg" id="st-price-peg"><option value="">no: a fixed price</option>
                    <option value="buys_at">what the bank buys at</option><option value="sells_at">what the bank sells at</option></select>
                <input class="mk-input" type="number" name="offset_percent" id="st-price-offset" step="0.5" value="0"
                       min="-{{ \App\Services\PlayerShares::PEG_MAX_PERCENT }}" max="{{ \App\Services\PlayerShares::PEG_MAX_PERCENT }}"
                       aria-label="Plus or minus, percent" style="width: 6em" disabled> <span>%</span>
                <button class="mk-button" type="submit">Post the order</button>
            </div>
            <div class="st-say" id="st-price-say" role="status" aria-live="polite"></div>
        </form>
        <p class="st-band"><small>The bank buys at <b>{{ number_format($tk['p']['buys_at']) }}</b> (paid in {{ config('forge.bank_pay_days') }} game days)
            and sells at <b>{{ number_format($tk['p']['sells_at']) }}</b>; players trade at any price they agree.@if(!$sample) You hold <b>{{ number_format($tk['held']) }}</b>.@endif</small></p>
</div>
@once
@push('styles')
<style>
    .share-ticket .st-tabs { display: flex; gap: 6px; margin-bottom: .6rem; flex-wrap: wrap; }
    .share-ticket .st-tab { background: transparent; color: inherit; border: 1px solid #3a4568; border-radius: 999px; padding: .25rem .8rem; }
    .share-ticket .st-tab[aria-selected="true"] { background: #2c3a63; border-color: #6b7fc0; }
    .share-ticket .st-row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: .45rem 0; }
    .share-ticket .st-quick { display: flex; flex-wrap: wrap; gap: 6px; margin: .3rem 0; }
    .share-ticket .st-q { background: transparent; color: inherit; border: 1px dashed #4a5680; border-radius: 6px; padding: .1rem .6rem; font-size: .9em; }
    .share-ticket .st-say { white-space: normal; min-height: 2.6em; margin: .4rem 0; }
    .share-ticket .st-say.st-bad { color: #ff8a7a; }
    .share-ticket .st-sample-note { margin-bottom: .5rem; }
    .share-ticket .st-sample-note ~ form { opacity: .8; }
</style>
@endpush
@endonce
<script>
document.addEventListener('DOMContentLoaded', function () {
    const T = document.getElementById('ticket'), Q = T.dataset.quote, $ = (id) => document.getElementById(id);
    const n = (v) => Number(v).toLocaleString('en-US');
    const P = @json($tk['p']), SAMPLE = @json($sample);
    if (SAMPLE) T.querySelectorAll('button[type=submit]').forEach((b) => { b.disabled = true; b.title = 'Log in to trade'; });
    let timer = null, seq = 0;
    async function quote(params) {
        const my = ++seq;
        const r = await fetch(Q + '?' + new URLSearchParams(params), {headers: {Accept: 'application/json'}, credentials: 'same-origin'});
        const j = await r.json().catch(() => ({error: 'No answer: try again.'}));
        j.ok = r.ok && !j.error;   // the answer carries no ok: its status says
        return my === seq ? j : null;
    }
    // Now: the quote, in words, and the worst price it would pay or take, sent along so a moved market can't surprise you
    async function now() {
        const side = $('st-now-side').value, units = Number($('st-now-units').value), say = $('st-now-say'), go = $('st-now-go');
        go.textContent = side === 'buy' ? 'Buy now' : 'Sell now';
        go.disabled = true;
        if (!Number.isInteger(units) || units < 1) { say.textContent = 'Shares are whole numbers, at least 1.'; return; }
        const q = await quote({side, units});
        if (!q) return;
        say.classList.toggle('st-bad', !q.ok || q.filled < 1 || q.can_pay === false);
        if (!q.ok) { say.textContent = q.error; return; }
        say.textContent = q.text;
        $('st-now-limit').value = q.worst ?? '';
        go.disabled = SAMPLE || q.filled < 1 || q.can_pay === false;
    }
    // Name my price: what meets it at once, and what stands
    async function price() {
        const side = $('st-price-side').value, units = Number($('st-price-units').value), say = $('st-price-say');
        const peg = $('st-price-peg').value, off = Number($('st-price-offset').value), fixed = Number($('st-price-price').value);
        $('st-price-offset').disabled = !peg;
        $('st-price-price').disabled = !!peg;
        if (!Number.isInteger(units) || units < 1) { say.textContent = 'Shares are whole numbers, at least 1.'; return; }
        let at = fixed;
        if (peg) {
            at = Math.round(P[peg] * (1 + off / 100));
            if (side === 'sell') at = Math.max(at, P.buys_at);
            at = Math.max(P.low, Math.min(P.high, at));
        } else if (!Number.isInteger(at) || at < P.low || at > P.high) {
            say.classList.add('st-bad');
            say.textContent = `Refused: a price is from ${n(P.low)} to ${n(P.high)} a share.`;
            return;
        }
        const q = await quote({side, units, limit: at, book: 1});
        if (!q) return;
        say.classList.toggle('st-bad', !q.ok);
        if (!q.ok) { say.textContent = q.error; return; }
        let t = q.filled ? `${q.filled} ${side === 'buy' ? 'bought' : 'sold'} at once from players' orders (${n(q.total)} credits).` : 'No player\'s order meets it now.';
        const rest = units - q.filled;
        if (rest) t += ` ${q.filled ? 'The other ' + rest : 'All ' + rest} stand as ${side === 'buy' ? 'a buy' : 'a sell'} order at ${n(at)}`
            + (peg ? `, moving with what the bank ${peg === 'buys_at' ? 'buys' : 'sells'} at` : '') + ' until someone meets it; once it has rested a game hour, each fill earns you a rebate from the bank.';
        if (side === 'buy' && at > P.sells_at && P.bank_holds > 0) t += ` The bank sells for less (${n(P.sells_at)}): Buy now takes that.`;
        if (side === 'sell' && at < P.buys_at) t += ` The bank buys for more (${n(P.buys_at)}): Sell now takes that.`;
        say.textContent = t;
    }
    const soon = (f) => () => { clearTimeout(timer); timer = setTimeout(f, 200); };
    ['st-now-side', 'st-now-units'].forEach((id) => $(id).addEventListener('input', soon(now)));
    ['st-price-side', 'st-price-units', 'st-price-price', 'st-price-peg', 'st-price-offset'].forEach((id) => $(id).addEventListener('input', soon(price)));
    T.querySelectorAll('.st-q').forEach((b) => b.addEventListener('click', async () => {
        if (b.dataset.units === 'max') {
            const q = await quote({side: $('st-now-side').value, units: 1});
            if (q && q.ok && q.max_units) $('st-now-units').value = q.max_units;
        } else $('st-now-units').value = b.dataset.units;
        now();
    }));
    $('st-now-side').addEventListener('input', () => { $('st-max').textContent = $('st-now-side').value === 'sell' ? 'All I hold' : 'As many as I can'; });
    // Tabs
    function show(which) {
        ['now', 'price'].forEach((k) => { $('st-' + k).hidden = k !== which; $('st-tab-' + k).setAttribute('aria-selected', String(k === which)); });
        which === 'now' ? now() : price();
    }
    $('st-tab-now').addEventListener('click', () => show('now'));
    $('st-tab-price').addEventListener('click', () => show('price'));
    // Another part of the page fills the ticket (a ladder row, a holding's Sell button): ExTicket.fill({mode, side, units, price})
    window.ExTicket = {fill(o) {
        if (o.mode === 'price') {
            if (o.side) $('st-price-side').value = o.side;
            if (o.units) $('st-price-units').value = o.units;
            if (o.price) { $('st-price-peg').value = ''; $('st-price-price').value = o.price; }
            show('price');
        } else {
            if (o.side) $('st-now-side').value = o.side;
            if (o.units) $('st-now-units').value = o.units;
            $('st-max').textContent = $('st-now-side').value === 'sell' ? 'All I hold' : 'As many as I can';
            show('now');
        }
        T.scrollIntoView({behavior: 'smooth', block: 'nearest'});
    }};
    $('st-max').textContent = $('st-now-side').value === 'sell' ? 'All I hold' : 'As many as I can';
    now();
});
</script>
