<?php

namespace App\Services;

use App\Http\Controllers\ApiController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The Market page's goods board (#721): one row per good, with the cheapest
 * seller and the best buyer at each place, the best deal between places,
 * and the change in its average price over the last 7 game days. Round 70:
 * the page was a 28,000-pixel order table. Two queries, both on small
 * tables (latest_orders, and trades by resource and game time), never a scan
 * of the events; cached for a minute.
 */
class MarketBoard
{
    /** The filter chips: a tier (the first digit of the resource ID), fuel, or the most traded */
    public const FILTERS = ['traded' => 'Most traded', 'all' => 'All goods', 'ores' => 'Ores', 'refined' => 'Refined goods',
        'parts' => 'Parts', 'systems' => 'Systems', 'modules' => 'Modules', 'kits' => 'Ship kits', 'fuel' => 'Fuel'];

    /** The chips' tiers, as HouseMarket::TIERS numbers them */
    public const FILTER_TIERS = ['ores' => 1, 'refined' => 2, 'parts' => 3, 'systems' => 4, 'modules' => 5, 'kits' => 6];

    /** A tier's name on a board row */
    public const SHORT_TIERS = ['ores' => 'ore', 'refined goods' => 'refined', 'parts' => 'part', 'systems' => 'system',
        'modules' => 'module', 'ship kits' => 'ship kit', 'fuel' => 'fuel', 'goods' => 'good'];

    /** How many rows "Most traded" shows */
    public const MOST_TRADED = 20;

    /** Game days a price change looks back over */
    public const DAYS = 7;

    public const CACHE_SECONDS = 60;

    /** Every good with an open order, its rows built (cached for a minute) */
    public static function all(): array
    {
        return Cache::remember('market-board', self::CACHE_SECONDS, fn () => self::build());
    }

    /** The rows a chip shows */
    public static function filtered(string $filter): array
    {
        $rows = self::all();
        if ($filter === 'fuel') {
            return array_values(array_filter($rows, fn ($r) => $r['resource'] === Release::FUEL));
        }
        if (isset(self::FILTER_TIERS[$filter])) {
            return array_values(array_filter($rows, fn ($r) => $r['tier'] === self::FILTER_TIERS[$filter]));
        }
        if ($filter === 'traded') {
            $traded = array_values(array_filter($rows, fn ($r) => $r['credits'] > 0));
            return array_slice($traded ?: $rows, 0, self::MOST_TRADED);
        }
        return $rows;
    }

    /** One good's row, or null when nobody trades it */
    public static function row(int $resource): ?array
    {
        foreach (self::all() as $r) {
            if ($r['resource'] === $resource) {
                return $r;
            }
        }
        return null;
    }

