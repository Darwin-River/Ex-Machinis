<?php

namespace App\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Markets that move (#380 round 36): the House's back office. Each exchange's
 * prices and daily units move over game time, each exchange in its own way
 * (the house_market view, migration 0028). Its station drone posted its own
 * orders, one action each, and could post about 8 a game hour; a moving
 * market changes about 180 an exchange every half day. So the orders are
 * posted here, on the website's tick, as the drone's program did:
 * - what it sells: at today's price, all but its fuel reserve;
 * - what it buys: from today's price with no stock bought to half at a full
 *   day's demand, for up to a day's demand above the stock it keeps to sell.
 * Only orders whose price or quantity changed are posted. The drone's
 * program (resources/house/station.lua) keeps the stock and ships down what
 * it bought.
 */
class HouseMarket
{
    public const TIERS = [1 => 'ores', 2 => 'refined goods', 3 => 'parts', 4 => 'systems',
                          5 => 'modules', 6 => 'ship kits'];

    /**
     * The website's tick: the House's orders at today's prices, and each
     * company's net wealth once a game day (Leaderboard::dailyIfDue).
     * Returns the orders posted.
     */
    public static function tick(): int
    {
        // The daily record runs even when posting fails (#420: a deadlock
        // ended the tick before it, and company history stopped)
        try {
            return self::refresh();
        } finally {
            Leaderboard::dailyIfDue();
            // What the House sells and when it restocks, for drones (#630)
            try {
                self::storeSupply();
            } catch (\Throwable $e) {
                report($e);
            }
            // Code lineage (#616): new releases' and pushes' parents, a few each tick
            try {
                CodeLineage::catchUp();
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * Runs $work, again up to $tries times in all if MySQL picks it as a
     * deadlock's victim (#420): the engines write events all the time, and
     * at 1,800x the House's inserts sometimes lose the race.
     */
    public static function retryOnDeadlock(callable $work, int $tries = 3)
    {
        for ($try = 1; ; $try++) {
            try {
                return $work();
            } catch (QueryException $e) {
                if ($try >= $tries || !self::isDeadlock($e)) {
                    throw $e;
                }
                usleep(50000 * $try);
            }
        }
    }

    /** Whether MySQL gave up on a statement because of a deadlock or lock wait */
    public static function isDeadlock(QueryException $e): bool
    {
        $code = (int) ($e->errorInfo[1] ?? 0);
        return $code === 1213 || $code === 1205 || ($e->errorInfo[0] ?? $e->getCode()) === '40001';
    }

    /** Posts every station drone's changed orders; returns how many it posted */
    public static function refresh(?string $connection = null): int
    {
        $db = DB::connection($connection);
        $posted = 0;
        $drones = $db->table('agents')->whereNotNull('station_id')->whereNull('wrecked_at')
            ->get(['agent_id', 'station_id', 'object_id']);
        foreach ($drones as $drone) {
            try {
                $posted += self::post($db, (int) $drone->agent_id, (int) $drone->station_id, (int) $drone->object_id);
            } catch (QueryException $e) {
                // Still deadlocked after retries: this station waits for the
                // next tick, and the others carry on (#420)
                if (!self::isDeadlock($e)) {
                    throw $e;
                }
            }
        }
        return $posted;
    }

    /** The orders a station drone should have now: [type => [resource => [quantity, price]]] */
    public static function wanted($db, int $agentId, int $stationId): array
    {
        $today = self::today($db);
        $goods = $db->table('house_market')->where('station_id', $stationId)->where('from_day', '<=', $today)
            ->orderBy('resource')->orderBy('side')->get(['resource', 'side', 'price', 'daily_units']);
        $units = self::holdings($db, $agentId);
        $stock = [];
        foreach ($goods as $g) {
            if ((int) $g->side === 2) {
                $stock[(int) $g->resource] = max(1, intdiv(max(1, (int) $g->daily_units), 4));
            }
        }
        $wanted = [HouseStations::SELL_ORDER_EVENT => [], HouseStations::BUY_ORDER_EVENT => []];
        foreach ($goods as $g) {
            $resource = (int) $g->resource;
            $price = (int) $g->price;
            if ((int) $g->side === 2) {
                $keep = $resource === Release::FUEL ? HouseStations::FUEL_RESERVE : 0;
                $wanted[HouseStations::SELL_ORDER_EVENT][$resource] = [$keep, $price];
                continue;
            }
            $daily = max(1, (int) $g->daily_units);
            $keep = $stock[$resource] ?? 0;
            $bought = min(max(0, ($units[$resource] ?? 0) - $keep), $daily);
            $bid = max(1, $price - intdiv($price * $bought, 2 * $daily));
            $wanted[HouseStations::BUY_ORDER_EVENT][$resource] = [$daily + $keep, $bid];
        }
        return $wanted;
    }

    /** Posts a station drone's changed orders */
    public static function post($db, int $agentId, int $stationId, int $objectId): int
    {
        $wanted = self::wanted($db, $agentId, $stationId);
        $now = self::gameNow($db);
        $rows = [];
        foreach ($wanted as $type => $orders) {
            $current = self::orders($db, $agentId, $type);
            foreach ($orders as $resource => [$quantity, $price]) {
                if (($current[$resource] ?? null) === [$quantity, $price]) {
                    continue;
                }
                $rows[] = [
                    'event_type' => $type, 'action' => 0, 'logged' => 1, 'outcome' => 1, 'drone' => $agentId,
                    'resource' => $resource, 'installed' => 0, 'locked' => 0,
                    'new_quantity' => $quantity, 'new_credits' => $price, 'new_cargo' => 0,
                    'game_t' => $now, 'place' => $objectId,
                ];
            }
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            self::retryOnDeadlock(fn () => $db->table('events')->insert($chunk));
        }
        return count($rows);
    }

    /**
     * The booms and slumps under way (the news): by exchange, tier and side,
     * strongest first. A boom in demand: the House buys more, and pays more;
     * a shortage: it sells less, and asks more; a slump and a glut the other
     * way. Each runs for 8 game days, strongest in the middle.
     */
    public static function news(?string $connection = null): array
    {
        $db = DB::connection($connection);
        $rows = $db->table('house_market as m')->join('stations as s', 's.id', '=', 'm.station_id')
            ->where('m.shock', '<>', 0)
            ->groupBy('s.id', 's.name', 'tier', 'm.side', 'm.day')
            ->orderByRaw('MAX(ABS(m.shock)) DESC')
            ->get([DB::raw('s.id'), 's.name', DB::raw('FLOOR(m.resource / 1000) AS tier'), 'm.side', 'm.day',
                   DB::raw('MAX(m.shock) AS up'), DB::raw('MIN(m.shock) AS down')]);
        $news = [];
        foreach ($rows as $r) {
            $shock = (float) $r->up > 0 ? (float) $r->up : (float) $r->down;
            $buys = (int) $r->side === 1;
            $kind = $buys ? ($shock > 0 ? 'boom' : 'slump') : ($shock > 0 ? 'shortage' : 'glut');
            $tier = self::TIERS[(int) $r->tier] ?? 'goods';
            $from = (int) (floor((float) $r->day / 8) * 8);
            $news[] = [
                'place' => $r->name,
                'tier' => $tier,
                'side' => $buys ? 'demand' : 'supply',
                'kind' => $kind,
                'percent_now' => (int) round($shock * 100),
                'from_day' => $from,
                'to_day' => $from + 8,
                'text' => sprintf('%s: a %s in %s (%s): the House %s %s %d%%, days %d to %d.',
                    $r->name, $kind, $tier, $buys ? 'demand' : 'supply',
                    $buys ? 'bids' : 'asks', $shock > 0 ? 'up' : 'down', abs((int) round($shock * 100)),
                    $from, $from + 8),
            ];
        }
        return $news;
    }

    /**
     * The House's demand (#550): for each exchange and good it buys, its bid
     * now, the units it still takes today, its day's demand, its listed price
     * and the game hours until it refills. Since #608 the bid and the units
     * come from the station drone's live buy order, as the order book counts
     * them (ApiController::movable): round 63's Xavi flew rods to Earth and
     * the Moon on a demand worked out from the House's holdings while its
     * posted order wanted none. Also the units sold into it today (`sold`),
     * by `$company` (`mine`), and the game hours until it runs out at
     * today's pace (`empty_hours`, null when nothing has sold today).
     * Rows: place, place_name, resource, resource_name, price, left, daily,
     * listed, hours, sold, mine, empty_hours
     */
    public static function demand(?int $resource = null, ?int $place = null, ?int $company = null): array
    {
        $db = DB::connection();
        $today = self::today($db);
        $now = self::gameNow($db);
        $epoch = (float) ($db->table('game_clock')->where('id', 1)->value('epoch') ?? 0);
        $dayStart = (int) ($epoch + $today * 86400);
        $hours = (int) max(0, ceil(($dayStart + 86400 - $now) / 3600));
        $elapsed = max(1, $now - $dayStart);
        $stations = $db->table('agents AS a')->join('stations AS s', 's.id', '=', 'a.station_id')
            ->leftJoin('objects AS o', 'o.object_id', '=', 's.object_id')->whereNull('a.wrecked_at')
            ->when($place !== null, fn ($q) => $q->where('s.object_id', $place))
            ->get(['a.agent_id', 's.id AS station_id', 's.object_id', 'o.object_name']);
        $names = $db->table('resources')->pluck('name', 'id')->all();
        $movable = \App\Http\Controllers\ApiController::movable(HouseStations::BUY_ORDER_EVENT);
        $out = [];
        foreach ($stations as $st) {
            $goods = $db->table('house_market')->where('station_id', $st->station_id)->where('from_day', '<=', $today)
                ->where('side', 1)->when($resource !== null, fn ($q) => $q->where('resource', $resource))
                ->get(['resource', 'price', 'daily_units']);
            if ($goods->isEmpty()) {
                continue;
            }
            $orders = collect($db->select("SELECT o.resource, o.price, o.quantity, $movable AS movable FROM latest_orders AS o
                WHERE o.drone = ? AND o.event_type = ?" . ($resource !== null ? ' AND o.resource = ?' : ''),
                array_values(array_filter([(int) $st->agent_id, HouseStations::BUY_ORDER_EVENT, $resource], fn ($v) => $v !== null))))
                ->keyBy('resource');
            $sold = $db->table('trades AS t')->leftJoin('agents AS s', 's.agent_id', '=', 't.seller')
                ->where('t.buyer', $st->agent_id)->where('t.game_t', '>=', $dayStart)
                ->when($resource !== null, fn ($q) => $q->where('t.resource', $resource))->groupBy('t.resource')
                ->selectRaw('t.resource, SUM(t.units) AS units, SUM(IF(s.company_id = ?, t.units, 0)) AS mine', [$company ?? 0])
                ->get()->keyBy('resource');
            foreach ($goods as $g) {
                $r = (int) $g->resource;
                $o = $orders[$r] ?? null;
                $left = $o ? max(0, (int) $o->quantity - (int) $o->movable) : 0;
                $soldToday = (int) ($sold[$r]->units ?? 0);
                $out[] = ['place' => (int) $st->object_id, 'place_name' => $st->object_name, 'resource' => $r,
                    'resource_name' => $names[$r] ?? "Resource $r",
                    'price' => $left > 0 ? (int) $o->price : 0, 'left' => $left,
                    'daily' => max(1, (int) $g->daily_units), 'listed' => (int) $g->price, 'hours' => $hours,
                    'sold' => $soldToday, 'mine' => (int) ($sold[$r]->mine ?? 0),
                    'empty_hours' => $soldToday > 0 && $left > 0 ? (int) ceil($left * $elapsed / $soldToday / 3600) : null];
            }
        }
        usort($out, fn ($a, $b) => [$a['resource'], $b['price'], $a['place']] <=> [$b['resource'], $a['price'], $b['place']]);
        return $out;
    }

    /** Imports this far apart (game seconds) start a new round of the station's restocking */
    public const ROUND_GAP = 1800;
    /** Import Resource (resources/house/station.lua: perform(5911, n, resource)) */
    public const IMPORT_PROTOCOL = 5911;

    /**
     * What the House sells (#630, the other side of demand): at each
     * exchange and good it sells, best price first: place, place_name,
     * resource, resource_name, price (0 when none is on sale), on_sale (the
     * units a pull can take now, its sell order counted as the order book
     * counts it), refill_to (what each round tops it back up to: a quarter of
     * the day's supply), daily (the day's supply), sold (units sold today),
     * last_restock_hours (game hours since it last imported this good; null
     * if not in two game days), round_hours (how often its rounds come: the
     * median gap between rounds of imports over two game days; null with
     * fewer than three) and next_hours (game hours until its next round is
     * due, 0 when due now; null without round_hours). A good that sells out
     * is back, up to refill_to, at the next round.
     */
    public static function supply(?int $resource = null, ?int $place = null): array
    {
        $db = DB::connection();
        $today = self::today($db);
        $now = self::gameNow($db);
        $epoch = (float) ($db->table('game_clock')->where('id', 1)->value('epoch') ?? 0);
        $dayStart = (int) ($epoch + $today * 86400);
        $stations = $db->table('agents AS a')->join('stations AS s', 's.id', '=', 'a.station_id')
            ->leftJoin('objects AS o', 'o.object_id', '=', 's.object_id')->whereNull('a.wrecked_at')
            ->when($place !== null, fn ($q) => $q->where('s.object_id', $place))
            ->get(['a.agent_id', 's.id AS station_id', 's.object_id', 'o.object_name']);
        $names = $db->table('resources')->pluck('name', 'id')->all();
        $movable = \App\Http\Controllers\ApiController::movable(HouseStations::SELL_ORDER_EVENT);
        $out = [];
        foreach ($stations as $st) {
            $goods = $db->table('house_market')->where('station_id', $st->station_id)->where('from_day', '<=', $today)
                ->where('side', 2)->when($resource !== null, fn ($q) => $q->where('resource', $resource))
                ->get(['resource', 'price', 'daily_units']);
            if ($goods->isEmpty()) {
                continue;
            }
            $orders = collect($db->select("SELECT o.resource, o.price, o.quantity, $movable AS movable FROM latest_orders AS o
                WHERE o.drone = ? AND o.event_type = ? AND o.is_open = 1" . ($resource !== null ? ' AND o.resource = ?' : ''),
                array_values(array_filter([(int) $st->agent_id, HouseStations::SELL_ORDER_EVENT, $resource], fn ($v) => $v !== null))))
                ->keyBy('resource');
            $sold = $db->table('trades')->where('seller', $st->agent_id)->where('game_t', '>=', $dayStart)
                ->when($resource !== null, fn ($q) => $q->where('resource', $resource))->groupBy('resource')
                ->selectRaw('resource, SUM(units) AS units')->pluck('units', 'resource')->all();
            [$last, $roundHours, $nextHours] = self::restocks((int) $st->agent_id, $now);
            foreach ($goods as $g) {
                $r = (int) $g->resource;
                $o = $orders[$r] ?? null;
                $onSale = $o ? max(0, (int) $o->movable - (int) $o->quantity) : 0;
                $daily = max(1, (int) $g->daily_units);
                $out[] = ['place' => (int) $st->object_id, 'place_name' => $st->object_name, 'resource' => $r,
                    'resource_name' => $names[$r] ?? "Resource $r",
                    'price' => $onSale > 0 ? (int) $o->price : 0, 'on_sale' => $onSale,
                    'refill_to' => max(1, intdiv($daily, 4)), 'daily' => $daily, 'sold' => (int) ($sold[$r] ?? 0),
                    'last_restock_hours' => isset($last[$r]) ? round(($now - $last[$r]) / 3600, 1) : null,
                    'round_hours' => $roundHours, 'next_hours' => $nextHours];
            }
        }
        usort($out, fn ($a, $b) => [$a['resource'], $a['price'] === 0, $a['price'], $a['place']]
            <=> [$b['resource'], $b['price'] === 0, $b['price'], $b['place']]);
        return $out;
    }

    /**
     * A station drone's restocking over the last two game days: [last import
     * of each resource (game time), round interval in game hours or null,
     * game hours until its next round or null]
     */
    public static function restocks(int $drone, int $now): array
    {
        $times = DB::select('SELECT e.resource, e.game_t FROM events AS e FORCE INDEX (events_drone_game_t)
            JOIN actions AS x ON x.id = e.action AND x.protocol = ?
            WHERE e.drone = ? AND e.game_t > ? AND e.game_t <= ? AND e.event_type = 1 AND e.outcome = 1
            ORDER BY e.game_t', [self::IMPORT_PROTOCOL, $drone, $now - 2 * 86400, $now]);
        $last = [];
        $starts = [];
        $prev = null;
        foreach ($times as $t) {
            $last[(int) $t->resource] = (int) $t->game_t;
            if ($prev === null || (int) $t->game_t - $prev > self::ROUND_GAP) {
                $starts[] = (int) $t->game_t;
            }
            $prev = (int) $t->game_t;
        }
        if (count($starts) < 3) {
            return [$last, null, null];
        }
        $gaps = [];
        for ($i = 1; $i < count($starts); $i++) {
            $gaps[] = $starts[$i] - $starts[$i - 1];
        }
        sort($gaps);
        $mid = intdiv(count($gaps), 2);
        $every = count($gaps) % 2 ? $gaps[$mid] : intdiv($gaps[$mid - 1] + $gaps[$mid], 2);
        $next = max(0, end($starts) + $every - $now);
        return [$last, round($every / 3600, 1), round($next / 3600, 1)];
    }

    /**
     * Stores supply() for the drones' query 707 (house_supply), once a real
     * minute, on the website's tick (#630). Returns whether it did.
     */
    public static function storeSupply(): bool
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('house_supply')) {
            return false;
        }
        $last = DB::table('house_supply')->max('updated_at');
        if ($last !== null && strtotime($last) > time() - 60) {
            return false;
        }
        $now = self::gameNow(DB::connection());
        $rows = array_map(fn ($r) => ['place' => $r['place'], 'resource' => $r['resource'], 'price' => $r['price'],
            'on_sale' => $r['on_sale'], 'refill_to' => $r['refill_to'], 'daily' => $r['daily'], 'sold' => $r['sold'],
            'last_restock_t' => $r['last_restock_hours'] === null ? null : (int) round($now - $r['last_restock_hours'] * 3600),
            'round_seconds' => $r['round_hours'] === null ? null : (int) round($r['round_hours'] * 3600),
            'next_round_t' => $r['next_hours'] === null ? null : (int) round($now + $r['next_hours'] * 3600),
            'updated_at' => now()], self::supply());
        DB::transaction(function () use ($rows) {
            DB::table('house_supply')->delete();
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('house_supply')->insert($chunk);
            }
        });
        return true;
    }

    /** What a drone holds, by resource */
    public static function holdings($db, int $agentId): array
    {
        $latest = $db->table('events')->where('drone', $agentId)->where('outcome', 1)->whereIn('event_type', [1, 2])
            ->groupBy('resource', 'installed', 'locked')->select(DB::raw('MAX(id) AS id'));
        $units = [];
        foreach ($db->table('events as e')->joinSub($latest, 'l', 'l.id', '=', 'e.id')->groupBy('e.resource')
                     ->selectRaw('e.resource, SUM(e.new_quantity) AS units')->get() as $row) {
            $units[(int) $row->resource] = (int) $row->units;
        }
        return $units;
    }

    /** A drone's latest order of a type, by resource: [quantity, price] */
    private static function orders($db, int $agentId, int $type): array
    {
        $latest = $db->table('events')->where('drone', $agentId)->where('outcome', 1)->where('event_type', $type)
            ->groupBy('resource')->select(DB::raw('MAX(id) AS id'));
        $orders = [];
        foreach ($db->table('events as e')->joinSub($latest, 'l', 'l.id', '=', 'e.id')
                     ->get(['e.resource', 'e.new_quantity', 'e.new_credits']) as $o) {
            $orders[(int) $o->resource] = [(int) $o->new_quantity, (int) $o->new_credits];
        }
        return $orders;
    }

    private static function gameNow($db): int
    {
        $clock = $db->table('game_clock')->where('id', 1)->first();
        return $clock ? (int) round($clock->game_anchor + (time() - $clock->real_anchor) * $clock->scale) : time();
    }

    private static function today($db): int
    {
        $clock = $db->table('game_clock')->where('id', 1)->first();
        if (!$clock) {
            return 0;
        }
        return (int) floor((self::gameNow($db) - (float) ($clock->epoch ?? 0)) / 86400);
    }
}
