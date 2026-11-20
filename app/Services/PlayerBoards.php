<?php

namespace App\Services;

use App\User;
use Illuminate\Support\Facades\DB;

/**
 * Player boards and the digest (#640, step 5 of the new rules in #635).
 *
 * - A player's wealth: their credits and starting credits, plus their shares
 *   at cash-out value, plus what the bank still owes them for shares sold to
 *   it (#687: at the lower of the price on the day of the sale and the
 *   cash-out value now, as it will pay; wealth fell by the whole sale until
 *   the bank paid, 163,000 for one player in round 69).
 * - The wealth board ranks players by it; the season board by what they
 *   made today, a real day (UTC), from their wealth when the day began
 *   (#775: it replaced growth this month over a 5,000 floor). A class
 *   group's accounts have their own boards, apart from everyone else's.
 * - Personal records on the account page (#775).
 * - The "while you were away" digest: what a player's companies and shares
 *   brought in since they last looked, the bank's payments, deals between
 *   their own companies, and the news.
 */
class PlayerBoards
{
    /**
     * The longest a digest looks back, in game days (#716: a player away
     * for a while got a digest of ~500 game days, and its "since" jumped
     * when the clock ran fast)
     */
    public const DIGEST_MAX_DAYS = 7;

    /**
     * Only accounts that have played (#867): founded a startup or held a
     * share. The live server verified every sign-up at once, so junk
     * accounts passed the verified-address test
     */
    public static function played($q)
    {
        return $q->where(fn ($w) => $w->whereExists(fn ($e) => $e->select(DB::raw(1))->from('companies')->whereColumn('companies.owner_id', 'users.user_id'))
            ->orWhereExists(fn ($e) => $e->select(DB::raw(1))->from('player_shares')->whereColumn('player_shares.user_id', 'users.user_id')));
    }

    /** Each player account's wealth: [user_id => [name, credits, shares, bank_due, wealth, class_group_id]] */
    public static function wealths(?int $classGroup = null, bool $everyone = false): array
    {
        $q = DB::table('users')->whereNotNull('login');
        if (!$everyone) {
            // The public boards list only accounts with a confirmed address (#867:
            // 36 of 40 were junk sign-ups); a class's pupils go by their teacher's
            $classGroup === null ? self::played($q->whereNull('class_group_id')->whereNotNull('email_verified_at')) : $q->where('class_group_id', $classGroup);
        }
        $users = $q->get(['user_id', 'login', 'credits', 'share_credits', 'class_group_id']);
        $price = [];
        $holdings = DB::table('player_shares')->whereIn('user_id', $users->pluck('user_id'))->where('units', '>', 0)
            ->get(['user_id', 'company_id', 'units'])->groupBy('user_id');
        $payments = DB::table('bank_payments')->whereIn('user_id', $users->pluck('user_id'))->whereNull('paid_t')
            ->get(['user_id', 'company_id', 'units', 'price_at_sale'])->groupBy('user_id');
        $out = [];
        foreach ($users as $u) {
            $shares = 0;
            foreach ($holdings[$u->user_id] ?? [] as $h) {
                $price[$h->company_id] ??= PlayerShares::cashOut((int) $h->company_id)['per_share'];
                $shares += (int) $h->units * $price[$h->company_id];
            }
            // What the bank owes it, at what the bank would pay now (#687)
            $due = 0;
            foreach ($payments[$u->user_id] ?? [] as $b) {
                $price[$b->company_id] ??= PlayerShares::cashOut((int) $b->company_id)['per_share'];
                $due += (int) $b->units * min((int) $b->price_at_sale, $price[$b->company_id]);
            }
            $credits = (int) $u->credits + (int) $u->share_credits;
            $out[(int) $u->user_id] = ['name' => $u->login, 'credits' => $credits, 'shares' => $shares, 'bank_due' => $due,
                'wealth' => $credits + $shares + $due, 'class_group_id' => $u->class_group_id === null ? null : (int) $u->class_group_id];
        }
        return $out;
    }

    /** The season (#775): a real day, UTC */
    public static function day(): string
    {
        return now('UTC')->format('Y-m-d');
    }

