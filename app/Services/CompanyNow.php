<?php

namespace App\Services;

use App\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * "Your company now" (#770): for a logged-in player, on every page, each
 * live seedling's credits against what it needs to mature, a forecast of
 * when it will at its recent pace, and whether it can mature now (with the
 * Mature button); and any matured company counting down to dissolving.
 * Kept a real minute for each account, so a page view costs a cache read.
 */
class CompanyNow
{
    /** Real seconds each account's strip is kept */
    public const TTL = 60;

    /** Game days of history its pace is read from */
    public const PACE_DAYS = 7;

    /** The strip's rows for an account: ['seedlings' => [...], 'alerts' => [...]], or null with nothing to show */
    public static function for(User $user): ?array
    {
        if (!config('forge.new_rules') || $user->login === null) {
            return null;
        }
        return Cache::remember('company-now:' . $user->user_id, self::TTL, fn () => self::build($user));
    }

    /** Forgets an account's kept strip (after it matures or starts a seedling) */
    public static function forget(User $user): void
    {
        Cache::forget('company-now:' . $user->user_id);
    }

    private static function build(User $user): ?array
    {
        $margin = Seedlings::matureMargin();
        $scale = max(1.0, (float) (DB::table('game_clock')->where('id', 1)->value('scale') ?? 1));
        $today = MarketHistory::today();
        $seedlings = [];
        foreach (DB::table('companies')->where('owner_id', $user->user_id)->where('seedling', 1)->whereNull('eliminated_at')
                     ->orderBy('company_id')->get() as $c) {
            $need = (int) $c->debt + $margin;
            $credits = (int) $c->credits - (int) ($c->compute_debt ?? 0);
            $can = Seedlings::canMature($c);
            $cap = Seedlings::creditCap($c);
            // Its pace: credits gained a game day over its last PACE_DAYS of history
            $days = DB::table('company_days')->where('company_id', $c->company_id)->where('day', '>=', $today - self::PACE_DAYS)
                ->orderBy('day')->get(['day', 'credits']);
            $pace = null;
            if (count($days) >= 2) {
                $first = $days->first();
                $span = max(1, $today - (int) $first->day);
                $pace = ((int) $c->credits - (int) $first->credits) / $span;
            }
            $minutes = !$can && $pace !== null && $pace > 0
                ? (int) ceil(($need - $credits) / $pace * 86400 / $scale / 60) : null;
            $seedlings[] = ['company' => (int) $c->company_id, 'name' => $c->name, 'credits' => $credits, 'need' => $need,
                'percent' => $need > 0 ? min(100, max(0, (int) floor(100 * $credits / $need))) : 100,
                'can_mature' => $can, 'at_cap' => $cap !== null && (int) $c->credits >= $cap,
                'pace' => $pace === null ? null : (int) round($pace), 'minutes' => $minutes];
        }
        // Matured companies counting down to dissolving
        $alerts = [];
        foreach (DB::table('companies')->where('owner_id', $user->user_id)->where('seedling', 0)->whereNull('eliminated_at')
                     ->whereNotNull('broke_since')->get(['company_id', 'name']) as $c) {
            $b = ProcessorTax::broke((int) $c->company_id);
            if ($b['dissolves_in'] !== null) {
                $alerts[] = ['company' => (int) $c->company_id, 'name' => $c->name,
                    'text' => 'has had no credits for ' . $b['broke_days'] . ' game days: it dissolves in ' . $b['dissolves_in'] . ' unless it earns some'];
            }
        }
        return $seedlings || $alerts ? ['seedlings' => $seedlings, 'alerts' => $alerts] : null;
    }
}
