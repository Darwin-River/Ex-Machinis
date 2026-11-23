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
 *   day's demand, for up to a day's demand above the stock it keeps to sell;
 *   beyond that, a quarter of a day's demand more at a time, at a bid
 *   falling on from half (#691), so it never stops buying.
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
        // The House's orders are reposted in their own fast loop (refreshNow, #764),
        // unless the scheduler hasn't one yet (an old scheduler: then here)
        try {
            return \Illuminate\Support\Facades\Cache::has('house-loop-alive') ? 0 : self::refreshNow();
        } finally {
            // Each in its own try, so one failing doesn't stop the others
            // (#710: the drones' house_supply must not wait on the record)
            try {
                Leaderboard::dailyIfDue();
            } catch (\Throwable $e) {
                report($e);
            }
            // What the House sells and when it restocks, for drones (#630)
            try {
                self::storeSupply();
            } catch (\Throwable $e) {
                report($e);
            }
            // Each good's best House bid today, for cash-out's 7-day average (#685)
            try {
                PlayerShares::recordBestBids();
            } catch (\Throwable $e) {
                report($e);
            }
            // Do credits balance? Once a game day (#937)
            try {
                CreditCheck::checkIfDue();
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
    /**
     * The House's own loop (#764): reposts its orders every real second, at
     * most one run at a time. On the tick (every 5 real seconds plus its other
     * work: 2.5 game hours or more at 1,800x) the bids lagged the half-day
     * price steps (Mars Metals bid 10 against a listed 21), read 0/0 once
     * filled, and showed `left` far above `daily` after a shipment, until the
     * next repost. Returns the orders posted, or -1 when another run holds it
     */
    public static function refreshNow(): int
    {
        $lock = \Illuminate\Support\Facades\Cache::lock('house-refresh', 30);
        if (!$lock->get()) {
            return -1;
        }
        try {
            return self::refresh();
        } finally {
            $lock->release();
        }
    }

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
        // Units on their way in (its own imports, sales into it not yet
        // settled): the order book counts them as held (ApiController::movable),
        // so a target that left them out read `left` 0 until they landed (#739)
        foreach (self::incoming($db, $agentId) as $r => $n) {
            $units[$r] = ($units[$r] ?? 0) + $n;
        }
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
            $held = $units[$resource] ?? 0;
            $bought = max(0, $held - $keep);
            // Its lowest bid (#739): half the listed price
            $floor = self::bidFloor($price);
            if ($bought < $daily) {
                $bid = max($floor, $price - intdiv($price * $bought, 2 * $daily));
                $wanted[HouseStations::BUY_ORDER_EVENT][$resource] = [$daily + $keep, $bid];
                continue;
            }
            // Overstocked (#691): its station ships what it bought down once
            // a game day, so it can hold more than a day's demand, and its own
            // unsold stock counts too. It still takes a quarter of a day's
            // demand more at a time, at half the listed price: until #739 the
            // bid fell on below half (a quarter at two days' worth, ...), and
            // round 71 found Mars bidding 2 to 5 for metals and Earth 67 for
            // rods listed at 181, so startups sold for nothing
            $wanted[HouseStations::BUY_ORDER_EVENT][$resource] = [$held + max(1, intdiv($daily, 4)), $floor];
        }
        return $wanted;
    }

    /** The lowest the House bids for a good listed at $price (#739): half, at least 1 */
    public static function bidFloor(int $price): int
    {
        return max(1, intdiv($price + 1, 2));
    }

    /**
     * Units on their way into a drone's free stock, by resource: its pending
     * increments, as the order book counts them (ApiController::movable)
     */
    public static function incoming($db, int $agentId): array
    {
        // events_drone_game_t (drone, outcome, game_t): only its pending events
        $rows = $db->select('SELECT e.resource, ABS(SUM(e.new_quantity)) AS n FROM events AS e FORCE INDEX (events_drone_game_t)
            WHERE e.drone = ? AND e.installed = 0 AND e.locked = 0 AND e.outcome = 0 AND e.event_type = 1
              AND NOT EXISTS (SELECT 1 FROM actions AS x WHERE x.id = e.action AND x.aborted = 1)
            GROUP BY e.resource', [$agentId]);
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->resource] = (int) $r->n;
        }
        return $out;
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
                // In dates, not day numbers (#838: "days 6080 to 6088")
                'text' => sprintf('%s: a %s in %s (%s): the House %s %s %d%%, %s.',
                    $r->name, $kind, $tier, $buys ? 'demand' : 'supply',
                    $buys ? 'bids' : 'asks', $shock > 0 ? 'up' : 'down', abs((int) round($shock * 100)),
                    GameDate::span($from, $from + 8)),
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
     * Since #691 an overstocked House (a full day's demand bought) still
     * takes a quarter of a day's demand more at a time, at a bid below half
     * its listed price, so `left` is 0 only between its tick's postings.
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

    /** Game days of a station's restocking that its figures look back over (#835: a round takes days) */
    public const LOOK_DAYS = 10;
    /** The program's wait between rounds (resources/house/station.lua: wait(60)) */
    public const WAIT_PROTOCOL = 3;
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
     * if not in LOOK_DAYS game days), round_hours (how often its rounds come:
     * the median gap between the waits that end them, over LOOK_DAYS game
     * days; null with fewer than two) and next_hours (game hours until its next round is
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
     * A station drone's restocking over the last LOOK_DAYS game days: [last
     * import of each resource (game time), round interval in game hours or
     * null, game hours until its next round or null]. A round ends with the
     * program's wait (resources/house/station.lua), so the waits mark the
     * rounds. Until #835 a round was any import more than half a game hour
     * after the one before; but each of a station's actions takes about a
     * game hour, so nearly every import started a "round", and the page said
     * "about every 1 game hour" while a real round took 2 to 8 game days.
     */
    public static function restocks(int $drone, int $now): array
    {
        $from = $now - self::LOOK_DAYS * 86400;
        $times = DB::select('SELECT e.resource, e.game_t FROM events AS e FORCE INDEX (events_drone_game_t)
            JOIN actions AS x ON x.id = e.action AND x.protocol = ?
            WHERE e.drone = ? AND e.outcome = 1 AND e.game_t > ? AND e.game_t <= ? AND e.event_type = 1
            ORDER BY e.game_t', [self::IMPORT_PROTOCOL, $drone, $from, $now]);
        $last = [];
        foreach ($times as $t) {
            $last[(int) $t->resource] = (int) $t->game_t;
        }
        $ends = array_map(fn ($w) => (int) $w->t, DB::select('SELECT MIN(e.game_t) AS t FROM events AS e FORCE INDEX (events_drone_game_t)
            JOIN actions AS x ON x.id = e.action AND x.protocol = ?
            WHERE e.drone = ? AND e.outcome = 1 AND e.game_t > ? AND e.game_t <= ?
            GROUP BY e.action ORDER BY t', [self::WAIT_PROTOCOL, $drone, $from, $now]));
        if (count($ends) < 2) {
            return [$last, null, null];
        }
        $gaps = [];
        for ($i = 1; $i < count($ends); $i++) {
            $gaps[] = $ends[$i] - $ends[$i - 1];
        }
        sort($gaps);
        $mid = intdiv(count($gaps), 2);
        $every = count($gaps) % 2 ? $gaps[$mid] : intdiv($gaps[$mid - 1] + $gaps[$mid], 2);
        $next = max(0, end($ends) + $every - $now);
        return [$last, round($every / 3600, 1), round($next / 3600, 1)];
    }

    /**
     * Real seconds between stores of house_supply (#710). It was a real
     * minute, but at the normal pace that is more than a game day (one
     * every 48 real seconds): drones saw Mars's fuel on sale unchanged from
     * 12:11 to 22:41 while GET /house-supply moved, and the next round,
     * stored as a game time, long past (next_minutes 0). Now every 10 real
     * seconds (about 5 game hours): each store rewrites the table, and the
     * world was slowing as it grew, so not on every 5-second tick.
     */
    public const SUPPLY_EVERY = 10;

    /**
     * Stores supply() for the drones' query 707 (house_supply), on each of
     * the website's ticks (#630, #710). Returns whether it did.
     */
    public static function storeSupply(): bool
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('house_supply')) {
            return false;
        }
        $last = DB::table('house_supply')->max('updated_at');
        if ($last !== null && strtotime($last) > time() - self::SUPPLY_EVERY) {
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

    public static function today($db): int
    {
        $clock = $db->table('game_clock')->where('id', 1)->first();
        if (!$clock) {
            return 0;
        }
        return (int) floor((self::gameNow($db) - (float) ($clock->epoch ?? 0)) / 86400);
    }
}
