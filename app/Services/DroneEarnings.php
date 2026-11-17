<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * What each drone earned (#774): its sales after tax less its purchases,
 * over its life and a game day, with a running total for the drone page's
 * activity chart, and a company's drones ranked by it. Players learn which
 * code path pays, and come back to fix the worst drone.
 */
class DroneEarnings
{
    /** Trades the running total is drawn from, newest first */
    public const SERIES_MAX = 400;

    /** A drone's earnings: {sales, bought, net, days, per_day, series: [{game_t, net}], before} */
    public static function of(int $drone, bool $series = false): array
    {
        $a = DB::table('agents as a')->leftJoin('companies as c', 'c.company_id', '=', 'a.company_id')
            ->where('a.agent_id', $drone)->first(['a.parent_id', 'a.wrecked_at', 'c.founded_t']);
        $sold = DB::table('trades')->where('seller', $drone)->selectRaw('COALESCE(SUM(credits), 0) AS c, COALESCE(SUM(tax), 0) AS tax, MIN(game_t) AS first')->first();
        $bought = DB::table('trades')->where('buyer', $drone)->selectRaw('COALESCE(SUM(credits), 0) AS c, MIN(game_t) AS first')->first();
        $sales = (int) $sold->c - (int) $sold->tax;
        $net = $sales - (int) $bought->c;
        $out = ['sales' => $sales, 'bought' => (int) $bought->c, 'net' => $net] + self::span($drone, $a, $sold->first, $bought->first, $net);
        if ($series) {
            // Its latest trades, oldest first, each with the running total after it
            $rows = DB::table('trades')->where(fn ($q) => $q->where('seller', $drone)->orWhere('buyer', $drone))
                ->whereNotNull('game_t')->orderByDesc('game_t')->orderByDesc('id')->limit(self::SERIES_MAX)
                ->get(['game_t', 'seller', 'credits', 'tax'])->reverse()->values();
            $deltas = $rows->map(fn ($t) => (int) $t->seller === $drone ? (int) $t->credits - (int) $t->tax : -(int) $t->credits);
            $total = $net - $deltas->sum();
            $out['before'] = $total;
            $out['series'] = $rows->map(function ($t, $i) use (&$total, $deltas) {
                $total += $deltas[$i];
                return ['game_t' => (int) $t->game_t, 'net' => $total];
            })->all();
        }
        return $out;
    }

    /** Its life in game days (from its company's founding for the released drone, else its first trade or event) */
    private static function span(int $drone, ?object $a, $firstSold, $firstBought, int $net): array
    {
        $now = World::gameNow();
        $end = $a && $a->wrecked_at !== null ? min($now, (int) $a->wrecked_at) : $now;
        $start = $a && $a->parent_id === null && $a->founded_t !== null ? (int) $a->founded_t : null;
        if ($start === null) {
            $firstEvent = DB::table('events')->where('drone', $drone)->min('game_t');
            $start = min(array_filter([$firstEvent, $firstSold, $firstBought], fn ($v) => $v !== null) ?: [$end]);
        }
        $days = max(0.0, ($end - (int) $start) / 86400);
        return ['days' => round($days, 1), 'per_day' => $days >= 0.25 ? (int) round($net / $days) : null];
    }

    /** A company's drones ranked by what they earned, best first: [{agent_id, name, wrecked, sales, bought, net, days, per_day}] */
    public static function ranked(int $company): array
    {
        $out = [];
        foreach (DB::table('agents')->where('company_id', $company)->orderBy('agent_id')->get(['agent_id', 'name', 'wrecked_at']) as $d) {
            $out[] = ['agent_id' => (int) $d->agent_id, 'name' => $d->name, 'wrecked' => $d->wrecked_at !== null]
                + self::of((int) $d->agent_id);
        }
        usort($out, fn ($x, $y) => [$y['per_day'] ?? PHP_INT_MIN, $y['net']] <=> [$x['per_day'] ?? PHP_INT_MIN, $x['net']]);
        return $out;
    }
}
