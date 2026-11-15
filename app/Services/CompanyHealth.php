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
 * - at_risk: no working drone, a working drone with no fuel left (#609:
 *   round 63's Wilma 3 sat at the Moon with 0 fuel and 2,400 credits, and
 *   wasn't flagged), or a drone that runs dry within 3 game days while the
 *   company has under 500 credits; at_risk_reason says which
 * - idle (#540): game hours since any of its working drones last gained or
 *   spent goods other than fuel (bought, sold, mined, refined or made), up to
 *   IDLE_LOOKBACK days; stalled when that is a game day or more. Dev 6 and
 *   Ezra 6 (round 57) sat "short of credits" for 20 game days before their
 *   owners noticed
 * - idle_drones (#689): working drones idle a game day or more;
 *   last_sale: game hours since it last sold anything (up to IDLE_LOOKBACK
 *   days; null when it is younger than that and has never sold). Round 69's
 *   company 161 had 39 drones, most idle 26 to 240 game hours and no sale for
 *   days, yet idle 0 and not stalled: one drone making alloys was enough.
 *   Now it is stalled too when at least half its drones are idle, or when it
 *   has sold nothing for STALLED_SALE_HOURS (once it is that old);
 *   stalled_reason says which
 */
class CompanyHealth
{
    public const FUEL = 2061;
    public const RISK_DAYS = 3;
    public const RISK_CREDITS = 500;
    public const STALLED_HOURS = 24;
    public const IDLE_LOOKBACK = 10;   // game days
    public const STALLED_SALE_HOURS = 72;

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
            . "IFNULL((SELECT c.credits FROM companies AS c WHERE c.company_id = $company), 0) AS d_credits, "
            . "IF(COUNT(d.agent_id) = 0, -1, FLOOR(($now - IFNULL(MAX(d.last_t), $now - " . (self::IDLE_LOOKBACK * 86400)
            . ")) / 3600)) AS idle "
            . "FROM (SELECT a.agent_id, " . self::lastWorkSql('a.agent_id', $now) . " AS last_t, "
            . "IFNULL((SELECT f.units FROM holdings AS f WHERE f.drone = a.agent_id "
            . "AND f.resource = " . self::FUEL . " AND f.installed = 0 AND f.locked = 0), 0) AS fuel, "
            . $fall('x.prev > x.q') . " AS used, " . $fall('x.prev IS NOT NULL') . " AS fall "
            . "FROM agents AS a WHERE a.company_id = $company AND a.wrecked_at IS NULL) AS d";
    }

    /**
     * When a drone last gained or spent goods other than fuel (#540), or NULL;
     * now while it is flying (#380 round 64: a drone three game days into a
     * flight to Deimos was flagged "Stalled: idle 33 game hours")
     */
    public static function lastWorkSql(string $drone, string $now): string
    {
        return "IF(" . DronePage::flyingSql($drone, $now) . ", $now, "
            . "(SELECT w.game_t FROM events AS w FORCE INDEX (events_drone_game_t) WHERE w.drone = $drone "
            . "AND w.outcome = 1 AND w.game_t > $now - " . (self::IDLE_LOOKBACK * 86400) . " AND w.game_t <= $now "
            . "AND w.event_type IN (1, 2) AND w.resource <> " . self::FUEL . " ORDER BY w.game_t DESC LIMIT 1))";
    }

    /** One company's health */
    public static function of(int $company): array
    {
        $now = (int) World::gameNow();
        $row = DB::selectOne(self::sql((string) $company, (string) $now));
        $health = ['working' => (int) $row->working, 'wrecked' => (int) $row->wrecked, 'fuel' => (int) $row->fuel,
            'lowest' => (int) $row->lowest, 'used' => (int) $row->used, 'days' => (int) $row->days,
            'credits' => (int) $row->d_credits, 'idle' => (int) $row->idle] + ProcessorTax::broke($company);
        // Its drones idle a game day or more, and its last sale (#689)
        $health['idle_drones'] = (int) DB::selectOne("SELECT COUNT(*) AS n FROM (SELECT " . self::lastWorkSql('a.agent_id', (string) $now)
            . " AS last_t FROM agents AS a WHERE a.company_id = ? AND a.wrecked_at IS NULL) AS d "
            . "WHERE d.last_t IS NULL OR d.last_t <= ?", [$company, $now - self::STALLED_HOURS * 3600])->n;
        $lookback = self::IDLE_LOOKBACK * 86400;
        $sold = DB::table('trades AS t')->join('agents AS a', 'a.agent_id', '=', 't.seller')->where('a.company_id', $company)
            ->where('t.game_t', '>', $now - $lookback)->where('t.game_t', '<=', $now)->max('t.game_t');
        $founded = (int) (DB::table('companies')->where('company_id', $company)->value('founded_t') ?? $now);
        $health['last_sale'] = $sold !== null ? (int) floor(($now - (int) $sold) / 3600)
            : ($now - $founded >= $lookback ? self::IDLE_LOOKBACK * 24 : null);
        // An ended company is neither stalled nor at risk: it has ended (#686)
        $ended = DB::table('companies')->where('company_id', $company)->whereNotNull('eliminated_at')->exists();
        $health['stalled_reason'] = $ended ? null : self::stalledReason($health, $now - $founded);
        $health['stalled'] = $health['stalled_reason'] !== null;
        $health['at_risk_reason'] = $ended ? null : self::riskReason($health);
        $health['at_risk'] = $health['at_risk_reason'] !== null;
        return $health;
    }

    /** Why a company is stalled (#540, #689), or null */
    public static function stalledReason(array $h, int $age): ?string
    {
        if ($h['working'] === 0) {
            return null;
        }
        if ($h['idle'] >= self::STALLED_HOURS) {
            return "none of its drones has bought, sold or made anything for {$h['idle']} game hours";
        }
        if ($h['working'] > 1 && $h['idle_drones'] * 2 >= $h['working']) {
            return "{$h['idle_drones']} of its {$h['working']} drones have bought, sold or made nothing for a game day or more";
        }
        if ($age >= self::STALLED_SALE_HOURS * 3600
            && ($h['last_sale'] === null || $h['last_sale'] >= self::STALLED_SALE_HOURS)) {
            return $h['last_sale'] === null ? 'it has never sold anything'
                : ($h['last_sale'] >= self::IDLE_LOOKBACK * 24 ? 'it has sold nothing for ' . self::IDLE_LOOKBACK . ' game days or more'
                    : "it has sold nothing for {$h['last_sale']} game hours");
        }
        return null;
    }

    /**
     * Why a company is at risk (#541), or null: a warning, not a verdict.
     * Round 57's investors sold everything at 1 on the flag, and the price
     * fell for every holder
     */
    public static function riskReason(array $h): ?string
    {
        if ($h['working'] === 0) {
            return 'no working drone';
        }
        if (($h['dissolves_in'] ?? null) !== null) {
            return "it has had no credits for {$h['broke_days']} game days, and dissolves in {$h['dissolves_in']} unless it earns some";
        }
        if (($h['lowest'] ?? 1) <= 0) {
            return 'a drone has no fuel left: it can\'t fly or trade until someone sells it fuel where it is';
        }
        if ($h['days'] >= 0 && $h['days'] <= self::RISK_DAYS && $h['credits'] < self::RISK_CREDITS) {
            return "a drone runs dry in about {$h['days']} game " . ($h['days'] === 1 ? 'day' : 'days')
                . " at the last day's fall in fuel, with {$h['credits']} credits to buy more";
        }
        return null;
    }

    /**
     * Each working drone (#540): how long it has been idle, what its script
     * last said (set_status) and what it holds, so a stalled company shows why
     */
    public static function drones(int $company): array
    {
        $now = (int) World::gameNow();
        $rows = DB::select("SELECT a.agent_id, a.name, a.script_status, " . self::lastWorkSql('a.agent_id', (string) $now)
            . " AS last_t FROM agents AS a WHERE a.company_id = ? AND a.wrecked_at IS NULL ORDER BY a.agent_id", [$company]);
        $out = [];
        foreach ($rows as $r) {
            $holds = DB::table('holdings AS h')->leftJoin('resources AS r', 'r.id', '=', 'h.resource')
                ->where('h.drone', $r->agent_id)->where('h.installed', 0)->where('h.units', '>', 0)
                ->orderByDesc('h.units')->limit(6)->get(['r.name', 'h.units'])
                ->map(fn ($h) => ['resource' => $h->name, 'units' => (int) $h->units])->all();
            $out[] = ['drone' => (int) $r->agent_id, 'name' => $r->name,
                'idle' => $r->last_t === null ? self::IDLE_LOOKBACK * 24 : (int) floor(($now - (int) $r->last_t) / 3600),
                'status' => $r->script_status, 'holds' => $holds];
        }
        return $out;
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
}
