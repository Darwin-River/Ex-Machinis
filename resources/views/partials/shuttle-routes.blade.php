{{-- Routes for the Shuttle (#1050): for each ore a Mining Kit digs, where to dig it and where to sell what it makes,
     the round trip and the profit to expect from today's prices. "Use this route" rewrites the Shuttle's settings
     in the editor (#try-script); then Test drive shows it. $routes: Routes::best() --}}
@if($routes)
    <section class="mt-4" id="routes" aria-labelledby="routes-title">
        <h2 class="h4 mb-1" id="routes-title">Choose a route</h2>
        <p class="mb-2" style="color: #c3c9d8; white-space: normal; max-width: 46em">Each row is a route the Shuttle can fly with its Mining Kit:
            the ore it digs, where it digs it, and where it sells what it refines the ore into. <b>Use this route</b> writes it into the
            code's settings, then press <b>Test drive</b>.</p>
        <div class="table-responsive">
            <table class="table table-sm" id="routes-table" style="min-width: 640px">
                <thead><tr><th>Ore</th><th>Dig at</th><th>Sell at</th><th class="text-right">Round trip</th>
                    <th class="text-right">Profit a trip</th><th class="text-right">A game hour</th><th></th></tr></thead>
                <tbody>
                    @foreach($routes as $i => $r)
                        @php($goods = array_map(fn ($g) => $g['lua'] ?? (string) $g['resource'], $r['goods']))
                        <tr>
                            <td>{{ preg_replace('/\s*\(N\)$/', '', $r['ore_name']) }}</td>
                            <td>{{ $r['mine_name'] }}</td>
                            <td>{{ $r['sell_name'] }}</td>
                            <td class="text-right" style="font-variant-numeric: tabular-nums">{{ number_format($r['hours'], 1) }} game h</td>
                            <td class="text-right" style="font-variant-numeric: tabular-nums">{{ number_format($r['profit']) }} credits</td>
                            <td class="text-right" style="font-variant-numeric: tabular-nums"><b>{{ number_format($r['per_hour'], 1) }}</b></td>
                            <td class="text-right">
                                <button type="button" class="button button-secondary button-sm route-use" id="route-{{ $i }}"
                                        data-ore="{{ $r['ore_lua'] ?? $r['ore'] }}" data-ore-name="{{ preg_replace('/\s*\(N\)$/', '', $r['ore_name']) }}"
                                        data-mine-at="{{ $r['mine_lua'] ?? $r['mine_at'] }}" data-mine-name="{{ $r['mine_name'] }}"
                                        data-sell-at="{{ $r['sell_lua'] ?? $r['sell_at'] }}" data-sell-name="{{ $r['sell_name'] }}"
                                        data-digs="{{ $r['digs'] }}" data-goods="{{ implode(', ', $goods) }}">Use this route</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="mb-0" style="color: #9aa3b8; font-size: 14px; white-space: normal; max-width: 46em" id="routes-note">An estimate from today's
            House prices, after fuel and sales tax, for a drone that already has its kit. It leaves out other drones crowding a site,
            deposits running down and prices moving: test drive a route to see it against the world as it is now.</p>
        <p class="mb-0 mt-1" role="status" id="routes-status" style="color: #7ee2a8"></p>
    </section>
    <script>
    (function () {
        // Rewrites one setting's line (`name = value   -- comment`), keeping its comment; the
        // place's name goes in the comment when the code can only use its number
        function setLine(code, name, value, note) {
            const re = new RegExp('^' + name + '\\s*=.*$', 'm');
            if (!re.test(code)) return null;
            return code.replace(re, function (line) {
                const found = line.match(/--\s*(.*)$/);
                const comment = found ? found[1].replace(/:\s[^(]*$/, '') : '';
                const text = note && !comment.includes(note) ? (comment ? comment.replace(/\s*$/, '') + ': ' : '') + note : comment;
                // The settings' comments line up at column 22, as the Shuttle's do
                return text ? (name + ' = ' + value).padEnd(20, ' ') + ' -- ' + text : name + ' = ' + value;
            });
        }
        // One listener on the document: the page's Vue app re-renders its DOM when it mounts, dropping listeners set before
        document.addEventListener('click', function (event) {
            const button = event.target.closest ? event.target.closest('.route-use') : null;
            if (!button) return;
            (function () {
                const box = document.getElementById('try-script');
                const status = document.getElementById('routes-status');
                if (!box) return;
                const d = button.dataset;
                const numeric = (v) => /^\d+$/.test(v);
                let code = box.value;
                const steps = [['ore', d.ore, numeric(d.ore) ? d.oreName : ''], ['mine_at', d.mineAt, numeric(d.mineAt) ? d.mineName : ''],
                    ['sell_at', d.sellAt, numeric(d.sellAt) ? d.sellName : ''], ['digs', d.digs, ''], ['goods', '{' + d.goods + '}', '']];
                for (const [name, value, note] of steps) {
                    const next = setLine(code, name, value, note);
                    if (next === null) {
                        status.textContent = 'This code has no "' + name + ' =" setting to change: start from the Shuttle again to use a route.';
                        status.style.color = '#f0b429';
                        return;
                    }
                    code = next;
                }
                box.value = code;
                box.dispatchEvent(new Event('input', { bubbles: true }));   // the editor follows its textarea
                status.style.color = '#7ee2a8';
                status.textContent = 'The code now digs ' + d.oreName + ' at ' + d.mineName + ' and sells at ' + d.sellName + '. Press Test drive to see it.';
            })();
        });
    })();
    </script>
@endif