    /** The board, worked out afresh */
    public static function build(): array
    {
        // 1. The best open order on each side for each good at each place:
        // what a pull or push can really move, as the order book counts it
        $sell = ApiController::movable(4);
        $buy = ApiController::movable(5);
        // (by whether the House posted it: a startup trades only with the House, #880)
        $book = DB::select("SELECT b.resource, r.name AS resource_name, b.place_id, p.object_name AS place, b.event_type, b.house,
                MIN(b.price) AS low, MAX(b.price) AS high, SUM(ABS(b.movable - b.quantity)) AS units
            FROM (SELECT o.resource, o.event_type, o.price, o.quantity, a.object_id AS place_id, IFNULL(c.house, 0) AS house,
                         IF(o.event_type = 4, $sell, $buy) AS movable
                  FROM latest_orders AS o
                  JOIN agents AS a ON a.agent_id = o.drone AND a.wrecked_at IS NULL
                  LEFT JOIN companies AS c ON c.company_id = a.company_id
                  WHERE o.event_type IN (4, 5) AND o.is_open = 1) AS b
            LEFT JOIN resources AS r ON r.id = b.resource
            LEFT JOIN objects AS p ON p.object_id = b.place_id
            WHERE (b.event_type = 4 AND b.movable > b.quantity) OR (b.event_type = 5 AND b.movable < b.quantity)
            GROUP BY b.resource, r.name, b.place_id, p.object_name, b.event_type, b.house");

        // The House's own best prices, a good at a place
        $house = [];
        foreach ($book as $o) {
            if ((int) $o->house !== 1) {
                continue;
            }
            $id = (int) $o->resource;
            if ((int) $o->event_type === 4 && (!isset($house[$id]['ask']) || (int) $o->low < $house[$id]['ask']['price'])) {
                $house[$id]['ask'] = ['place' => $o->place ?? 'Unknown', 'price' => (int) $o->low, 'units' => (int) $o->units];
            }
            if ((int) $o->event_type === 5 && (!isset($house[$id]['bid']) || (int) $o->high > $house[$id]['bid']['price'])) {
                $house[$id]['bid'] = ['place' => $o->place ?? 'Unknown', 'price' => (int) $o->high, 'units' => (int) $o->units];
            }
        }
        // Every order, the House's and players', merged a place at a time
        $merged = [];
        foreach ($book as $o) {
            $k = $o->resource . ':' . $o->place_id . ':' . $o->event_type;
            if (!isset($merged[$k])) {
                $merged[$k] = clone $o;
                continue;
            }
            $merged[$k]->low = min((int) $merged[$k]->low, (int) $o->low);
            $merged[$k]->high = max((int) $merged[$k]->high, (int) $o->high);
            $merged[$k]->units = (int) $merged[$k]->units + (int) $o->units;
        }

        $goods = [];
        foreach ($merged as $o) {
            $id = (int) $o->resource;
            $goods[$id]['name'] = $o->resource_name ?? (string) $id;
            $place = &$goods[$id]['places'][(int) $o->place_id];
            $place['place_id'] = (int) $o->place_id;
            $place['place'] = $o->place ?? 'Unknown';
            if ((int) $o->event_type === 4) {
                $place['ask'] = (int) $o->low;
                $place['ask_units'] = (int) $o->units;
            } else {
                $place['bid'] = (int) $o->high;
                $place['bid_units'] = (int) $o->units;
            }
            unset($place);
        }
        if (!$goods) {
            return [];
        }

        // 2. What each traded for over the last 7 game days, and the 7 before
        $now = World::gameNow();
        $week = self::DAYS * 86400;
        $ids = array_keys($goods);
        $trades = collect(DB::table('trades')->whereIn('resource', $ids)->where('game_t', '>=', $now - 2 * $week)
            ->where('units', '>', 0)->groupBy('resource')
            ->selectRaw('resource, SUM(IF(game_t >= ?, units, 0)) AS units, SUM(IF(game_t >= ?, credits, 0)) AS credits,
                SUM(IF(game_t < ?, units, 0)) AS units_before, SUM(IF(game_t < ?, credits, 0)) AS credits_before',
                [$now - $week, $now - $week, $now - $week, $now - $week])->get())->keyBy('resource');

        $rows = [];
        foreach ($goods as $id => $g) {
            $places = array_values($g['places']);
            usort($places, fn ($a, $b) => $a['place_id'] <=> $b['place_id']);
            foreach ($places as &$p) {
                $p += ['ask' => null, 'ask_units' => 0, 'bid' => null, 'bid_units' => 0];
            }
            unset($p);
            $asks = array_filter($places, fn ($p) => $p['ask'] !== null);
            $bids = array_filter($places, fn ($p) => $p['bid'] !== null);
            usort($asks, fn ($a, $b) => [$a['ask'], $a['place_id']] <=> [$b['ask'], $b['place_id']]);
            usort($bids, fn ($a, $b) => [-$a['bid'], $a['place_id']] <=> [-$b['bid'], $b['place_id']]);
            $cheapest = $asks[0] ?? null;
            $best = $bids[0] ?? null;
            $prices = array_merge(array_column($places, 'ask'), array_column($places, 'bid'));
            $prices = array_values(array_filter($prices, fn ($v) => $v !== null));

            $t = $trades->get($id);
            $units = $t ? (int) $t->units : 0;
            $credits = $t ? (int) $t->credits : 0;
            $before = $t && (int) $t->units_before > 0 ? $t->credits_before / $t->units_before : null;
            $average = $units > 0 ? $credits / $units : null;
            $change = $before !== null && $average !== null && $before > 0 ? (int) round(100 * ($average - $before) / $before) : null;

            $rows[] = [
                'resource' => $id, 'name' => $g['name'], 'tier' => intdiv($id, 1000),
                'tier_name' => $id === Release::FUEL ? 'fuel' : (HouseMarket::TIERS[intdiv($id, 1000)] ?? 'goods'),
                'places' => $places, 'low' => min($prices), 'high' => max($prices),
                'cheapest' => $cheapest ? ['place' => $cheapest['place'], 'price' => $cheapest['ask'], 'units' => $cheapest['ask_units']] : null,
                'best_bid' => $best ? ['place' => $best['place'], 'price' => $best['bid'], 'units' => $best['bid_units']] : null,
                // A haul: buy at the cheapest seller, sell to the best buyer elsewhere for more
                'margin' => $cheapest && $best && $best['bid'] > $cheapest['ask'] && $best['place_id'] !== $cheapest['place_id']
                    ? $best['bid'] - $cheapest['ask'] : null,
                // What a startup can trade at: the House's own cheapest seller and best buyer (#880)
                'house_cheapest' => $house[$id]['ask'] ?? null, 'house_best_bid' => $house[$id]['bid'] ?? null,
                'units' => $units, 'credits' => $credits,
                'average' => $average !== null ? round($average, $average < 10 ? 1 : 0) : null, 'change_percent' => $change,
            ];
        }
        // Most credits traded first; then the goods quoted at the most places, by ID
        usort($rows, fn ($a, $b) => [-$a['credits'], -count($a['places']), $a['resource']]
            <=> [-$b['credits'], -count($b['places']), $b['resource']]);
        return $rows;
    }

    /**
     * Every open order for one good at every place (#721: the order book
     * moved to the good's page), best first on each side
     */
    public static function book(int $resource): array
    {
        $out = [];
        foreach ([4 => 'sell', 5 => 'buy'] as $type => $side) {
            $movable = ApiController::movable($type);
            $rows = DB::select("SELECT * FROM (SELECT o.drone, a.name AS drone_name, o.price, o.quantity, $movable AS movable,
                    a.object_id AS place_id, p.object_name AS place, a.company_id, c.name AS company_name, a.station_id
                FROM latest_orders AS o
                JOIN agents AS a ON a.agent_id = o.drone AND a.wrecked_at IS NULL
                LEFT JOIN companies AS c ON c.company_id = a.company_id
                LEFT JOIN objects AS p ON p.object_id = a.object_id
                WHERE o.resource = ? AND o.event_type = ? AND o.is_open = 1) AS b
                WHERE " . ($type === 4 ? 'movable > quantity' : 'movable < quantity') . "
                ORDER BY price " . ($type === 4 ? 'ASC' : 'DESC') . ', place, drone LIMIT 200', [$resource, $type]);
            $out[$side] = array_map(fn ($r) => ['drone' => (int) $r->drone, 'drone_name' => $r->drone_name, 'price' => (int) $r->price,
                'units' => abs((int) $r->movable - (int) $r->quantity), 'place' => $r->place ?? 'Unknown',
                'company_id' => $r->company_id !== null ? (int) $r->company_id : null, 'company' => $r->company_name,
                'house' => $r->station_id !== null], $rows);
        }
        return $out;
    }
}
