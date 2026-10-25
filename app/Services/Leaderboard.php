<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * The leaderboard (#324 step 18): player accounts by net wealth.
 *
 * Net wealth = the credits of the player's companies that still trade
 * + the goods aboard their working drones, at the House price
 * - one starter kit for every company the player has started (its credits,
 *   its fuel and the kit), so a fresh release is worth 0 and wealth is what
 *   the player made.
 * The House price of a good is the least a House station pays for it, or if
 * none buys it, the least one sells it for; a packed starter kit is worth
 * its contents. Rising is the gain over the last real week, from the hourly
 * snapshots.
 */
class Leaderboard
{
    public const STARTER_KIT = 5901;
    public const UNPACK_PROTOCOL = 25901;
    public const FUEL = 2061;

    /** resource => House price */
    public static function prices(): array
    {
        $prices = DB::table('station_goods')->where('side', 2)->groupBy('resource')
            ->select('resource', DB::raw('MIN(price) AS price'))->pluck('price', 'resource')->all();
        foreach (DB::table('station_goods')->where('side', 1)->groupBy('resource')
                     ->select('resource', DB::raw('MIN(price) AS price'))->pluck('price', 'resource')->all() as $r => $p) {
            $prices[$r] = $p;   // what a station pays wins over what one charges
        }
        $kit = 0;
        foreach (DB::table('resource_effects')->where('protocol', self::UNPACK_PROTOCOL)->where('event_type', 1)
                     ->get(['resource', 'quantity']) as $part) {
            $kit += ($prices[$part->resource] ?? 0) * max(1, (int) $part->quantity);
        }
        $prices[self::STARTER_KIT] = $kit;
        return array_map('intval', $prices);
    }

    /** What every company starts with (#324 step 6): credits, fuel and a kit */
    public static function kitValue(array $prices): int
    {
        return (int) config('forge.starting_credits') + (int) config('forge.starting_fuel') * ($prices[self::FUEL] ?? 0)
            + ($prices[self::STARTER_KIT] ?? 0);
    }

    /** Every player with a company: user_id => [name, companies, drones, wealth] */
    public static function wealth(): array
    {
        $prices = self::prices();
        $kit = self::kitValue($prices);
        $players = [];
        foreach (DB::table('companies as c')->join('users as u', 'u.user_id', '=', 'c.user_id')
                     ->select('u.user_id', 'u.name', DB::raw('COUNT(*) AS companies'),
                         DB::raw('SUM(CASE WHEN c.eliminated_at IS NULL THEN c.credits ELSE 0 END) AS credits'))
                     ->groupBy('u.user_id', 'u.name')->get() as $p) {
            $players[$p->user_id] = ['user_id' => (int) $p->user_id, 'name' => $p->name, 'companies' => (int) $p->companies,
                'drones' => 0, 'wealth' => (int) $p->credits - (int) $p->companies * $kit];
        }
        foreach (DB::table('agents')->whereNull('wrecked_at')->groupBy('user_id')
                     ->select('user_id', DB::raw('COUNT(*) AS n'))->pluck('n', 'user_id')->all() as $user => $n) {
            if (isset($players[$user])) {
                $players[$user]['drones'] = (int) $n;
            }
        }
        foreach (DB::table('sqInventory as i')->join('agents as a', 'a.agent_id', '=', 'i.drone')
                     ->whereNull('a.wrecked_at')->where('i.units', '>', 0)
                     ->select('a.user_id', 'i.resource', DB::raw('SUM(i.units) AS units'))
                     ->groupBy('a.user_id', 'i.resource')->get() as $g) {
            if (isset($players[$g->user_id])) {
                $players[$g->user_id]['wealth'] += (int) $g->units * ($prices[$g->resource] ?? 0);
            }
        }
        return $players;
    }

    /** The all-time board, richest first */
    public static function allTime(int $limit = 50): array
    {
        $rows = array_values(self::wealth());
        usort($rows, fn ($a, $b) => $b['wealth'] <=> $a['wealth']);
        return array_slice($rows, 0, $limit);
    }

    /**
     * Rising: the gain over the last real week (since the latest snapshot at
     * least a week old; a player who started within the week, since 0)
     */
    public static function rising(int $limit = 50): array
    {
        $since = now()->subDays(7);
        $rows = [];
        foreach (self::wealth() as $user => $row) {
            $before = DB::table('wealth_snapshots')->where('user_id', $user)->where('taken_at', '<=', $since)
                ->orderBy('taken_at', 'desc')->value('wealth');
            $row['gain'] = $row['wealth'] - (int) ($before ?? 0);
            $rows[] = $row;
        }
        usort($rows, fn ($a, $b) => $b['gain'] <=> $a['gain']);
        return array_slice($rows, 0, $limit);
    }

    /** Takes the hourly snapshot when it is due; returns whether it did */
    public static function snapshotIfDue(): bool
    {
        $last = DB::table('wealth_snapshots')->max('taken_at');
        if ($last !== null && strtotime($last) > time() - 3600) {
            return false;
        }
        $now = now();
        $rows = array_map(fn ($r) => ['user_id' => $r['user_id'], 'taken_at' => $now, 'wealth' => $r['wealth']],
            array_values(self::wealth()));
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('wealth_snapshots')->insert($chunk);
        }
        return true;
    }
}
