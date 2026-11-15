<?php

namespace App\Services;

use App\User;
use Illuminate\Support\Facades\DB;

/**
 * Player boards and the digest (#640, step 5 of the new rules in #635).
 *
 * - A player's wealth: their credits and starting credits, plus their shares
 *   at cash-out value.
 * - The wealth board ranks players by it; the monthly season board by how
 *   much it grew this real month, as a share of their wealth at its start
 *   (counted as at least forge.season_floor, 5,000). A class group's
 *   accounts have their own boards, apart from everyone else's.
 * - The "while you were away" digest: what a player's companies and shares
 *   brought in since they last looked, the bank's payments, deals between
 *   their own companies, and the news.
 */
class PlayerBoards
{
    /** Each player account's wealth: [user_id => [name, credits, shares, wealth, class_group_id]] */
    public static function wealths(?int $classGroup = null, bool $everyone = false): array
    {
        $q = DB::table('users')->whereNotNull('login');
        if (!$everyone) {
            $classGroup === null ? $q->whereNull('class_group_id') : $q->where('class_group_id', $classGroup);
        }
        $users = $q->get(['user_id', 'login', 'credits', 'share_credits', 'class_group_id']);
        $price = [];
        $holdings = DB::table('player_shares')->whereIn('user_id', $users->pluck('user_id'))->where('units', '>', 0)
            ->get(['user_id', 'company_id', 'units'])->groupBy('user_id');
        $out = [];
        foreach ($users as $u) {
            $shares = 0;
            foreach ($holdings[$u->user_id] ?? [] as $h) {
                $price[$h->company_id] ??= PlayerShares::cashOut((int) $h->company_id)['per_share'];
                $shares += (int) $h->units * $price[$h->company_id];
            }
            $credits = (int) $u->credits + (int) $u->share_credits;
            $out[(int) $u->user_id] = ['name' => $u->login, 'credits' => $credits, 'shares' => $shares,
                'wealth' => $credits + $shares, 'class_group_id' => $u->class_group_id === null ? null : (int) $u->class_group_id];
        }
        return $out;
    }

    private static function month(): string
    {
        return now()->format('Y-m');
    }

    /** Records this month's starting wealth for accounts that have none yet (the tick) */
    public static function startMonth(): int
    {
        $month = self::month();
        $have = DB::table('player_wealth_months')->where('month', $month)->pluck('user_id')->flip();
        $n = 0;
        foreach (self::wealths(null, true) as $id => $w) {
            if (!isset($have[$id])) {
                DB::table('player_wealth_months')->insertOrIgnore(['user_id' => $id, 'month' => $month, 'start_wealth' => $w['wealth']]);
                $n++;
            }
        }
        return $n;
    }

    /**
     * The wealth board and the season board for the public, or for a class
     * group: [wealth => [...], season => [...]] richest and fastest first
     */
    public static function boards(?int $classGroup = null, int $limit = 50): array
    {
        $wealths = self::wealths($classGroup);
        $starts = DB::table('player_wealth_months')->where('month', self::month())
            ->whereIn('user_id', array_keys($wealths))->pluck('start_wealth', 'user_id');
        $floor = (int) config('forge.season_floor');
        $rows = [];
        foreach ($wealths as $id => $w) {
            $start = isset($starts[$id]) ? (int) $starts[$id] : $w['wealth'];
            $rows[] = $w + ['user_id' => $id, 'start' => $start,
                'growth_percent' => round(100 * ($w['wealth'] - $start) / max($start, $floor), 1)];
        }
        $wealth = $rows;
        usort($wealth, fn ($a, $b) => [$b['wealth'], $a['user_id']] <=> [$a['wealth'], $b['user_id']]);
        $season = $rows;
        usort($season, fn ($a, $b) => [$b['growth_percent'], $b['wealth']] <=> [$a['growth_percent'], $a['wealth']]);
        $strip = fn ($r) => ['name' => $r['name'], 'wealth' => $r['wealth'], 'credits' => $r['credits'],
            'shares' => $r['shares'], 'start' => $r['start'], 'growth_percent' => $r['growth_percent']];
        return ['month' => self::month(), 'wealth' => array_map($strip, array_slice($wealth, 0, $limit)),
            'season' => array_map($strip, array_slice($season, 0, $limit))];
    }

