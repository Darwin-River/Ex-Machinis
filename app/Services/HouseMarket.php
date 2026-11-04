<?php

namespace App\Services;

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
        $posted = self::refresh();
        Leaderboard::dailyIfDue();
        return $posted;
    }

    /** Posts every station drone's changed orders; returns how many it posted */
    public static function refresh(?string $connection = null): int
    {
        $db = DB::connection($connection);
        $posted = 0;
        $drones = $db->table('agents')->whereNotNull('station_id')->whereNull('wrecked_at')
            ->get(['agent_id', 'station_id', 'object_id']);
        foreach ($drones as $drone) {
            $posted += self::post($db, (int) $drone->agent_id, (int) $drone->station_id, (int) $drone->object_id);
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
            $db->table('events')->insert($chunk);
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