    /** Records today's starting wealth for accounts that have none yet (the tick) */
    public static function startDay(): int
    {
        $day = self::day();
        $have = DB::table('player_wealth_days')->where('day', $day)->pluck('user_id')->flip();
        $n = 0;
        foreach (self::wealths(null, true) as $id => $w) {
            if (!isset($have[$id])) {
                DB::table('player_wealth_days')->insertOrIgnore(['user_id' => $id, 'day' => $day, 'start_wealth' => $w['wealth']]);
                $n++;
            }
        }
        return $n;
    }

    /**
     * The wealth board and the season board for the public, or for a class
     * group: [day, wealth => [...], season => [...]], richest first, and
     * those who made most today first (#775: a short season a newcomer can
     * win, in place of growth this month over a floor)
     */
    public static function boards(?int $classGroup = null, int $limit = 50): array
    {
        $wealths = self::wealths($classGroup);
        $starts = DB::table('player_wealth_days')->where('day', self::day())
            ->whereIn('user_id', array_keys($wealths))->pluck('start_wealth', 'user_id');
        $rows = [];
        foreach ($wealths as $id => $w) {
            $start = isset($starts[$id]) ? (int) $starts[$id] : $w['wealth'];
            $rows[] = $w + ['user_id' => $id, 'start' => $start, 'made' => $w['wealth'] - $start];
        }
        $wealth = $rows;
        usort($wealth, fn ($a, $b) => [$b['wealth'], $a['user_id']] <=> [$a['wealth'], $b['user_id']]);
        $season = $rows;
        usort($season, fn ($a, $b) => [$b['made'], $b['wealth']] <=> [$a['made'], $a['wealth']]);
        $strip = fn ($r) => ['name' => $r['name'], 'wealth' => $r['wealth'], 'credits' => $r['credits'],
            'shares' => $r['shares'], 'bank_due' => $r['bank_due'], 'start' => $r['start'], 'made' => $r['made']];
        return ['day' => self::day(), 'wealth' => array_map($strip, array_slice($wealth, 0, $limit)),
            'season' => array_map($strip, array_slice($season, 0, $limit))];
    }