    /**
     * What happened for a player since they last looked (or the last game
     * day): their companies' earnings, dividends, the bank's payments, deals
     * between their own companies, and the news. With $mark, it counts as
     * seen from now.
     */
    public static function digest(User $user, bool $mark = true): array
    {
        $now = World::gameNow();
        $row = DB::table('users')->where('user_id', $user->user_id)->first(['last_seen_t', 'last_seen_trade']);
        // A new account's first look starts when it was made (#655)
        $since = (int) ($row->last_seen_t ?? $now - 86400);
        $lastTrade = (int) (DB::table('trades')->max('id') ?? 0);
        $epoch = (int) DB::table('game_clock')->where('id', 1)->value('epoch');
        $sinceDay = intdiv($since - $epoch, 86400);
        // Its companies, and those that closed or ended since the last look (#655)
        $companies = DB::table('companies')->where('owner_id', $user->user_id)
            ->where(fn ($q) => $q->whereNull('eliminated_at')->orWhere('eliminated_at', '>', $since))
            ->get(['company_id', 'name', 'seedling', 'eliminated_at', 'end_cause']);
        // Trades by id when there is a mark: one recorded after the last look
        // counts even if its game time was earlier (#655)
        $newer = fn ($q) => $row->last_seen_trade !== null ? $q->where('t.id', '>', (int) $row->last_seen_trade)
            : $q->where('t.game_t', '>', $since);
        $earned = [];
        foreach ($companies as $c) {
            $sales = (int) DB::table('trades as t')->join('agents as a', 'a.agent_id', '=', 't.seller')
                ->where('a.company_id', $c->company_id)->where($newer)->sum(DB::raw('t.credits - t.tax'));
            $bought = (int) DB::table('trades as t')->join('agents as a', 'a.agent_id', '=', 't.buyer')
                ->where('a.company_id', $c->company_id)->where($newer)->sum('t.credits');
            $earned[] = ['company_id' => (int) $c->company_id, 'name' => $c->name, 'seedling' => (int) $c->seedling === 1,
                'sales' => $sales, 'purchases' => $bought,
                // A seedling's credits over its cap, gone to the bank (#672)
                'to_bank' => (int) $c->seedling === 1 ? Seedlings::toBank((int) $c->company_id, $since) : 0,
                'ended' => $c->eliminated_at !== null ? ($c->end_cause === 'closed' ? 'closed' : 'ended') : null];
        }
        $flags = [];
        foreach ($companies as $c) {
            foreach (Dealings::of((int) $c->company_id) as $d) {
                if ($d['same_owner']) {
                    $flags[] = ['company' => $c->name, 'with' => $d['name']];
                }
            }
        }
        $digest = [
            'since' => DronePage::when($since),
            'companies' => $earned,
            'dividends' => (int) DB::table('player_dividends')->where('user_id', $user->user_id)->where('day', '>=', $sinceDay)->sum('amount'),
            'bank_paid' => (int) DB::table('bank_payments')->where('user_id', $user->user_id)->where('paid_t', '>', $since)->sum('paid'),
            'same_owner_deals' => $flags,
            'news' => array_values(array_filter(HouseMarket::news(), fn ($n) => $n['to_day'] > $sinceDay)),
            // Where the next look starts, once this one is shown
            'until_t' => $now,
            'until_trade' => $lastTrade,
        ];
        if ($mark) {
            self::markSeen($user, $digest);
        }
        return $digest;
    }

    /** The player has seen this digest: the next starts where it ended (#655) */
    public static function markSeen(User $user, array $digest): void
    {
        DB::table('users')->where('user_id', $user->user_id)
            ->update(['last_seen_t' => $digest['until_t'], 'last_seen_trade' => $digest['until_trade']]);
    }
}
