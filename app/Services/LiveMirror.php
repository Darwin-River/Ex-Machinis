<?php

namespace App\Services;

use App\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The live market in the test world (#380 round 36): every newcomer found
 * the test world's market unlike the live one (no miners, nobody selling
 * metals at the Moon), so a plan built on the live market went out untested.
 * Now a "Live market" company in the test world keeps a drone at each place
 * where the live world has open orders, holding the goods and posting the
 * live world's best orders there: for each good, the cheapest sell order at
 * its price with the units all the live sellers there offer at that price
 * or more, and the dearest buy order likewise. It is refreshed every few
 * real minutes (on the website's tick), so what a test drone buys from it
 * comes back, and what it sells to it goes.
 */
class LiveMirror
{
    public const EMAIL = 'live-market@forge.invalid';
    public const NAME = 'Live market';
    public const HULL = 9001;               // Drop Station: room for anything
    public const CREDITS = 1000000000;
    public const MAX_UNITS = 500;           // most units of a good on one order
    public const REFRESH_SECONDS = 300;

    /** Mirrors the live market into the test world when it is due; whether it did */
    public static function refreshIfDue(): bool
    {
        if (!TestWorld::available()) {
            return false;
        }
        $last = (int) Cache::get('live-mirror-at', 0);
        if (time() - $last < self::REFRESH_SECONDS) {
            return false;
        }
        Cache::put('live-mirror-at', time(), 86400);
        self::refresh(DB::connection(), DB::connection(TestWorld::CONNECTION));
        return true;
    }

    /**
     * The live world's best open orders, by place and resource:
     * [place][resource] => ['sell' => [price, units], 'buy' => [price, units]]
     */
    public static function liveBook($live): array
    {
        $book = [];
        foreach ([4 => 'sell', 5 => 'buy'] as $type => $side) {
            $open = $type === 4 ? 'IFNULL(i.units, 0) - e.new_quantity' : 'e.new_quantity - IFNULL(i.units, 0)';
            $rows = $live->select(
                "SELECT a.object_id AS place, e.resource, e.new_credits AS price, $open AS units
                 FROM events e
                 JOIN (SELECT MAX(id) AS id FROM events WHERE event_type = ? AND outcome = 1 GROUP BY drone, resource) l ON l.id = e.id
                 JOIN agents a ON a.agent_id = e.drone AND a.wrecked_at IS NULL
                     AND a.company_id NOT IN (SELECT company_id FROM companies WHERE name = '" . self::NAME . "')
                 LEFT JOIN (SELECT i.drone, i.resource, SUM(i.new_quantity) AS units
                            FROM events i JOIN (SELECT MAX(id) AS id FROM events WHERE outcome = 1 AND event_type IN (1, 2)
                                                GROUP BY drone, resource, installed, locked) li ON li.id = i.id
                            WHERE i.installed = 0
                            GROUP BY i.drone, i.resource) i ON i.drone = e.drone AND i.resource = e.resource
                 WHERE $open > 0 AND e.new_credits > 0", [$type]);
            foreach ($rows as $r) {
                $place = (int) $r->place;
                $resource = (int) $r->resource;
                $price = (int) $r->price;
                $units = (int) $r->units;
                $best = $book[$place][$resource][$side] ?? null;
                $better = $best === null || ($side === 'sell' ? $price < $best[0] : $price > $best[0]);
                if ($better) {
                    $book[$place][$resource][$side] = [$price, $units];
                } elseif ($price === $best[0]) {
                    $book[$place][$resource][$side][1] += $units;
                }
            }
        }
        return $book;
    }

    /** Posts the live book in the test world; returns the orders it posted */
    public static function refresh($live, $test): int
    {
        $book = self::liveBook($live);
        $companyId = self::company($test);
        $now = self::gameNow($test);
        $drones = [];
        foreach ($test->table('agents')->where('company_id', $companyId)->get(['agent_id', 'object_id']) as $a) {
            $drones[(int) $a->object_id] = (int) $a->agent_id;
        }
        $places = array_unique(array_merge(array_keys($book), array_keys($drones)));
        $rows = [];
        foreach ($places as $place) {
            $drone = $drones[$place] ?? self::drone($test, $companyId, $place);
            $orders = $book[$place] ?? [];
            // Whatever it had is gone (to the live world), and what it had
            // bought: it starts each refresh with what the live sellers offer
            foreach (HouseMarket::holdings($test, $drone) as $resource => $units) {
                if ($units !== 0 && !isset($orders[$resource]['sell'])) {
                    $rows[] = self::event(1, $drone, $resource, 0, 0, $now, $place);
                }
            }
            $posted = self::posted($test, $drone);
            foreach ($posted as $type => $byResource) {
                foreach ($byResource as $resource => $_) {
                    $side = $type === 4 ? 'sell' : 'buy';
                    if (!isset($orders[$resource][$side])) {
                        // No longer on the live book: off the test one
                        $rows[] = self::event($type, $drone, $resource, $type === 4 ? self::MAX_UNITS * 10 : 0, 0, $now, $place);
                    }
                }
            }
            foreach ($orders as $resource => $sides) {
                if (isset($sides['sell'])) {
                    [$price, $units] = $sides['sell'];
                    $rows[] = self::event(1, $drone, $resource, min($units, self::MAX_UNITS), 0, $now, $place);
                    $rows[] = self::event(4, $drone, $resource, 0, $price, $now, $place);
                }
                if (isset($sides['buy'])) {
                    [$price, $units] = $sides['buy'];
                    if (!isset($sides['sell'])) {
                        $rows[] = self::event(1, $drone, $resource, 0, 0, $now, $place);
                    }
                    $have = isset($sides['sell']) ? min($sides['sell'][1], self::MAX_UNITS) : 0;
                    $rows[] = self::event(5, $drone, $resource, $have + min($units, self::MAX_UNITS), $price, $now, $place);
                }
            }
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            $test->table('events')->insert($chunk);
        }
        return count($rows);
    }

    /** The mirror's company in the test world (the leaderboard leaves it out) */
    private static function company($test): int
    {
        $userId = $test->table('users')->where('email', self::EMAIL)->value('user_id');
        if ($userId === null) {
            $userId = $test->table('users')->insertGetId(['name' => self::NAME, 'email' => self::EMAIL,
                'password' => bcrypt(bin2hex(random_bytes(16)))]);
        }
        $companyId = $test->table('companies')->where('user_id', $userId)->value('company_id');
        if ($companyId === null) {
            $companyId = $test->table('companies')->insertGetId(['user_id' => $userId, 'name' => self::NAME,
                'credits' => self::CREDITS, 'house' => 1]);
        }
        $test->table('companies')->where('company_id', $companyId)->update(['credits' => self::CREDITS]);
        return (int) $companyId;
    }

    /** A mirror drone at a place: it runs no program, it only holds goods and orders */
    private static function drone($test, int $companyId, int $place): int
    {
        $userId = (int) $test->table('companies')->where('company_id', $companyId)->value('user_id');
        $id = $test->table('agents')->insertGetId(['name' => self::NAME, 'user_id' => $userId,
            'company_id' => $companyId, 'object_id' => $place, 'hull_type' => self::HULL]);
        return (int) $id;
    }

    /** Its latest orders: [type => [resource => true]] (only those still open) */
    private static function posted($test, int $drone): array
    {
        $posted = [4 => [], 5 => []];
        foreach ([4, 5] as $type) {
            $latest = $test->table('events')->where('drone', $drone)->where('event_type', $type)->where('outcome', 1)
                ->groupBy('resource')->select(DB::raw('MAX(id) AS id'));
            foreach ($test->table('events as e')->joinSub($latest, 'l', 'l.id', '=', 'e.id')
                         ->where('e.new_credits', '>', 0)->pluck('e.resource') as $resource) {
                $posted[$type][(int) $resource] = true;
            }
        }
        return $posted;
    }

    private static function event(int $type, int $drone, int $resource, int $quantity, int $price, int $now, int $place): array
    {
        return ['event_type' => $type, 'action' => 0, 'logged' => 1, 'outcome' => 1, 'drone' => $drone,
            'resource' => $resource, 'installed' => 0, 'locked' => 0, 'new_quantity' => $quantity,
            'new_credits' => $price, 'new_cargo' => 0, 'game_t' => $now, 'place' => $place];
    }

    private static function gameNow($db): int
    {
        $clock = $db->table('game_clock')->where('id', 1)->first();
        return $clock ? (int) round($clock->game_anchor + (time() - $clock->real_anchor) * $clock->scale) : time();
    }
}
