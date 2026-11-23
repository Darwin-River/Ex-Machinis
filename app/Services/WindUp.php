<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Winding up a stalled company (#875). A matured company whose drones have
 * sold nothing for DAYS game days is wound up: its credits are shared out
 * to its shareholders by the shares each holds (the bank's part of it goes
 * back to the bank), a sale to the bank still waiting to be paid is paid at
 * the same price, its drones are scrapped (they leave the game with what
 * they hold, as a closed startup's do) and the company ends. Round 77 found
 * matured companies holding 300,000 to 735,000 credits whose locked code had
 * stopped selling 240 game days before: nobody could reach the credits, and
 * their idle fleets crowded the mining sites. After WARN game days without
 * a sale, its page, the exchange and its owner's strip say when it winds up.
 */
class WindUp
{
    /** Game days without a sale after which a matured company is wound up */
    public const DAYS = 60;

    /** Game days without a sale after which it says when it winds up */
    public const WARN = 30;

    /** Real seconds between looks (the tick runs every few seconds) */
    public const EVERY = 300;

    /**
     * Game seconds since $since that the world stood still (#837: no drone
     * acted for an hourly bill; the engine keeps them in world_stalls), which
     * the wind-up leaves out: a company can't sell while nothing runs
     */
    public static function stalledSince(int $since, ?array $stalls = null): int
    {
        $stalls ??= self::stalls($since);
        $sum = 0;
        foreach ($stalls as [$from, $to]) {
            if ($to > $since) {
                $sum += $to - max($from, $since);
            }
        }
        return $sum;
    }

    /** The world's stalls ending after $since: [[from_t, to_t]] (one query, for several companies) */
    private static function stalls(int $since): array
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('world_stalls')) {
            return [];
        }
        return DB::table('world_stalls')->where('to_t', '>', $since)->get(['from_t', 'to_t'])
            ->map(fn ($r) => [(int) $r->from_t, (int) $r->to_t])->all();
    }

    /** Game days a company has gone without a sale, the world's stalls left out */
    private static function idleDays(int $lastSale, int $now, ?array $stalls = null): float
    {
        return max(0, $now - $lastSale - self::stalledSince($lastSale, $stalls)) / 86400;
    }

    /** When a company last sold anything: the game time, or its maturing when it has sold nothing since */
    public static function lastSale(object $c): int
    {
        $sold = DB::table('trades AS t')->join('agents AS a', 'a.agent_id', '=', 't.seller')
            ->where('a.company_id', $c->company_id)->max('t.game_t');
        return max((int) ($sold ?? 0), (int) ($c->matured_t ?? 0));
    }

    /**
     * Game days until a matured company still trading is wound up, once it
     * has gone WARN game days without a sale; null when it isn't near it (or
     * isn't a matured company)
     */
    public static function daysLeft(int $company): ?int
    {
        $c = DB::table('companies')->where('company_id', $company)->whereNull('eliminated_at')->where('startup', 0)
            ->whereNotNull('matured_t')->where('house', 0)->first(['company_id', 'matured_t']);
        if (!$c) {
            return null;
        }
        $since = self::idleDays(self::lastSale($c), World::gameNow());
        return $since >= self::WARN ? max(0, (int) ceil(self::DAYS - $since)) : null;
    }

    /** daysLeft() for several companies in two queries (the stock exchange's rows): [company => days or null] */
    public static function daysLeftMany(array $companies): array
    {
        $out = array_fill_keys(array_map('intval', $companies), null);
        if (!$companies) {
            return $out;
        }
        $matured = DB::table('companies')->whereIn('company_id', $companies)->whereNull('eliminated_at')->where('startup', 0)
            ->whereNotNull('matured_t')->where('house', 0)->pluck('matured_t', 'company_id')->all();
        if (!$matured) {
            return $out;
        }
        $sold = DB::table('trades AS t')->join('agents AS a', 'a.agent_id', '=', 't.seller')
            ->whereIn('a.company_id', array_keys($matured))->groupBy('a.company_id')
            ->select('a.company_id', DB::raw('MAX(t.game_t) AS last'))->pluck('last', 'company_id')->all();
        $now = World::gameNow();
        $stalls = self::stalls($now - 365 * 86400);   // once for them all
        foreach ($matured as $co => $t) {
            $since = self::idleDays(max((int) ($sold[$co] ?? 0), (int) $t), $now, $stalls);
            $out[(int) $co] = $since >= self::WARN ? max(0, (int) ceil(self::DAYS - $since)) : null;
        }
        return $out;
    }

    /** The scheduler's tick: winds up every company due, at most every EVERY real seconds; how many it wound up */
    public static function runIfDue(): int
    {
        if (!Cache::add('wind-up-looked', 1, self::EVERY)) {
            return 0;
        }
        $now = World::gameNow();
        $n = 0;
        foreach (DB::table('companies')->whereNull('eliminated_at')->where('startup', 0)->whereNotNull('matured_t')
                     ->where('house', 0)->where('matured_t', '<=', $now - self::DAYS * 86400)
                     ->get(['company_id', 'matured_t']) as $c) {
            if (self::idleDays(self::lastSale($c), $now) >= self::DAYS) {
                $n += self::windUp((int) $c->company_id) ? 1 : 0;
            }
        }
        return $n;
    }

    /**
     * Winds one company up now; returns what each share paid, or null when
     * it had already ended. $cause: why it ended (closed: its code called
     * close_company(), #925). $keepHolds: its drones become wrecks with what
     * they hold, for plunderers (#925), rather than leaving the game with it
     */
    public static function windUp(int $company, string $cause = 'wound_up', bool $keepHolds = false): ?int
    {
        return DB::transaction(function () use ($company, $cause, $keepHolds) {
            $c = DB::table('companies')->where('company_id', $company)->whereNull('eliminated_at')->lockForUpdate()
                ->first(['company_id', 'credits', 'compute_debt']);
            if (!$c) {
                return null;
            }
            $now = World::gameNow();
            $count = max(1, PlayerShares::count($company));
            $credits = max(0, (int) $c->credits - (int) ($c->compute_debt ?? 0));
            // Its contracts settle now, as if due, and its lenders are paid first (#928)
            Contracts::onEnding($company);
            $credits = max(0, (int) DB::table('companies')->where('company_id', $company)->value('credits')
                - (int) ($c->compute_debt ?? 0));
            $credits -= Loans::onEnding($company, $credits);
            $perShare = intdiv($credits, $count);
            // Each player's shares, paid out of the company's credits (#937: a
            // move, not a flow; what is left goes when it ends)
            $paidOut = 0;
            foreach (DB::table('player_shares')->where('company_id', $company)->where('units', '>', 0)->get(['user_id', 'units']) as $h) {
                DB::table('users')->where('user_id', $h->user_id)
                    ->update(['credits' => DB::raw('credits + ' . ((int) $h->units * $perShare))]);
                $paidOut += (int) $h->units * $perShare;
            }
            if ($paidOut > 0) {
                DB::table('companies')->where('company_id', $company)->update(['credits' => DB::raw('credits - ' . $paidOut)]);
            }
            // A sale to the bank not yet paid is paid now, at no more than it was sold for
            foreach (DB::table('bank_payments')->where('company_id', $company)->whereNull('paid_t')->get() as $b) {
                $amount = (int) $b->units * min((int) $b->price_at_sale, $perShare);
                DB::table('bank_payments')->where('id', $b->id)->update(['paid_t' => $now, 'paid' => $amount]);
                DB::table('users')->where('user_id', $b->user_id)->update(['credits' => DB::raw('credits + ' . $amount)]);
                CreditFlows::record('bank_paid', $amount, $company, (int) $b->user_id);   // #937
            }
            DB::table('player_share_orders')->where('company_id', $company)->delete();
            // Its drones leave the game with what they hold, or (closed by its
            // code) become wrecks with it
            DB::table('agents')->where('company_id', $company)->whereNull('wrecked_at')
                ->update(['wrecked_at' => $now, 'wreck_cause' => $cause, 'launched_running' => 0]
                    + ($keepHolds ? [] : ['plundered_at' => $now]));
            DB::table('latest_orders')->whereIn('drone', DB::table('agents')->where('company_id', $company)->select('agent_id'))->delete();
            DB::table('companies')->where('company_id', $company)
                ->update(['credits' => 0, 'eliminated_at' => $now, 'end_cause' => $cause, 'broke_since' => null]);
            DB::table('company_windups')->insert(['company_id' => $company, 'game_t' => $now, 'per_share' => $perShare,
                'shares' => $count, 'credits' => $credits]);
            return $perShare;
        });
    }
}
