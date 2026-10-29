<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * The public view of the world (#324 step 17): the Home page's event feed,
 * the Market page (the House station drones' live orders, the order book
 * and recent trades) and the Map (where the planets are now, and
 * the drones and wrecks around each). Visitors see everything at once; only
 * drones are limited to what is local.
 */
class World
{
    public const STATION_BUYS = 1;
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

    /** A real time (a row's created_at) as game time */
    private static function gameTimeOf(string $real): int
    {
        $clock = DB::table('game_clock')->where('id', 1)->first();
        $t = strtotime($real);
        return $clock ? (int) round($clock->game_anchor + ($t - $clock->real_anchor) * $clock->scale) : $t;
    }

    /**
     * What happened lately, newest first: [game_t, when, text, link]. Each
     * kind is read on its own (the latest few), then they are merged.
     */
    public static function feed(int $limit = 30): array
    {
        $items = [];
        $add = function (int $t, string $text, ?string $link = null) use (&$items) {
            $items[] = ['game_t' => $t, 'text' => $text, 'link' => $link];
        };

        foreach (DB::table('releases as r')->join('companies as c', 'c.company_id', '=', 'r.company_id')
                     ->join('agents as a', 'a.agent_id', '=', 'r.agent_id')
                     ->select('r.created_at', 'c.name as company', 'a.name', 'a.agent_id', 'r.copied_from', 'r.same_as')
                     ->orderBy('r.id', 'desc')->limit($limit)->get() as $r) {
            $how = $r->copied_from ? ', with code copied from drone ' . $r->copied_from : '';
            $add(self::gameTimeOf($r->created_at), "{$r->company} released a new drone, {$r->name}{$how}.", 'spacecraft/' . $r->agent_id);
        }

        $launches = DB::table('events')->where('event_type', 8)->where('outcome', 1)->orderBy('id', 'desc')->limit($limit)->get();
        $names = self::names('agents', 'agent_id', 'name', array_merge($launches->pluck('drone')->all(), $launches->pluck('new_quantity')->all()));
        foreach ($launches as $e) {
            $add((int) $e->game_t, ($names[$e->drone] ?? 'A drone') . ' launched a clone, ' . ($names[$e->new_quantity] ?? 'a new drone') . '.',
                'spacecraft/' . $e->new_quantity);
        }

        $why = ['fuel' => 'it ran out of fuel', 'crashes' => 'its program kept crashing', 'tax' => "its company couldn't pay its tax"];
        foreach (DB::table('agents')->whereNotNull('wrecked_at')->orderBy('wrecked_at', 'desc')->limit($limit)->get() as $a) {
            $add((int) $a->wrecked_at, "{$a->name} became a wreck: " . ($why[$a->wreck_cause] ?? 'it died') . '.', 'spacecraft/' . $a->agent_id);
        }

        $plundered = DB::table('agents')->whereNotNull('plundered_at')->orderBy('plundered_at', 'desc')->limit($limit)->get();
        $names = self::names('agents', 'agent_id', 'name', $plundered->pluck('plundered_by')->all());
        foreach ($plundered as $a) {
            $add((int) $a->plundered_at, ($names[$a->plundered_by] ?? 'A drone') . " plundered the wreck of {$a->name}.", 'spacecraft/' . $a->agent_id);
        }

        $ends = ['tax' => "couldn't pay its tax and was eliminated", 'drones' => 'lost its last drone'];
        foreach (DB::table('companies')->whereNotNull('eliminated_at')->orderBy('eliminated_at', 'desc')->limit($limit)->get() as $c) {
            $add((int) $c->eliminated_at, "{$c->name} " . ($ends[$c->end_cause] ?? 'ended') . '.');
        }

        $trades = DB::table('trades')->whereNotNull('game_t')->orderBy('id', 'desc')->limit($limit)->get();
        $drones = self::names('agents', 'agent_id', 'name', array_merge($trades->pluck('seller')->all(), $trades->pluck('buyer')->all()));
        $resources = self::names('resources', 'id', 'name', $trades->pluck('resource')->all());
        foreach ($trades as $t) {
            $add((int) $t->game_t, ($drones[$t->buyer] ?? 'A drone') . " bought {$t->units} " . ($resources[$t->resource] ?? 'units')
                . ' from ' . ($drones[$t->seller] ?? 'another drone') . ' for ' . number_format($t->credits) . ' credits.');
        }

        $batches = DB::table('station_batches as b')->join('stations as s', 's.id', '=', 'b.station_id')
            ->where('b.filled', '>', 0)->select('b.*', 's.name as station')->orderBy('b.cleared_t', 'desc')->limit($limit)->get();
        $resources = self::names('resources', 'id', 'name', $batches->pluck('resource')->all());
        foreach ($batches as $b) {
            $verb = (int) $b->side === self::STATION_BUYS ? 'bought' : 'sold';
            $add((int) $b->cleared_t, "The {$b->station} {$verb} {$b->filled} " . ($resources[$b->resource] ?? 'units') . " at {$b->price} a unit.", 'market');
        }

        usort($items, fn ($a, $b) => $b['game_t'] <=> $a['game_t']);
        $items = array_slice($items, 0, $limit);
        foreach ($items as &$item) {
            $item['when'] = DronePage::when($item['game_t']);
        }
        return $items;
    }

    /**
     * The House stations (#380): each one's station drone and the goods it
     * trades now, with the price of its order (null until it has posted
     * one), what it holds, and how many its order takes (buying) or has for
     * sale (selling)
     */
    public static function stations(): array
    {
        $epoch = (int) DB::table('game_clock')->where('id', 1)->value('epoch');
        $today = intdiv(max(0, self::gameNow() - $epoch), 86400);
        $out = [];
        foreach (DB::table('stations as s')->leftJoin('objects as o', 'o.object_id', '=', 's.object_id')
                     ->select('s.*', 'o.object_name')->orderBy('s.id')->get() as $s) {
            $drone = DB::table('agents')->where('station_id', $s->id)->first(['agent_id', 'name']);
            $goods = [];
            foreach (DB::table('station_goods as g')->join('resources as r', 'r.id', '=', 'g.resource')
                         ->where('g.station_id', $s->id)->where('g.from_day', '<=', $today)
                         ->select('g.*', 'r.name')->orderBy('g.side')->orderBy('g.resource')->get() as $g) {
                $buys = (int) $g->side === self::STATION_BUYS;
                $order = null;
                $stock = 0;
                if ($drone) {
                    // Its latest order for the good: buy orders are event type 5, sell orders 4
                    $order = DB::table('events')->where('drone', $drone->agent_id)->where('resource', $g->resource)
                        ->where('event_type', $buys ? 5 : 4)->where('outcome', 1)->orderBy('id', 'desc')
                        ->first(['new_quantity', 'new_credits']);
                    $stock = (int) DB::table('sqInventory')->where('drone', $drone->agent_id)
                        ->where('resource', $g->resource)->max('units');
                }
                $room = $order ? max(0, $buys ? (int) $order->new_quantity - $stock : $stock - (int) $order->new_quantity) : 0;
                $goods[] = [
                    'resource' => (int) $g->resource, 'name' => $g->name, 'buys' => $buys,
                    'listed' => (int) $g->price, 'daily' => (int) $g->daily_units,
                    'price' => $order ? (int) $order->new_credits : null, 'stock' => $stock, 'room' => $room,
                ];
            }
            $out[] = ['name' => $s->name, 'place' => $s->object_name, 'object_id' => (int) $s->object_id,
                'drone' => $drone ? (int) $drone->agent_id : null, 'goods' => $goods];
        }
        return $out;
    }

    /** Recent trades, newest first */
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
     * The open orders (sell: event type 4, stock above the kept minimum; buy:
     * 5, stock below the wanted maximum), best price first
     */
    public static function orders(int $type, int $limit = 25): array
    {
        $open = $type === 4 ? 'IFNULL(i.units, 0) - e.new_quantity > 0' : 'e.new_quantity - IFNULL(i.units, 0) > 0';
        $rows = DB::select(
            "SELECT e.drone, a.name AS drone_name, e.resource, r.name AS resource_name, e.new_credits AS price,
                    ABS(IFNULL(i.units, 0) - e.new_quantity) AS units, o.object_name AS place
             FROM events e
             JOIN (SELECT MAX(id) AS id FROM events WHERE event_type = ? AND outcome = 1 GROUP BY drone, resource) l ON l.id = e.id
             JOIN agents a ON a.agent_id = e.drone AND a.wrecked_at IS NULL
             LEFT JOIN resources r ON r.id = e.resource
             LEFT JOIN objects o ON o.object_id = a.object_id
             LEFT JOIN sqInventory i ON i.drone = e.drone AND i.resource = e.resource
             WHERE $open ORDER BY e.resource, e.new_credits " . ($type === 4 ? 'ASC' : 'DESC') . ' LIMIT ' . (int) $limit,
            [$type]);
        return array_map(fn ($r) => (array) $r, $rows);
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
        $stations = DB::table('stations')->pluck('name', 'object_id')->all();

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
            $here = $stations[$p->object_id] ?? null;
            $moonStations = DB::table('stations as s')->join('objects as o', 'o.object_id', '=', 's.object_id')
                ->where('o.central_body_object_id', $p->object_id)->pluck('s.name')->all();
            $out[] = [
                'id' => (int) $p->object_id, 'name' => $p->object_name,
                'x' => round(300 + $radius * cos($longitude), 1), 'y' => round(300 - $radius * sin($longitude), 1),
                'r' => round($radius, 1),
                'working' => $counts[$p->object_id]['working'] ?? 0, 'wrecks' => $counts[$p->object_id]['wrecks'] ?? 0,
                'stations' => array_values(array_filter(array_merge([$here], $moonStations))),
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
