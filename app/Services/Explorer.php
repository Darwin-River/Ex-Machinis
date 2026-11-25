<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * The home page's "Explore the world" tables (#1059): every company still
 * trading, every good on the market and every place with players' drones, as
 * rows a visitor sorts, filters and searches in the page. Public data only,
 * the same the leaderboard, the market and the map show. Kept (Kept: the
 * scheduler rebuilds it every 5 real minutes, so a page view never waits).
 */
class Explorer
{
    public const KEY = 'home-explorer';
    public const KEEP = 300;

    /** The most companies sent to the page: the richest, the rest counted */
    public const COMPANIES = 500;

    /**
     * {companies: [{id, name, wealth, drones, age, status (startup|grown)}],
     *  company_count, goods: [{id, name, tier, low, high, average, change, units, credits, places}],
     *  places: [{id, name, working, wrecks, exchange}]}
     */
    public static function data(): array
    {
        return Kept::get(self::KEY, self::KEEP, fn () => self::work());
    }

    public static function work(): array
    {
        $board = Leaderboard::wealth();
        $status = DB::table('companies')->whereIn('company_id', array_keys($board) ?: [0])
            ->get(['company_id', 'startup', 'matured_t'])->keyBy('company_id');
        usort($board, fn ($a, $b) => $b['wealth'] <=> $a['wealth']);
        $companies = array_map(function ($r) use ($status) {
            $s = $status[$r['company_id']] ?? null;
            return ['id' => $r['company_id'], 'name' => $r['company'], 'wealth' => (int) $r['wealth'],
                'drones' => (int) $r['drones'], 'age' => (float) $r['age'],
                'status' => $s && (int) $s->startup === 1 && $s->matured_t === null ? 'startup' : 'grown'];
        }, array_slice($board, 0, self::COMPANIES));

        $goods = array_map(fn ($g) => ['id' => $g['resource'], 'name' => $g['name'], 'tier' => $g['tier_name'],
            'low' => $g['low'], 'high' => $g['high'], 'average' => $g['average'], 'change' => $g['change_percent'],
            'units' => $g['units'], 'credits' => $g['credits'], 'places' => count($g['places'])], MarketBoard::all());

        // Places with a market: any with an order on the book
        $exchanges = [];
        foreach (MarketBoard::all() as $g) {
            foreach ($g['places'] as $p) {
                if (isset($p['place_id'])) {
                    $exchanges[(int) $p['place_id']] = true;
                }
            }
        }
        $places = array_map(fn ($p) => ['id' => $p['object_id'], 'name' => $p['object_name'], 'working' => $p['working'],
            'wrecks' => $p['wrecks'], 'exchange' => isset($exchanges[$p['object_id']])], World::places());

        return ['companies' => $companies, 'company_count' => count($board), 'goods' => $goods, 'places' => $places];
    }
}
