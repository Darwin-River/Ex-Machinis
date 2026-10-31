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

        // Broadcasts (#380): what drones said, for all to see
        $said = DB::table('broadcasts as b')->leftJoin('agents as a', 'a.agent_id', '=', 'b.drone')
            ->orderBy('b.id', 'desc')->limit($limit)->get(['b.drone', 'b.game_t', 'b.body', 'a.name']);
        foreach ($said as $b) {
            $add((int) $b->game_t, ($b->name ?? 'A drone') . ' broadcast: "' . $b->body . '"', 'spacecraft/' . $b->drone);
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

        usort($items, fn ($a, $b) => $b['game_t'] <=> $a['game_t']);
        $items = array_slice($items, 0, $limit);
        foreach ($items as &$item) {
            $item['when'] = DronePage::when($item['game_t']);
        }
        return $items;
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
        $open = $type === 4 ? 'IFNULL(i.units, 0) - e.new_quantity > 0' : 'e.new_quantity - IFNULL(i.units, 0) > 0';
        $best = $type === 4 ? 'ASC' : 'DESC';
        $rows = DB::select(
            "SELECT * FROM (
               SELECT e.drone, a.name AS drone_name, e.resource, r.name AS resource_name, e.new_credits AS price,
                      ABS(IFNULL(i.units, 0) - e.new_quantity) AS units, o.object_name AS place,
                      ROW_NUMBER() OVER (PARTITION BY e.resource, a.object_id ORDER BY e.new_credits $best, e.id) AS n
               FROM events e
               JOIN (SELECT MAX(id) AS id FROM events WHERE event_type = ? AND outcome = 1 GROUP BY drone, resource) l ON l.id = e.id
               JOIN agents a ON a.agent_id = e.drone AND a.wrecked_at IS NULL
               LEFT JOIN resources r ON r.id = e.resource
               LEFT JOIN objects o ON o.object_id = a.object_id
               LEFT JOIN sqInventory i ON i.drone = e.drone AND i.resource = e.resource
               WHERE $open) b
             WHERE n = 1 ORDER BY resource, place LIMIT " . (int) $limit,
            [$type]);
        return array_map(function ($r) { $r = (array) $r; unset($r['n']); return $r; }, $rows);
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
