<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * A company's health (#537): whether it can keep its drones flying. Round
 * 56's investors lost three holdings to companies whose drones ran out of
 * fuel, with nothing to warn them. Everything here was public already
 * (drone pages, timelines); this puts it in one place, for the API, the
 * company and Stock exchange pages, and Lua's company_health(c) (query 699,
 * the same SQL: keep the two in step).
 *
 * - working, wrecked: its drones
 * - fuel: fuel aboard its working drones; lowest: the least aboard one
 * - used: fuel its working drones used over the last game day (actions,
 *   thinking, and fuel that went into what they made or sold)
 * - days: game days until its first drone runs dry at the last game day's
 *   net fall in fuel (used less bought); -1 when no drone's fuel is falling
 * - credits: what it has to buy more
 * - at_risk: no working drone, or a drone that runs dry within 3 game days
 *   while the company has under 500 credits
 */
class CompanyHealth
{
    public const FUEL = 2061;
    public const RISK_DAYS = 3;
    public const RISK_CREDITS = 500;

    /** The per-drone fuel figures for the SQL: c (company id) and now are placeholders */
    public static function sql(string $company, string $now): string
    {
        $fall = fn (string $only) => "IFNULL((SELECT SUM(x.prev - x.q) FROM (SELECT e.game_t, e.new_quantity AS q, "
            . "LAG(e.new_quantity) OVER (ORDER BY e.game_t, e.id) AS prev FROM events AS e FORCE INDEX (events_inventory_time) "
            . "WHERE e.drone = a.agent_id AND e.resource = " . self::FUEL . " AND e.installed = 0 AND e.locked = 0 "
            . "AND e.outcome = 1 AND e.game_t > $now - 172800) AS x WHERE x.game_t > $now - 86400 AND $only), 0)";
        return "SELECT (SELECT COUNT(*) FROM agents AS w WHERE w.company_id = $company AND w.wrecked_at IS NULL) AS working, "
            . "(SELECT COUNT(*) FROM agents AS w WHERE w.company_id = $company AND w.wrecked_at IS NOT NULL) AS wrecked, "
            . "IFNULL(SUM(d.fuel), 0) AS fuel, IFNULL(MIN(d.fuel), 0) AS lowest, IFNULL(SUM(d.used), 0) AS used, "
            . "IFNULL(MIN(IF(d.fall > 0, FLOOR(d.fuel / d.fall), NULL)), -1) AS days, "
            . "IFNULL((SELECT c.credits FROM companies AS c WHERE c.company_id = $company), 0) AS d_credits "
            . "FROM (SELECT a.agent_id, IFNULL((SELECT f.units FROM holdings AS f WHERE f.drone = a.agent_id "
            . "AND f.resource = " . self::FUEL . " AND f.installed = 0 AND f.locked = 0), 0) AS fuel, "
            . $fall('x.prev > x.q') . " AS used, " . $fall('x.prev IS NOT NULL') . " AS fall "
            . "FROM agents AS a WHERE a.company_id = $company AND a.wrecked_at IS NULL) AS d";
    }

    /** One company's health */
    public static function of(int $company): array
    {
        $row = DB::selectOne(self::sql('?', '?'), self::bindings($company));
        $health = ['working' => (int) $row->working, 'wrecked' => (int) $row->wrecked, 'fuel' => (int) $row->fuel,
            'lowest' => (int) $row->lowest, 'used' => (int) $row->used, 'days' => (int) $row->days,
            'credits' => (int) $row->d_credits];
        $health['at_risk'] = $health['working'] === 0
            || ($health['days'] >= 0 && $health['days'] <= self::RISK_DAYS && $health['credits'] < self::RISK_CREDITS);
        return $health;
    }

    /** Several companies' health, by company ID */
    public static function many(array $companies): array
    {
        $out = [];
        foreach (array_unique(array_map('intval', $companies)) as $c) {
            $out[$c] = self::of($c);
        }
        return $out;
    }

    /** The values for sql('?', '?'), in the order its placeholders appear */
    private static function bindings(int $company): array
    {
        $now = World::gameNow();
        // In the order they appear: company (working), company (wrecked),
        // company (credits), now x4 (the two falls, each twice), company (its drones)
        return [$company, $company, $company, $now, $now, $now, $now, $company];
    }
}
