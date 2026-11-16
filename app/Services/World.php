<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * The public view of the world (#324 step 17): the Home page's event feed,
 * the Market page (the order book and recent trades) and the Map (where the planets are now, and
 * the drones and wrecks around each). Visitors see everything at once; only
 * drones are limited to what is local.
 */
class World
{
    public const STATION_SELLS = 2;

    private static function names(string $table, string $key, string $name, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        return $ids ? DB::table($table)->whereIn($key, $ids)->pluck($name, $key)->all() : [];
    }

    /** A game time now, from the shared game clock */
    public static function gameNow(): int
    {
        $clock = DB::table('game_clock')->where('id', 1)->first();
        return $clock ? (int) round($clock->game_anchor + (time() - $clock->real_anchor) * $clock->scale) : time();
    }

    /**
     * The mineral deposits at a place (#380), as the engine works them out
     * (db_take_deposit): what is left now, refilled by refill_per_day for
     * the game time since it was last worked, up to the capacity; a deposit
     * nobody has worked yet is full. Rows: resource, name, left, capacity,
     * refill_per_day.
     */
    public static function deposits(int $objectId): array
    {
        $now = self::gameNow();
        return DB::table('deposits AS d')
            ->leftJoin('deposit_state AS s', function ($j) {
                $j->on('s.object_id', '=', 'd.object_id')->on('s.resource', '=', 'd.resource');
            })
            ->leftJoin('resources AS r', 'r.id', '=', 'd.resource')
            ->where('d.object_id', $objectId)->orderBy('d.resource')
            ->get(['d.resource', 'r.name', 'd.capacity', 'd.refill_per_day', 's.amount', 's.refilled_t'])
            ->map(function ($d) use ($now) {
                $left = $d->amount === null ? (float) $d->capacity
                    : (float) $d->amount + $d->refill_per_day * max(0, $now - (int) $d->refilled_t) / 86400;
                return [
                    'resource' => (int) $d->resource,
                    'name' => $d->name,
                    'left' => (int) floor(max(0, min((float) $d->capacity, $left))),
                    'capacity' => (int) $d->capacity,
                    'refill_per_day' => (int) $d->refill_per_day,
                ];
            })->all();
    }

    /** A real time (a row's created_at) as game time */
    private static function gameTimeOf(string $real): int
    {
        $clock = DB::table('game_clock')->where('id', 1)->first();
        $t = strtotime($real);
        return $clock ? (int) round($clock->game_anchor + ($t - $clock->real_anchor) * $clock->scale) : $t;
    }

    /**
     * What happened lately, newest first: [game_t, when, text, link, html,
     * kind, place]. Each kind is read on its own (the latest few), then they
     * are merged. `html` is the text with every name a link (#697); `kind`
     * is fuel for a purchase of fuel, which the home page groups.
     */
    public static function feed(int $limit = 30): array
    {
        $items = [];
        $add = function (int $t, string $text, ?string $link = null, ?string $html = null, string $kind = 'event', $place = null) use (&$items) {
            $items[] = ['game_t' => $t, 'text' => $text, 'link' => $link, 'html' => $html ?? e($text), 'kind' => $kind, 'place' => $place];
        };
        $a = fn (?string $name, string $path) => '<a href="' . e(url($path)) . '">' . e($name) . '</a>';

        foreach (DB::table('releases as r')->join('companies as c', 'c.company_id', '=', 'r.company_id')
                     ->join('agents as a', 'a.agent_id', '=', 'r.agent_id')
                     ->select('r.created_at', 'c.name as company', 'c.company_id', 'a.name', 'a.agent_id', 'r.copied_from', 'r.same_as')
                     ->orderBy('r.id', 'desc')->limit($limit)->get() as $r) {
            $how = $r->copied_from ? ', with code copied from drone ' . $r->copied_from : '';
            $add(self::gameTimeOf($r->created_at), "{$r->company} released a new drone, {$r->name}{$how}.", 'spacecraft/' . $r->agent_id,
                $a($r->company, 'companies/' . $r->company_id) . ' released a new drone, ' . $a($r->name, 'spacecraft/' . $r->agent_id)
                . ($r->copied_from ? ', with code copied from ' . $a('drone ' . $r->copied_from, 'spacecraft/' . $r->copied_from) : '') . '.');
        }

        // Broadcasts (#380): messages are private to a company (after round
        // 40), so the feed says one was sent, not what it said
        $said = DB::table('broadcasts as b')->leftJoin('agents as a', 'a.agent_id', '=', 'b.drone')
            ->orderBy('b.id', 'desc')->limit($limit)->get(['b.drone', 'b.game_t', 'a.name']);
        foreach ($said as $b) {
            $add((int) $b->game_t, ($b->name ?? 'A drone') . ' sent a message to its company.', 'spacecraft/' . $b->drone,
                $a($b->name ?? 'A drone', 'spacecraft/' . $b->drone) . ' sent a message to its company.');
        }

        $launches = DB::table('events')->where('event_type', 8)->where('outcome', 1)->orderBy('id', 'desc')->limit($limit)->get();
        $names = self::names('agents', 'agent_id', 'name', array_merge($launches->pluck('drone')->all(), $launches->pluck('new_quantity')->all()));
        foreach ($launches as $e) {
            $add((int) $e->game_t, ($names[$e->drone] ?? 'A drone') . ' launched a clone, ' . ($names[$e->new_quantity] ?? 'a new drone') . '.',
                'spacecraft/' . $e->new_quantity,
                $a($names[$e->drone] ?? 'A drone', 'spacecraft/' . $e->drone) . ' launched a clone, '
                . $a($names[$e->new_quantity] ?? 'a new drone', 'spacecraft/' . $e->new_quantity) . '.');
        }

        $why = ['fuel' => 'it ran out of fuel', 'crashes' => 'its program kept crashing', 'tax' => "its company couldn't pay its tax",
            'closed' => 'its player closed its seedling'];
        foreach (DB::table('agents')->whereNotNull('wrecked_at')->orderBy('wrecked_at', 'desc')->limit($limit)->get() as $w) {
            $cause = $why[$w->wreck_cause] ?? 'it died';
            $add((int) $w->wrecked_at, "{$w->name} became a wreck: $cause.", 'spacecraft/' . $w->agent_id,
                $a($w->name, 'spacecraft/' . $w->agent_id) . ' became a wreck: ' . e($cause) . '.');
        }

        $plundered = DB::table('agents')->whereNotNull('plundered_at')->whereNotNull('plundered_by')->orderBy('plundered_at', 'desc')->limit($limit)->get();
        $names = self::names('agents', 'agent_id', 'name', $plundered->pluck('plundered_by')->all());
        foreach ($plundered as $p) {
            $add((int) $p->plundered_at, ($names[$p->plundered_by] ?? 'A drone') . " plundered the wreck of {$p->name}.", 'spacecraft/' . $p->agent_id,
                $a($names[$p->plundered_by] ?? 'A drone', 'spacecraft/' . $p->plundered_by) . ' plundered the wreck of '
                . $a($p->name, 'spacecraft/' . $p->agent_id) . '.');
        }

        $ends = ['tax' => "couldn't pay its tax and was eliminated", 'drones' => 'lost its last drone'];
        foreach (DB::table('companies')->whereNotNull('eliminated_at')->orderBy('eliminated_at', 'desc')->limit($limit)->get() as $c) {
            $end = $ends[$c->end_cause] ?? 'ended';
            $add((int) $c->eliminated_at, "{$c->name} $end.", null, $a($c->name, 'companies/' . $c->company_id) . ' ' . e($end) . '.');
        }

        $trades = DB::table('trades')->whereNotNull('game_t')->orderBy('id', 'desc')->limit($limit)->get();
        $drones = self::names('agents', 'agent_id', 'name', array_merge($trades->pluck('seller')->all(), $trades->pluck('buyer')->all()));
        $resources = self::names('resources', 'id', 'name', $trades->pluck('resource')->all());
        $places = self::names('objects', 'object_id', 'object_name', $trades->pluck('location')->all());
        foreach ($trades as $t) {
            $what = "{$t->units} " . ($resources[$t->resource] ?? 'units');
            $where = $t->location && isset($places[$t->location]) ? ' at ' . $a($places[$t->location], 'locations/' . $t->location) : '';
            $add((int) $t->game_t, ($drones[$t->buyer] ?? 'A drone') . " bought $what"
                . ' from ' . ($drones[$t->seller] ?? 'another drone') . ' for ' . number_format($t->credits) . ' credits.', null,
                $a($drones[$t->buyer] ?? 'A drone', 'spacecraft/' . $t->buyer) . ' bought ' . $a($what, 'resources/' . $t->resource)
                . ' from ' . $a($drones[$t->seller] ?? 'another drone', 'spacecraft/' . $t->seller) . $where
                . ' for ' . number_format($t->credits) . ' credits.',
                (int) $t->resource === Release::FUEL ? 'fuel' : 'trade',
                $t->location ? ['id' => (int) $t->location, 'name' => $places[$t->location] ?? null, 'units' => (int) $t->units,
                    'credits' => (int) $t->credits] : null);
        }

        usort($items, fn ($a, $b) => $b['game_t'] <=> $a['game_t']);
        $items = array_slice($items, 0, $limit);
        foreach ($items as &$item) {
            $item['when'] = DronePage::when($item['game_t']);
        }
        return $items;
    }

    /**
     * The home page's "Just now" (#697): the latest few things, with fuel
     * purchases at a place grouped into one line where its latest one was
     */
    public static function feedGrouped(int $limit = 8): array
    {
        $out = [];
        $fuelAt = [];
        foreach (self::feed(40) as $item) {
            if ($item['kind'] === 'fuel' && $item['place']) {
                $key = $item['place']['id'];
                if (isset($fuelAt[$key])) {
                    $g = &$out[$fuelAt[$key]];
                    $g['count']++;
                    $g['units'] += $item['place']['units'];
                    $g['credits'] += $item['place']['credits'];
                    unset($g);
                    continue;
                }
                $fuelAt[$key] = count($out);
                $item += ['count' => 1, 'units' => $item['place']['units'], 'credits' => $item['place']['credits']];
            }
            $out[] = $item;
        }
        foreach ($out as &$item) {
            if ($item['kind'] === 'fuel' && ($item['count'] ?? 1) > 1) {
                $place = '<a href="' . e(url('locations/' . $item['place']['id'])) . '">' . e($item['place']['name'] ?? 'one place') . '</a>';
                $item['html'] = $item['count'] . ' fuel purchases at ' . $place . ': ' . number_format($item['units']) . ' fuel for '
                    . number_format($item['credits']) . ' credits. The latest: ' . $item['html'];
                $item['text'] = strip_tags($item['html']);
            }
        }
        unset($item);
        return array_slice($out, 0, $limit);
    }

    public static function trades(int $limit = 30): array
    {
        $trades = DB::table('trades')->orderBy('id', 'desc')->limit($limit)->get();
        $drones = self::names('agents', 'agent_id', 'name', array_merge($trades->pluck('seller')->all(), $trades->pluck('buyer')->all()));
        $resources = self::names('resources', 'id', 'name', $trades->pluck('resource')->all());
        $places = self::names('objects', 'object_id', 'object_name', $trades->pluck('location')->all());
        return $trades->map(fn ($t) => [
            'when' => DronePage::when((int) $t->game_t), 'resource' => $resources[$t->resource] ?? $t->resource,
            'units' => (int) $t->units, 'credits' => (int) $t->credits,
            'price' => $t->units ? round($t->credits / $t->units, 1) : 0,
            'seller' => $drones[$t->seller] ?? $t->seller, 'seller_id' => (int) $t->seller,
            'buyer' => $drones[$t->buyer] ?? $t->buyer, 'buyer_id' => (int) $t->buyer,
            'place' => $places[$t->location] ?? '',
        ])->all();
    }

    /**
     * Market history (#380): every trade of the last $days game days, summed
     * per game day, resource and place. Players asked for it in round 31:
     * the recent trades list covers under a game day. Rows: day, resource,
     * resource_name, place, place_name, trades, units, credits, and the
     * lowest, average and highest price a unit paid that day.
     */
    public static function history(int $days = 60, ?int $resource = null, ?int $place = null): array
    {
        $epoch = (int) DB::table('game_clock')->where('id', 1)->value('epoch');
        $now = self::gameNow();
        $from = max($epoch, $now - $days * 86400);
        $query = DB::table('trades AS t')
            ->leftJoin('resources AS r', 'r.id', '=', 't.resource')
            ->leftJoin('objects AS o', 'o.object_id', '=', 't.location')
            ->where('t.game_t', '>=', $from)->where('t.units', '>', 0)
            ->when($resource, fn ($q) => $q->where('t.resource', $resource))
            ->when($place, fn ($q) => $q->where('t.location', $place))
            ->groupBy('day', 't.resource', 'r.name', 't.location', 'o.object_name')
            ->orderBy('day')->orderBy('t.resource')->orderBy('t.location')
            ->selectRaw('FLOOR((t.game_t - ?) / 86400) AS day, t.resource, r.name AS resource_name,
                t.location AS place, o.object_name AS place_name, COUNT(*) AS trades, SUM(t.units) AS units,
                SUM(t.credits) AS credits, MIN(t.credits / t.units) AS low, MAX(t.credits / t.units) AS high', [$epoch]);
        return $query->get()->map(fn ($r) => [
            'day' => (int) $r->day, 'resource' => (int) $r->resource, 'resource_name' => $r->resource_name ?? (string) $r->resource,
            'place' => (int) $r->place, 'place_name' => $r->place_name ?? '',
            'trades' => (int) $r->trades, 'units' => (int) $r->units, 'credits' => (int) $r->credits,
            'low' => round((float) $r->low, 1), 'average' => round($r->credits / $r->units, 1), 'high' => round((float) $r->high, 1),
        ])->all();
    }

    /**
     * The open orders (sell: event type 4, stock above the kept minimum; buy:
     * 5, stock below the wanted maximum): the best one for each resource at
     * each place, by resource. Round 30: a plain top 25 by resource ID left
     * out everything past the metals, so nobody saw Earth's rod buyer.
     */
    public static function orders(int $type, int $limit = 300): array
    {
        // From latest_orders (each drone's newest order, its units and
        // is_open kept by triggers; #430, #434), not from events: grouping
        // every order event by drone and resource walked all 19 million
        // events on the live database each time the dashboard or the Market
        // page was worked out (#723)
        $best = $type === 4 ? 'ASC' : 'DESC';
        $rows = DB::select(
            "SELECT * FROM (
               SELECT o.drone, a.name AS drone_name, o.resource, r.name AS resource_name, o.price,
                      ABS(IFNULL(o.units, 0) - o.quantity) AS units, p.object_name AS place,
                      ROW_NUMBER() OVER (PARTITION BY o.resource, a.object_id ORDER BY o.price $best, o.event_id) AS n
               FROM latest_orders o
               JOIN agents a ON a.agent_id = o.drone AND a.wrecked_at IS NULL
               LEFT JOIN resources r ON r.id = o.resource
               LEFT JOIN objects p ON p.object_id = a.object_id
               WHERE o.event_type = ? AND o.is_open = 1) b
             WHERE n = 1 ORDER BY resource, place LIMIT " . (int) $limit,
            [$type]);
        return array_map(function ($r) { $r = (array) $r; unset($r['n']); return $r; }, $rows);
    }

    /**
     * Fuel on sale now, by place (#609): what a pull can take there, as the
     * order book counts it, and the cheapest price; and the exchanges selling
     * none. Round 63: only the Mars Exchange showed fuel for most of the
     * round, and drones stranded without a word. Rows: [place, units, price];
     * then the House exchanges with none
     */
    public static function fuelForSale(): array
    {
        $movable = \App\Http\Controllers\ApiController::movable(4);
        $rows = DB::select("SELECT p.object_name AS place, SUM(b.movable - b.quantity) AS units, MIN(b.price) AS price
            FROM (SELECT o.drone, o.price, o.quantity, $movable AS movable FROM latest_orders AS o
                  WHERE o.resource = ? AND o.event_type = 4 AND o.is_open = 1) AS b
            JOIN agents AS a ON a.agent_id = b.drone AND a.wrecked_at IS NULL
            LEFT JOIN objects AS p ON p.object_id = a.object_id
            WHERE b.movable > b.quantity GROUP BY p.object_name ORDER BY price, place", [\App\Services\Release::FUEL]);
        $selling = array_map(fn ($r) => ['place' => $r->place, 'units' => (int) $r->units, 'price' => (int) $r->price], $rows);
        $exchanges = DB::table('stations AS s')->join('objects AS o', 'o.object_id', '=', 's.object_id')
            ->orderBy('o.object_name')->pluck('o.object_name')->all();
        $none = array_values(array_diff($exchanges, array_column($selling, 'place')));
        return ['selling' => $selling, 'none' => $none];
    }

    /**
     * The planets where they are now (top-down, distances on a log scale) and,
     * for each, the working drones and wrecks around it (its moons included)
     */
    public static function map(): array
    {
        $days = (self::gameNow() - 946684800) / 86400.0;   // since J2000, the orbital elements' epoch
        $planets = DB::table('objects')->where('object_type', 'Planet')->orderBy('semimajor_axis')->get();
        $min = log10(max(1, (float) $planets->min('semimajor_axis')));
        $max = log10(max(1, (float) $planets->max('semimajor_axis')));

        // Each drone's body: a moon counts for its planet
        $counts = [];
        foreach (DB::table('agents as a')->leftJoin('objects as o', 'o.object_id', '=', 'a.object_id')
                     ->select('a.object_id', 'o.object_type', 'o.central_body_object_id', DB::raw('a.wrecked_at IS NULL AS working'))->get() as $d) {
            $body = ($d->object_type !== 'Planet' && $d->central_body_object_id) ? (int) $d->central_body_object_id : (int) $d->object_id;
            $counts[$body][$d->working ? 'working' : 'wrecks'] = ($counts[$body][$d->working ? 'working' : 'wrecks'] ?? 0) + 1;
        }

        $out = [];
        foreach ($planets as $p) {
            $longitude = deg2rad((float) $p->ascending_node_longitude + (float) $p->periapsis_argument
                + (float) $p->mean_anomaly + (float) $p->mean_angular_motion * $days);
            $radius = 30 + 250 * (log10(max(1, (float) $p->semimajor_axis)) - $min) / max(0.001, $max - $min);
            $out[] = [
                'id' => (int) $p->object_id, 'name' => $p->object_name,
                'x' => round(300 + $radius * cos($longitude), 1), 'y' => round(300 - $radius * sin($longitude), 1),
                'r' => round($radius, 1),
                'working' => $counts[$p->object_id]['working'] ?? 0, 'wrecks' => $counts[$p->object_id]['wrecks'] ?? 0,
            ];
        }
        return $out;
    }

    /** Places with drones, busiest first: name, working drones, wrecks */
    public static function places(): array
    {
        return array_map(fn ($r) => (array) $r, DB::select(
            'SELECT o.object_id, o.object_name, SUM(a.wrecked_at IS NULL) AS working, SUM(a.wrecked_at IS NOT NULL) AS wrecks
             FROM agents a JOIN objects o ON o.object_id = a.object_id GROUP BY o.object_id, o.object_name
             ORDER BY working DESC, wrecks DESC LIMIT 50'));
    }
}