    /**
     * A player's personal records (#775), for the account page: their best
     * season (the most they made in a real day), fastest maturity, the best
     * yield among the shares they hold, their longest-running company, and
     * their share trades with other players. Each null until they have one
     */
    public static function records(User $user): array
    {
        $id = (int) $user->user_id;
        // Best season: the rise from one day's start to the next day's
        $days = DB::table('player_wealth_days')->where('user_id', $id)->orderBy('day')->get(['day', 'start_wealth'])->values();
        $best = null;
        for ($i = 1; $i < count($days); $i++) {
            $made = (int) $days[$i]->start_wealth - (int) $days[$i - 1]->start_wealth;
            if ($made > 0 && (!$best || $made > $best['made'])) {
                $best = ['made' => $made, 'day' => $days[$i - 1]->day];
            }
        }
        $today = self::boards(null, PHP_INT_MAX)['season'];
        $mine = collect($today)->firstWhere('name', $user->login);
        if ($mine && $mine['made'] > 0 && (!$best || $mine['made'] > $best['made'])) {
            $best = ['made' => $mine['made'], 'day' => self::day(), 'today' => true];
        }
        $companies = DB::table('companies')->where('owner_id', $id)->whereNotNull('founded_t')
            ->get(['company_id', 'name', 'founded_t', 'matured_t', 'eliminated_at']);
        $fastest = $companies->whereNotNull('matured_t')->map(fn ($c) => ['company_id' => (int) $c->company_id, 'name' => $c->name,
            'days' => round(((int) $c->matured_t - (int) $c->founded_t) / 86400, 1)])->sortBy('days')->first();
        $now = World::gameNow();
        $longest = $companies->map(fn ($c) => ['company_id' => (int) $c->company_id, 'name' => $c->name,
            'days' => round((min($now, (int) ($c->eliminated_at ?? $now)) - (int) $c->founded_t) / 86400, 1),
            'running' => $c->eliminated_at === null])->sortByDesc('days')->first();
        // The best yield (dividends over 30 game days against the bank's price) among the shares held
        $held = DB::table('player_shares')->where('user_id', $id)->where('units', '>', 0)->pluck('company_id')->map(fn ($c) => (int) $c)->all();
        $yield = null;
        if ($held) {
            foreach (PlayerShares::market() as $m) {
                if (in_array((int) $m['company'], $held, true) && ($m['yield_percent'] ?? null) !== null
                    && (!$yield || $m['yield_percent'] > $yield['percent'])) {
                    $yield = ['company_id' => (int) $m['company'], 'name' => $m['name'] ?? null, 'percent' => $m['yield_percent']];
                }
            }
        }
        $trades = DB::table('player_share_trades')->whereNotNull('seller_id')->whereNotNull('buyer_id')
            ->where(fn ($q) => $q->where('seller_id', $id)->orWhere('buyer_id', $id))->count();
        return ['best_season' => $best, 'fastest_maturity' => $fastest, 'best_yield' => $yield, 'longest_running' => $longest,
            'player_trades' => $trades];
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
        $capped = $since < $now - self::DIGEST_MAX_DAYS * 86400;
        $since = max($since, $now - self::DIGEST_MAX_DAYS * 86400);
        $lastTrade = (int) (DB::table('trades')->max('id') ?? 0);
        $epoch = (int) DB::table('game_clock')->where('id', 1)->value('epoch');
        $sinceDay = intdiv($since - $epoch, 86400);
        // Its companies, and those that closed or ended since the last look (#655)
        $companies = DB::table('companies')->where('owner_id', $user->user_id)
            ->where(fn ($q) => $q->whereNull('eliminated_at')->orWhere('eliminated_at', '>', $since))
            ->get(['company_id', 'name', 'startup', 'eliminated_at', 'end_cause', 'credits', 'debt']);
        // Trades by id when there is a mark: one recorded after the last look
        // counts even if its game time was earlier (#655)
        $newer = fn ($q) => $row->last_seen_trade !== null && !$capped ? $q->where('t.id', '>', (int) $row->last_seen_trade)
            : $q->where('t.game_t', '>', $since);
        $earned = [];
        foreach ($companies as $c) {
            $sales = (int) DB::table('trades as t')->join('agents as a', 'a.agent_id', '=', 't.seller')
                ->where('a.company_id', $c->company_id)->where($newer)->sum(DB::raw('t.credits - t.tax'));
            $bought = (int) DB::table('trades as t')->join('agents as a', 'a.agent_id', '=', 't.buyer')
                ->where('a.company_id', $c->company_id)->where($newer)->sum('t.credits');
            $earned[] = ['company_id' => (int) $c->company_id, 'name' => $c->name, 'startup' => (int) $c->startup === 1,
                'sales' => $sales, 'purchases' => $bought,
                // A startup's credits over its cap, gone to the bank (#672)
                'to_bank' => (int) $c->startup === 1 ? Startups::toBank((int) $c->company_id, $since) : 0,
                // A live startup at its cap (#749): ready to mature, and what it earns now goes to the bank
                'credit_cap' => ($cap = (int) $c->startup === 1 && $c->eliminated_at === null ? Startups::creditCap($c) : null),
                'at_cap' => $cap !== null && (int) $c->credits >= $cap,
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
            // Whether it was cut to the last DIGEST_MAX_DAYS game days
            'capped' => $capped,
            'companies' => $earned,
            'dividends' => (int) DB::table('player_dividends')->where('user_id', $user->user_id)->where('day', '>=', $sinceDay)->sum('amount'),
            'bank_paid' => (int) DB::table('bank_payments')->where('user_id', $user->user_id)->where('paid_t', '>', $since)->sum('paid'),
            'same_owner_deals' => $flags,
            'news' => array_values(array_filter(HouseMarket::news(), fn ($n) => $n['to_day'] > $sinceDay)),
            // Its share orders that filled (#715: nothing said when one did)
            'share_fills' => PlayerShares::fills((int) $user->user_id, $since),
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
        \Illuminate\Support\Facades\Cache::forget('since-looked:' . $user->user_id);   // #771
    }
}
