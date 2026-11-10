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
    /** Credits a million instructions (house_rules.compute_tax_per_million) */
    public static function rate(): int
    {
        if (!Schema::hasColumn('house_rules', 'compute_tax_per_million')) {
            return 0;
        }
        return (int) (DB::table('house_rules')->where('id', 1)->value('compute_tax_per_million') ?? 0);
    }

    /**
     * A company's processor tax: instructions billed and tax over the last
     * game day, what it owes, whether its drones run throttled, and the rate
     */
    public static function of(int $company): array
    {
        $out = ['instructions_day' => 0, 'tax_day' => 0, 'debt' => 0, 'throttled' => false, 'per_million' => self::rate()];
        if (!Schema::hasTable('compute_bills')) {
            return $out;
        }
        $day = DB::table('compute_bills')->where('company_id', $company)->where('charged_t', '>', World::gameNow() - 86400)
            ->selectRaw('IFNULL(SUM(instructions), 0) AS i, IFNULL(SUM(amount), 0) AS a')->first();
        $debt = (int) (DB::table('companies')->where('company_id', $company)->value('compute_debt') ?? 0);
        return ['instructions_day' => (int) $day->i, 'tax_day' => (int) $day->a, 'debt' => $debt,
            'throttled' => $debt > 0, 'per_million' => $out['per_million']];
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
