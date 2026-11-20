<?php

namespace App\Services;

use App\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * "Your company now" (#770): for a logged-in player, on every page, each
 * live startup's credits against what it needs to mature, a forecast of
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

    /**
     * The least history a forecast is drawn from (#878): a young startup's
     * first days (buying its kit, its first load) say little about its pace,
     * and a forecast from two of them promised "11 real minutes" to one
     * that took 45 game days
     */
    public const MIN_PACE_DAYS = 6;

    /** The strip's rows for an account: ['startups' => [...], 'alerts' => [...]], or null with nothing to show */
    public static function for(User $user): ?array
    {
        if (!config('forge.new_rules') || $user->login === null) {
            return null;
        }
        return Cache::remember('company-now:' . $user->user_id, self::TTL, fn () => self::build($user));
    }

    /** Forgets an account's kept strip (after it matures or founds a startup) */
    public static function forget(User $user): void
    {
        Cache::forget('company-now:' . $user->user_id);
    }

    /**
     * When a startup should mature at its pace (#770, #744): its credits
     * gained a game day over its last PACE_DAYS of history, the real
     * minutes and the game time to its mark, or nulls when it isn't gaining
     */
    public static function forecast(object $c, int $need, int $credits, bool $can, ?int $today = null, ?float $scale = null): array
    {
        $today ??= MarketHistory::today();
        $scale ??= max(1.0, (float) (DB::table('game_clock')->where('id', 1)->value('scale') ?? 1));
        $days = DB::table('company_days')->where('company_id', $c->company_id)->where('day', '>=', $today - self::PACE_DAYS)
            ->orderBy('day')->get(['day', 'credits']);
        $pace = null;
        if (count($days) >= 2 && $today - (int) $days->first()->day >= self::MIN_PACE_DAYS) {
            $first = $days->first();
            $span = max(1, $today - (int) $first->day);
            $pace = ((int) $c->credits - (int) $first->credits) / $span;
        }
        $gameSeconds = !$can && $pace !== null && $pace > 0 ? (int) ceil(($need - $credits) / $pace * 86400) : null;
        return ['pace' => $pace, 'minutes' => $gameSeconds === null ? null : (int) ceil($gameSeconds / $scale / 60),
            'game_t' => $gameSeconds === null ? null : World::gameNow() + $gameSeconds];
    }

    private static function build(User $user): ?array
    {
        $margin = Startups::matureMargin();
        $scale = max(1.0, (float) (DB::table('game_clock')->where('id', 1)->value('scale') ?? 1));
        $today = MarketHistory::today();
        $startups = [];
        foreach (DB::table('companies')->where('owner_id', $user->user_id)->where('startup', 1)->whereNull('eliminated_at')
                     ->orderBy('company_id')->get() as $c) {
            $need = (int) $c->debt + $margin;
            $credits = (int) $c->credits - (int) ($c->compute_debt ?? 0);
            $can = Startups::canMature($c);
            $cap = Startups::creditCap($c);
            ['pace' => $pace, 'minutes' => $minutes] = self::forecast($c, $need, $credits, $can, $today, $scale);
            $startups[] = ['company' => (int) $c->company_id, 'name' => $c->name, 'credits' => $credits, 'need' => $need,
                'percent' => $need > 0 ? min(100, max(0, (int) floor(100 * $credits / $need))) : 100,
                'can_mature' => $can, 'at_cap' => $cap !== null && (int) $c->credits >= $cap,
                'pace' => $pace === null ? null : (int) round($pace), 'minutes' => $minutes];
        }
        // Matured companies counting down to dissolving
        $alerts = [];
        foreach (DB::table('companies')->where('owner_id', $user->user_id)->where('startup', 0)->whereNull('eliminated_at')
                     ->whereNotNull('broke_since')->get(['company_id', 'name']) as $c) {
            $b = ProcessorTax::broke((int) $c->company_id);
            if ($b['dissolves_in'] !== null) {
                $alerts[] = ['company' => (int) $c->company_id, 'name' => $c->name,
                    'text' => 'has had no credits for ' . $b['broke_days'] . ' game days: it dissolves in ' . $b['dissolves_in'] . ' unless it earns some'];
            }
        }
        // Matured companies counting down to winding up (#875)
        foreach (DB::table('companies')->where('owner_id', $user->user_id)->where('startup', 0)->whereNull('eliminated_at')
                     ->whereNotNull('matured_t')->get(['company_id', 'name']) as $c) {
            $left = WindUp::daysLeft((int) $c->company_id);
            if ($left !== null) {
                $alerts[] = ['company' => (int) $c->company_id, 'name' => $c->name,
                    'text' => 'has sold nothing for ' . (WindUp::DAYS - $left) . ' game days: it is wound up in ' . $left
                        . ' unless it sells, its credits shared out to its shareholders'];
            }
        }
        return $startups || $alerts ? ['startups' => $startups, 'alerts' => $alerts] : null;
    }
}
