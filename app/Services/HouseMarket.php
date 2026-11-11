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
     * and the game hours until it refills. As wanted() and query 706: it buys
     * up to a day's demand above what it keeps to sell, its bid falls to half
     * the listed price at a full day's demand bought, and what it bought goes
     * down to the surface at the start of the next game day (station.lua).
     * Rows: place, place_name, resource, resource_name, price, left, daily,
     * listed, hours
     */
    public static function demand(?int $resource = null, ?int $place = null): array
    {
        $db = DB::connection();
        $today = self::today($db);
        $epoch = (float) ($db->table('game_clock')->where('id', 1)->value('epoch') ?? 0);
        $hours = (int) max(0, ceil(($epoch + ($today + 1) * 86400 - self::gameNow($db)) / 3600));
        $stations = $db->table('agents AS a')->join('stations AS s', 's.id', '=', 'a.station_id')
            ->leftJoin('objects AS o', 'o.object_id', '=', 's.object_id')->whereNull('a.wrecked_at')
            ->when($place !== null, fn ($q) => $q->where('s.object_id', $place))
            ->get(['a.agent_id', 's.id AS station_id', 's.object_id', 'o.object_name']);
        $names = $db->table('resources')->pluck('name', 'id')->all();
        $out = [];
        foreach ($stations as $st) {
            $goods = $db->table('house_market')->where('station_id', $st->station_id)->where('from_day', '<=', $today)
                ->when($resource !== null, fn ($q) => $q->where('resource', $resource))
                ->get(['resource', 'side', 'price', 'daily_units']);
            $keep = [];
            foreach ($goods as $g) {
                if ((int) $g->side === 2) {
                    $keep[(int) $g->resource] = max(1, intdiv(max(1, (int) $g->daily_units), 4));
                }
            }
            $held = $db->table('holdings')->where('drone', $st->agent_id)->whereIn('installed', [0, 1])->whereIn('locked', [0, 1])
                ->when($resource !== null, fn ($q) => $q->where('resource', $resource))
                ->groupBy('resource')->selectRaw('resource, SUM(units) AS units')->pluck('units', 'resource')->all();
            foreach ($goods as $g) {
                if ((int) $g->side !== 1) {
                    continue;
                }
                $r = (int) $g->resource;
                $daily = max(1, (int) $g->daily_units);
                $listed = (int) $g->price;
                $bought = min(max(0, (int) ($held[$r] ?? 0) - ($keep[$r] ?? 0)), $daily);
                $out[] = ['place' => (int) $st->object_id, 'place_name' => $st->object_name, 'resource' => $r,
                    'resource_name' => $names[$r] ?? "Resource $r", 'price' => max(1, $listed - intdiv($listed * $bought, 2 * $daily)),
                    'left' => $daily - $bought, 'daily' => $daily, 'listed' => $listed, 'hours' => $hours];
            }
        }
        usort($out, fn ($a, $b) => [$a['resource'], $b['price'], $a['place']] <=> [$b['resource'], $a['price'], $b['place']]);
        return $out;
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
