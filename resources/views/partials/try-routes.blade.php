{{-- The simple drones' tables on /try (#1050, #1070): the Miner's routes (each ore a Mining Kit digs, where to dig it and
     where to sell what it refines into) and the Maker's products (each good a Factory Kit makes from goods the House sells,
     where to buy them and where to sell it), each with the round trip and the profit to expect from today's prices.
     "Use this" rewrites the drone's settings in the editor (#try-script); then Test drive shows it.
     $routes: Routes::best(); $making: Routes::making() --}}
@php($routes = $routes ?? [])
@php($making = $making ?? [])
@php($plain = fn ($name) => preg_replace('/\s*\(N\)$/', '', (string) $name))
@php($num = 'text-right" style="font-variant-numeric: tabular-nums')
@if($routes || $making)
    <section class="mt-4" id="routes" aria-labelledby="routes-title">
        @if($routes)
            <h2 class="h4 mb-1" id="routes-title">Choose a route</h2>
            <p class="mb-2" style="color: #c3c9d8; white-space: normal; max-width: 46em">Each row is a route the Miner can fly with its Mining Kit:
                the ore it digs, where it digs it, and where it sells what it refines the ore into. <b>Use this route</b> writes it into the
                code's settings, then press <b>Test drive</b>.</p>
            <div class="table-responsive">
                <table class="table table-sm" id="routes-table" style="min-width: 640px">
                    <thead><tr><th>Ore</th><th>Dig at</th><th>Sell at</th><th class="text-right">Round trip</th>
                        <th class="text-right">Fuel</th><th class="text-right">Profit a trip</th><th class="text-right">A game hour</th><th></th></tr></thead>
                    <tbody>
                        @foreach($routes as $i => $r)
                            @php($goods = array_map(fn ($g) => $g['lua'] ?? (string) $g['resource'], $r['goods']))
                            @php($ore = $plain($r['ore_name']))
                            <tr>
                                <td>{{ $ore }}</td>
                                <td>{{ $r['mine_name'] }}</td>
                                <td>{{ $r['sell_name'] }}</td>
                                <td class="{!! $num !!}">{{ number_format($r['hours'], 1) }} game h</td>
                                <td class="{!! $num !!}">{{ number_format($r['fuel_cost']) }} credits</td>
                                <td class="{!! $num !!}">{{ number_format($r['profit']) }} credits</td>
                                <td class="{!! $num !!}"><b>{{ number_format($r['per_hour'], 1) }}</b></td>
                                <td class="text-right">
                                    <button type="button" class="button button-secondary button-sm route-use" id="route-{{ $i }}"
                                            data-settings="{{ json_encode([['ore', (string) ($r['ore_lua'] ?? $r['ore']), $r['ore_lua'] ? '' : $ore],
                                                ['mine_at', (string) ($r['mine_lua'] ?? $r['mine_at']), $r['mine_lua'] ? '' : $r['mine_name']],
                                                ['sell_at', (string) ($r['sell_lua'] ?? $r['sell_at']), $r['sell_lua'] ? '' : $r['sell_name']],
                                                ['digs', (string) $r['digs'], ''], ['goods', '{' . implode(', ', $goods) . '}', '']]) }}"
                                            data-done="The code now digs {{ $ore }} at {{ $r['mine_name'] }} and sells at {{ $r['sell_name'] }}. Press Test drive to see it.">Use this route</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        @if($making)
            <h2 class="h4 mb-1" id="routes-title">Choose what to make</h2>
            <p class="mb-2" style="color: #c3c9d8; white-space: normal; max-width: 46em">Each row is something the Maker can make with its Factory Kit
                from goods the House sells: where it buys them and what they cost, and where it sells what it made. <b>Use this</b> writes it
                into the code's settings, then press <b>Test drive</b>.</p>
            <div class="table-responsive">
                <table class="table table-sm" id="making-table" style="min-width: 720px">
                    <thead><tr><th>Makes</th><th>From</th><th>Buy at</th><th>Sell at</th><th class="text-right">Costs</th><th class="text-right">Sells for</th>
                        <th class="text-right">Round trip</th><th class="text-right">Profit</th><th class="text-right">A game hour</th><th></th></tr></thead>
                    <tbody>
                        @foreach($making as $i => $m)
                            <tr>
                                <td>{{ $m['makes'] > 1 ? $m['makes'] . ' ' : '' }}{{ $m['product_name'] }}</td>
                                <td style="font-size: 13px">{{ implode(', ', array_map(fn ($in) => $in['units'] . ' ' . $plain($in['name']), $m['inputs'])) }}</td>
                                <td>{{ $m['buy_name'] }}</td>
                                <td>{{ $m['sell_name'] }}</td>
                                <td class="{!! $num !!}">{{ number_format($m['cost'] + $m['fuel_cost']) }}</td>
                                <td class="{!! $num !!}">{{ number_format($m['income']) }}</td>
                                <td class="{!! $num !!}">{{ number_format($m['hours'], 1) }} game h</td>
                                <td class="{!! $num !!}">{{ number_format($m['profit']) }}</td>
                                <td class="{!! $num !!}"><b>{{ number_format($m['per_hour'], 1) }}</b></td>
                                <td class="text-right">
                                    <button type="button" class="button button-secondary button-sm route-use" id="make-{{ $i }}"
                                            data-settings="{{ json_encode([['product', (string) ($m['product_lua'] ?? $m['product']), $m['product_lua'] ? '' : $m['product_name']],
                                                ['buy_at', (string) ($m['buy_lua'] ?? $m['buy_at']), $m['buy_lua'] ? '' : $m['buy_name']],
                                                ['sell_at', (string) ($m['sell_lua'] ?? $m['sell_at']), $m['sell_lua'] ? '' : $m['sell_name']]]) }}"
                                            data-done="The code now makes {{ $m['product_name'] }} from goods bought at {{ $m['buy_name'] }} and sells it at {{ $m['sell_name'] }}. Press Test drive to see it.">Use this</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        <p class="mb-0" style="color: #9aa3b8; font-size: 14px; white-space: normal; max-width: 46em" id="routes-note">Credits, estimated from today's
            House prices, after fuel and sales tax, for a drone that already has its kit (a kit costs 2,000).@if($making) The Maker's rows are the goods a new startup can afford to make once it has its kit.@endif It leaves out other drones crowding a site
            or buying the same goods, deposits running down and prices moving: test drive a row to see it against the world as it is now.</p>
        <p class="mb-0 mt-1" role="status" id="routes-status" style="color: #7ee2a8"></p>
    </section>
    <script>
    (function () {
        // Rewrites one setting's line (`name = value   -- comment`), keeping its comment; a name goes at the
        // end of the comment ("; now Io") when the code can only use a number, replacing the last one
        function setLine(code, name, value, note) {
            const re = new RegExp('^' + name + '\\s*=.*$', 'm');
            if (!re.test(code)) return null;
            return code.replace(re, function (line) {
                const found = line.match(/--\s*(.*)$/);
                const comment = found ? found[1].replace(/;\s*now\s.*$/, '').replace(/\s*$/, '') : '';
                const text = note ? (comment ? comment + '; now ' : '') + note : comment;
                // The settings' comments line up at column 22, as the starters' do
                return text ? (name + ' = ' + value).padEnd(20, ' ') + ' -- ' + text : name + ' = ' + value;
            });
        }
        // One listener on the document: the page's Vue app re-renders its DOM when it mounts, dropping listeners set before
        document.addEventListener('click', function (event) {
            const button = event.target.closest ? event.target.closest('.route-use') : null;
            if (!button) return;
            const box = document.getElementById('try-script');
            const status = document.getElementById('routes-status');
            if (!box) return;
            let code = box.value;
            for (const [name, value, note] of JSON.parse(button.dataset.settings)) {
                const next = setLine(code, name, value, note);
                if (next === null) {
                    status.textContent = 'This code has no "' + name + ' =" setting to change: start from the drone again to use this row.';
                    status.style.color = '#f0b429';
                    return;
                }
                code = next;
            }
            box.value = code;
            box.dispatchEvent(new Event('input', { bubbles: true }));   // the editor follows its textarea
            status.style.color = '#7ee2a8';
            status.textContent = button.dataset.done;
        });
    })();
    </script>
@endif
