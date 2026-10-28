<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The leaderboard (#324 step 18; companies since #367 step 2): every company
 * still trading, by net wealth. The drones a company launches belong to it,
 * so their goods count too.
 *
 * Net wealth = the company's credits
 * + the goods aboard its working drones, at the House price
 * - its starter package (credits, fuel and the kit), so a fresh release is
 *   worth 0 and wealth is what it made.
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
        // A packed kit counts as its contents (#380: two kits, each unpacked by
        // the protocol 20000 above its ID)
        foreach (Release::KITS as $kitId) {
            $kit = 0;
            foreach (DB::table('resource_effects')->where('protocol', $kitId + 20000)->where('event_type', 1)
                         ->get(['resource', 'quantity']) as $part) {
                $kit += ($prices[$part->resource] ?? 0) * max(1, (int) $part->quantity);
            }
            $prices[$kitId] = $kit;
        }
        return array_map('intval', $prices);
    }

    /** What every company starts with (#324 step 6): credits, fuel and a kit */
    public static function kitValue(array $prices): int
    {
        return (int) config('forge.starting_credits') + (int) config('forge.starting_fuel') * ($prices[self::FUEL] ?? 0)
            + ($prices[self::STARTER_KIT] ?? 0);
    }

    /**
     * Every company still trading: company_id => [company, drones, wealth]
     * (and drone, name: the drone it was released with). Its launched
     * drones' goods count too.
     */
    public static function wealth(): array
    {
        $prices = self::prices();
        $kit = self::kitValue($prices);
        $rows = [];
        // The House (#380: its station drones' company) isn't a player
        foreach (DB::table('companies')->whereNull('eliminated_at')->where('house', 0)->get(['company_id', 'name', 'credits']) as $c) {
            $rows[$c->company_id] = ['company_id' => (int) $c->company_id, 'company' => $c->name, 'drone' => null,
                'name' => null, 'drones' => 0, 'wealth' => (int) $c->credits - $kit];
        }
        // The released drone: the company's first, a launched clone's ancestor
        foreach (DB::table('agents')->whereIn('company_id', array_keys($rows))->groupBy('company_id')
                     ->select('company_id', DB::raw('MIN(agent_id) AS drone'))->pluck('drone', 'company_id')->all() as $co => $id) {
            $rows[$co]['drone'] = (int) $id;
        }
        foreach (DB::table('agents')->whereIn('agent_id', array_filter(array_column($rows, 'drone')))
                     ->pluck('name', 'agent_id')->all() as $id => $name) {
            foreach ($rows as $co => $row) {
                if ($row['drone'] === (int) $id) {
                    $rows[$co]['name'] = $name;
                }
            }
        }
        foreach (DB::table('agents')->whereNull('wrecked_at')->whereIn('company_id', array_keys($rows))->groupBy('company_id')
                     ->select('company_id', DB::raw('COUNT(*) AS n'))->pluck('n', 'company_id')->all() as $co => $n) {
            $rows[$co]['drones'] = (int) $n;
        }
        foreach (DB::table('sqInventory as i')->join('agents as a', 'a.agent_id', '=', 'i.drone')
                     ->whereNull('a.wrecked_at')->where('i.units', '>', 0)->whereIn('a.company_id', array_keys($rows))
                     ->select('a.company_id', 'i.resource', DB::raw('SUM(i.units) AS units'))
                     ->groupBy('a.company_id', 'i.resource')->get() as $g) {
            $rows[$g->company_id]['wealth'] += (int) $g->units * ($prices[$g->resource] ?? 0);
        }
        return array_filter($rows, fn ($row) => $row['drone'] !== null);
    }

    /** The all-time board, richest first */
    public static function allTime(int $limit = 50): array
    {
        $rows = array_values(self::wealth());
        usort($rows, fn ($a, $b) => [$b['wealth'], $a['drone']] <=> [$a['wealth'], $b['drone']]);
        return array_slice($rows, 0, $limit);
    }

    /**
     * Rising: the gain over the last real week (since the latest snapshot at
     * least a week old; a company that started within the week, since 0)
     */
    public static function rising(int $limit = 50): array
    {
        $since = now()->subDays(7);
        $before = [];
        if (Schema::hasColumn('wealth_snapshots', 'company_id')) {
            $latest = DB::table('wealth_snapshots')->whereNotNull('company_id')->where('taken_at', '<=', $since)
                ->groupBy('company_id')->select('company_id', DB::raw('MAX(taken_at) AS t'));
            $before = DB::table('wealth_snapshots as s')->joinSub($latest, 'l', function ($join) {
                $join->on('l.company_id', '=', 's.company_id')->on('l.t', '=', 's.taken_at');
            })->pluck('s.wealth', 's.company_id')->all();
        }
        $rows = [];
        foreach (self::wealth() as $co => $row) {
            $row['gain'] = $row['wealth'] - (int) ($before[$co] ?? 0);
            $rows[] = $row;
        }
        usort($rows, fn ($a, $b) => [$b['gain'], $a['drone']] <=> [$a['gain'], $b['drone']]);
        return array_slice($rows, 0, $limit);
    }

    /** Takes the hourly snapshot when it is due; returns whether it did */
    public static function snapshotIfDue(): bool
    {
        if (!Schema::hasColumn('wealth_snapshots', 'company_id')) {
            return false;
        }
        $last = DB::table('wealth_snapshots')->max('taken_at');
        if ($last !== null && strtotime($last) > time() - 3600) {
            return false;
        }
        $now = now();
        $rows = array_map(fn ($r) => ['company_id' => $r['company_id'], 'taken_at' => $now, 'wealth' => $r['wealth']],
            array_values(self::wealth()));
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('wealth_snapshots')->insert($chunk);
        }
        return true;
    }
}
