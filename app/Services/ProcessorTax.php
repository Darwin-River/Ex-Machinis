<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The processor tax (#544): a company pays credits for its drones' thinking,
 * billed once a game hour by the events engine (db_compute.c), instead of
 * the fuel thinking burned until then. A company that can't pay owes the
 * rest (companies.compute_debt) and its drones run throttled until it has
 * paid: a tenth of a turn each.
 */
class ProcessorTax
{
    /** The base rate: credits a million instructions at the clock's target pace (house_rules.compute_tax_per_million) */
    /**
     * A company's share of the congestion (#620), as compute_company_congestion
     * in compute.c: 1 + (congestion - 1) times its instructions over the last
     * game day as a share of full_at (at most all of it)
     */
    public static function companyCongestion(float $congestion, int $dayInstructions, int $fullAt): float
    {
        if ($congestion <= 1) {
            return 1.0;
        }
        if ($fullAt <= 0) {
            return $congestion;
        }
        return 1 + ($congestion - 1) * min(1, max(0, $dayInstructions) / $fullAt);
    }

    public static function baseRate(): int
    {
        if (!Schema::hasColumn('house_rules', 'compute_tax_per_million')) {
            return 0;
        }
        return (int) (DB::table('house_rules')->where('id', 1)->value('compute_tax_per_million') ?? 0);
    }

    /**
     * Credits a million instructions billed now to a busy company (#620): the
     * base rate times the congestion, as the events engine billed it last
     * (compute_clock)
     */
    public static function rate(): int
    {
        return self::congestion()['rate'];
    }

    /**
     * The congestion tax (#620): while the clock runs slower than its target
     * because the engines can't keep up, thinking costs that many times more
     * (target / scale, up to house_rules.congestion_cap). [rate (for a busy
     * company), base, congestion (1: none), cap, full_at], as billed at the
     * last hourly bill
     */
    public static function congestion(): array
    {
        $base = self::baseRate();
        $out = ['rate' => $base, 'base' => $base, 'congestion' => 1.0, 'cap' => null, 'full_at' => null];
        if (!Schema::hasColumn('compute_clock', 'rate_now')) {
            return $out;
        }
        $now = DB::table('compute_clock')->where('id', 1)->first(['rate_now', 'congestion']);
        $rules = DB::table('house_rules')->where('id', 1)->first(['congestion_cap', 'congestion_full_at']);
        $out['cap'] = (float) ($rules->congestion_cap ?? 0);
        $out['full_at'] = (int) ($rules->congestion_full_at ?? 0);
        if ($now && $now->rate_now !== null) {
            $out['rate'] = (int) $now->rate_now;
            $out['congestion'] = round((float) ($now->congestion ?? 1), 2);
        }
        return $out;
    }

    /**
     * A company's processor tax: instructions billed and tax over the last
     * game day, what it owes, whether its drones run throttled, and the rate
     */
    public static function of(int $company): array
    {
        $c = self::congestion();
        $out = ['instructions_day' => 0, 'tax_day' => 0, 'debt' => 0, 'throttled' => false, 'per_million' => $c['base'],
            'base_per_million' => $c['base'], 'congestion' => 1.0];
        if (!Schema::hasTable('compute_bills')) {
            return $out;
        }
        $day = DB::table('compute_bills')->where('company_id', $company)->where('charged_t', '>', World::gameNow() - 86400)
            ->selectRaw('IFNULL(SUM(instructions), 0) AS i, IFNULL(SUM(amount), 0) AS a')->first();
        $debt = (int) (DB::table('companies')->where('company_id', $company)->value('compute_debt') ?? 0);
        // Its own rate (#620): its share of the congestion, by how much it thought over the last game day
        $mine = round(self::companyCongestion($c['congestion'], (int) $day->i, (int) $c['full_at']), 2);
        $out['congestion'] = $mine;
        $out['per_million'] = (int) round($c['base'] * $mine);
        return ['instructions_day' => (int) $day->i, 'tax_day' => (int) $day->a, 'debt' => $debt,
            'throttled' => $debt > 0, 'per_million' => $out['per_million'], 'base_per_million' => $out['base_per_million'],
            'congestion' => $out['congestion']] + self::broke($company);
    }

    /**
     * How long a company has been broke (#547): its credits less the tax it
     * owes at 0 or below, checked once a game hour. Broke for dissolve_days
     * game days straight, it dissolves and its drones become wrecks.
     * broke_days (null when it isn't broke) and dissolves_in (game days left)
     */
    public static function broke(int $company): array
    {
        $out = ['broke_days' => null, 'dissolves_in' => null];
        if (!Schema::hasColumn('companies', 'broke_since')) {
            return $out;
        }
        $since = DB::table('companies')->where('company_id', $company)->whereNull('eliminated_at')->value('broke_since');
        if ($since === null) {
            return $out;
        }
        $days = (World::gameNow() - (int) $since) / 86400;
        $limit = (int) (DB::table('house_rules')->where('id', 1)->value('dissolve_days') ?? 50);
        return ['broke_days' => round($days, 1), 'dissolves_in' => $limit > 0 ? max(0, round($limit - $days, 1)) : null];
    }

    /** company => processor tax owed, for net wealth */
    public static function debts(): array
    {
        if (!Schema::hasColumn('companies', 'compute_debt')) {
            return [];
        }
        return array_map('intval', DB::table('companies')->where('compute_debt', '>', 0)
            ->pluck('compute_debt', 'company_id')->all());
    }
}
