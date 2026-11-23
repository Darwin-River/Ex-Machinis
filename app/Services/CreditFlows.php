<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Credits created (+) or destroyed (-) by the website (#937), for the daily
 * check that credits balance (CreditCheck). Moving credits between holders
 * (a trade, a dividend to a player) is not a flow. Call it in the same
 * transaction as the change, so a check never sees one without the other.
 * The engines' flows, and companies founded or ended and accounts opened or
 * deleted, are written by triggers (migration 0094).
 */
class CreditFlows
{
    public static function record(string $kind, int $amount, ?int $company = null, ?int $user = null): void
    {
        if ($amount === 0) {
            return;
        }
        DB::table('credit_flows')->insert(['game_t' => World::gameNow(), 'kind' => $kind,
            'company_id' => $company, 'user_id' => $user, 'amount' => $amount]);
    }
}
