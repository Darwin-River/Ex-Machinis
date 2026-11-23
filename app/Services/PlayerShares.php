<?php

namespace App\Services;

use App\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Players' shares and the bank (#638, step 3 of the new rules in #635).
 *
 * A matured company's 100 shares (#667) are its player's (#637), and players trade
 * them. The price that anchors everything is the company's cash-out value: its
 * credits, plus its goods and equipment at each good's best House bid
 * anywhere, averaged over 7 game days (#685), plus each working drone's hull at a fixed value that falls
 * with age (nothing for a drone with no fuel aboard that hasn't bought any
 * for forge.stranded_days game days), less what it owes, a share. It never
 * depends on the price of trades in its shares.
 *
 * - The bank always buys a matured company's shares at cash-out value, and
 *   pays forge.bank_pay_days game days later, at the lower of the value on
 *   the day of the sale and on the day of payment.
 * - The bank sells only shares it bought back, at cash-out value plus the
 *   share's dividends over the last 30 game days (its selling price, which
 *   is quoted even when it holds none).
 * - Players trade with each other through standing orders, at a price
 *   between forge.share_range_low and forge.share_range_high times the bank's
 *   selling price. A class group's accounts trade only with each other.
 * - An account's starting credits (share_credits) buy shares only at or below
 *   the bank's selling price; other credits pay for the rest.
 */
class PlayerShares
{
    // A matured company's shares: 100 since #667, so each is worth enough to
    // price in whole credits (1,000 made it 2 to 15 credits)
    public const COUNT = 100;

    /** Why new shares are no longer offered (#923) */
    public const OFFERS_RETIRED = 'Offering new shares is retired: nobody adds credits to a company. A company\'s money is what it earns, '
        . 'and once it is public, what its code borrows.';

    /** New shares a matured company offers at a time, and the most it may have (#780) */
    public const OFFER_UNITS = 10;
    public const MAX_COUNT = 200;

    /** A company's shares in all: 100 unless it has sold new ones (#780) */
    public static function count(int $company): int
    {
        return self::counts([$company])[$company];
    }

    /** count() for several companies at once: [company => shares] */
    public static function counts(array $companies): array
    {
        $ids = array_values(array_unique(array_map('intval', $companies)));
        $out = array_fill_keys($ids, self::COUNT);
        if ($ids && \Illuminate\Support\Facades\Schema::hasColumn('companies', 'player_share_count')) {
            foreach (DB::table('companies')->whereIn('company_id', $ids)->whereNotNull('player_share_count')
                         ->pluck('player_share_count', 'company_id') as $id => $n) {
                $out[(int) $id] = max(1, (int) $n);
            }
        }
        return $out;
    }

    /** Game days of an account's own share trades its page and GET /account/shares list (#715) */
    public const FILLS_DAYS = 7;

    /** How many of an account's latest share trades its page and GET /account/shares list (#758) */
    public const FILLS = 20;

    /** Can players trade this company's shares: a matured company of the new rules, still trading? */
    public static function tradable(int $company): bool
    {
        return self::company($company) !== null;
    }

    /** A matured company of the new rules, still trading, or null */
    public static function company(int $company): ?object
    {
        $c = DB::table('companies')->where('company_id', $company)->first();
        // An ended company's shares are worth nothing and don't trade (#743:
        // the bank took them at 0, and orders on them stayed listed)
        return $c && (int) $c->startup === 0 && $c->owner_id !== null && $c->matured_t !== null && $c->eliminated_at === null ? $c : null;
    }

    /**
     * Moves each pegged order to its price now (#766), on the scheduler's
     * keeper: an order whose price changed is taken down and posted again at
     * the new price, so it trades at once with any order it now meets. One
     * that can't stand at its new price (its own orders would cross, or the
     * account can't pay) stays where it was. Returns how many moved.
     */
    public static function repeg(): int
    {
        if (!\Illuminate\Support\Facades\Schema::hasColumn('player_share_orders', 'peg_ref')) {
            return 0;
        }
        $moved = 0;
        $prices = [];
        foreach (DB::table('player_share_orders')->whereNotNull('peg_ref')->orderBy('id')->get() as $o) {
            $c = (int) $o->company_id;
            if (!self::company($c)) {
                continue;
            }
            $p = $prices[$c] ??= self::prices($c);
            if (self::pegPrice($p, $o->peg_ref, (int) $o->peg_offset_bp, (string) $o->side) === (int) $o->price) {
                continue;
            }
            $user = User::find($o->user_id);
            if (!$user) {
                continue;
            }
            DB::transaction(function () use ($o, $user, $c, &$moved) {
                DB::table('player_share_orders')->where('id', $o->id)->delete();
                $r = self::order($user, $c, $o->side, (int) $o->units, 0, ['ref' => $o->peg_ref, 'offset_bp' => (int) $o->peg_offset_bp],
                    $o->game_t !== null ? (int) $o->game_t : null, (bool) ($o->by_script ?? false));
                if (!($r['ok'] ?? false)) {
                    DB::table('player_share_orders')->insert((array) $o);   // it stays where it was
                    return;
                }
                // What is left standing keeps the order's id (#873, #831: a script
                // that kept the id found "You have no such order." after a reprice)
                if (($r['order'] ?? null) !== null && (int) $r['order'] !== (int) $o->id) {
                    DB::table('player_share_orders')->where('id', $r['order'])->update(['id' => $o->id]);
                }
                $moved++;
            });
        }
        return $moved;
    }

    /** For a company that has ended, a sentence saying so (#758), else null */
    public static function endedNote(int $company): ?string
    {
        return DB::table('companies')->where('company_id', $company)->whereNotNull('eliminated_at')->exists()
            ? 'That company has ended: its shares are worth nothing and no longer trade.' : null;
    }

    /**
     * Takes down the share orders of companies that have ended (#743), on
     * the tick; how many went. The orders are found first and taken down by
     * id (#860: a delete that read the companies table locked rows the
     * engine was writing, and deadlocked hundreds of times a day)
     */
    public static function dropEndedOrders(): int
    {
        $ids = DB::table('player_share_orders AS o')->join('companies AS c', 'c.company_id', '=', 'o.company_id')
            ->whereNotNull('c.eliminated_at')->pluck('o.id')->all();
        return $ids ? DB::table('player_share_orders')->whereIn('id', $ids)->delete() : 0;
    }

    /**
     * What the company is worth if it were sold off now, and its parts:
     * ['total', 'per_share', 'credits', 'goods', 'hulls', 'debts']
     */
    public static function cashOut(int $company): array
    {
        return self::cashOuts([$company])[$company];
    }

    /**
     * cashOut() for several companies at once (#726: the stock exchange
     * page worked each out on its own, two or three queries a drone, and
     * took 4.5 to 7 real seconds on the live database): [company => cash-out]
     */
    public static function cashOuts(array $companies): array
    {
        $ids = array_values(array_unique(array_map('intval', $companies)));
        $zero = ['total' => 0, 'per_share' => 0, 'credits' => 0, 'goods' => 0, 'hulls' => 0, 'debts' => 0];
        $out = array_fill_keys($ids, $zero);
        if (!$ids) {
            return $out;
        }
        $rows = DB::table('companies')->whereIn('company_id', $ids)->whereNull('eliminated_at')
            ->get(['company_id', 'credits', 'compute_debt', 'debt'])->keyBy('company_id');
        $live = $rows->keys()->map(fn ($id) => (int) $id)->all();
        if (!$live) {
            return $out;
        }
        // Goods and equipment at a steady price, wherever they are (#685):
        // each good's best listed House bid anywhere, averaged over the last
        // BID_DAYS game days (bestBids). Valued at the bid where each drone
        // happened to be (#670), cash-out swung 20 to 30% a game day as drones
        // flew between exchanges and the Mars moons (where nothing is bid, so
        // goods counted 0), and buying from the bank on a low and selling
        // back on a high paid 28% in a game day (round 70)
        $bids = self::bestBids();
        $goods = array_fill_keys($live, 0);
        foreach (DB::table('holdings as h')->join('agents as a', 'a.agent_id', '=', 'h.drone')
                     ->whereIn('a.company_id', $live)->whereNull('a.wrecked_at')->where('h.units', '>', 0)
                     ->groupBy('a.company_id', 'h.resource')->selectRaw('a.company_id, h.resource, SUM(h.units) AS units')->get() as $h) {
            $goods[(int) $h->company_id] += (int) $h->units * ($bids[(int) $h->resource] ?? 0);
        }

        // Hulls: a fixed value falling with age, nothing for a stranded drone
        $now = World::gameNow();
        $hullValue = (int) config('forge.startup_hull_value');
        $life = max(1, (int) config('forge.hull_life_days')) * 86400;
        $strandedSince = $now - (int) config('forge.stranded_days') * 86400;
        $drones = DB::table('agents')->whereIn('company_id', $live)->whereNull('wrecked_at')
            ->pluck('company_id', 'agent_id')->map(fn ($c) => (int) $c)->all();
        $hulls = array_fill_keys($live, 0);
        if ($drones) {
            $fuel = DB::table('holdings')->whereIn('drone', array_keys($drones))->where('resource', Release::FUEL)
                ->where('installed', 0)->where('locked', 0)->where('units', '>', 0)
                ->pluck('drone')->map(fn ($d) => (int) $d)->flip()->all();
            // A drone with no fuel aboard still counts if it bought some lately
            $empty = array_values(array_diff(array_keys($drones), array_keys($fuel)));
            $bought = $empty ? DB::table('events')->whereIn('drone', $empty)->where('resource', Release::FUEL)
                ->where('event_type', 1)->where('outcome', 1)->where('game_t', '>', $strandedSince)
                ->distinct()->pluck('drone')->map(fn ($d) => (int) $d)->flip()->all() : [];
            $flying = array_values(array_filter(array_keys($drones), fn ($d) => isset($fuel[$d]) || isset($bought[$d])));
            foreach (self::born($flying, $now) as $drone => $born) {
                $hulls[$drones[$drone]] += (int) floor($hullValue * max(0, 1 - ($now - $born) / $life));
            }
        }

        $counts = self::counts($live);
        foreach ($rows as $id => $c) {
            $id = (int) $id;
            $credits = (int) $c->credits;
            $debts = (int) $c->compute_debt + (int) $c->debt;
            $total = $credits + $goods[$id] + $hulls[$id] - $debts;
            $out[$id] = ['total' => $total, 'per_share' => max(0, intdiv(max(0, $total), $counts[$id])),
                'credits' => $credits, 'goods' => $goods[$id], 'hulls' => $hulls[$id], 'debts' => $debts];
        }
        return $out;
    }

    /**
     * When each drone was made: its first event's game time, or $now for
     * one with none. It never changes, so it is kept: the lookup walks every
     * one of a drone's events (#723: a dashboard rebuild took 90 s)
     */
    private static function born(array $drones, int $now): array
    {
        if (!$drones) {
            return [];
        }
        $kept = Cache::many(array_map(fn ($d) => "drone_born:$d", $drones));
        $born = [];
        foreach ($drones as $d) {
            $t = $kept["drone_born:$d"] ?? null;
            if ($t === null) {
                $t = EventHistory::firstGameT((int) $d);   // archived or not (#694)
                if ($t !== null) {
                    Cache::forever("drone_born:$d", (int) $t);
                }
            }
            $born[$d] = $t !== null ? (int) $t : $now;
        }
        return $born;
    }

    /** Game days over which cash-out averages each good's best House bid (#685) */
    public const BID_DAYS = 7;

    /**
     * Records today's best listed House bid anywhere for each good it buys
     * (house_market, side 1) in house_best_bids, once a game day, on the
     * website's tick, and keeps the last 30 game days. Returns whether it
     * recorded.
     */
    public static function recordBestBids(): bool
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('house_best_bids')) {
            return false;
        }
        $today = HouseMarket::today(DB::connection());
        if (DB::table('house_best_bids')->where('day', $today)->exists()) {
            return false;
        }
        $rows = DB::table('house_market')->where('side', 1)->where('price', '>', 0)->where('from_day', '<=', $today)
            ->groupBy('resource')->selectRaw('resource, MAX(price) AS price')->get()
            ->map(fn ($r) => ['day' => $today, 'resource' => (int) $r->resource, 'price' => (int) $r->price])->all();
        DB::table('house_best_bids')->insertOrIgnore($rows);
        DB::table('house_best_bids')->where('day', '<', $today - 30)->delete();
        return true;
    }

    /**
     * Each good's best listed House bid anywhere, averaged over the last
     * BID_DAYS game days that have one recorded, today included: [resource
     * => credits]. A recorded day with no bid for a good counts 0. Cached
     * for a real minute.
     */
    public static function bestBids(): array
    {
        $today = HouseMarket::today(DB::connection());
        return Cache::remember("best_bids:$today", 60, function () use ($today) {
            if (!\Illuminate\Support\Facades\Schema::hasTable('house_best_bids')) {
                return [];
            }
            self::recordBestBids();
            $from = $today - self::BID_DAYS + 1;
            $days = max(1, DB::table('house_best_bids')->whereBetween('day', [$from, $today])->distinct()->count('day'));
            return DB::table('house_best_bids')->whereBetween('day', [$from, $today])->groupBy('resource')
                ->selectRaw('resource, SUM(price) AS total')->get()
                ->mapWithKeys(fn ($r) => [(int) $r->resource => intdiv((int) $r->total, $days)])->all();
        });
    }

    /**
     * A share's dividends over the last 30 game days: what the company
     * actually paid its holders since it matured (#673). It was estimated
     * from profit, so a company just matured sold at a premium earned while
     * it was a startup, before it had paid anything (round 68: +31%)
     */
    public static function dividends30(int $company): int
    {
        return self::dividends30Many([$company])[$company];
    }

    /** dividends30() for several companies at once: [company => credits a share] */
    public static function dividends30Many(array $companies): array
    {
        return self::dividendsOverMany($companies, 30);
    }

    /** The game days of dividends the bank's selling price adds to cash-out (#781) */
    public static function premiumDays(): int
    {
        return max(0, (int) config('forge.bank_premium_days', 10));
    }

    /** A share's dividends over the last $days game days, for several companies: [company => credits a share] */
    public static function dividendsOverMany(array $companies, int $days): array
    {
        $ids = array_values(array_unique(array_map('intval', $companies)));
        $out = array_fill_keys($ids, 0);
        if (!$ids) {
            return $out;
        }
        $epoch = (int) DB::table('game_clock')->where('id', 1)->value('epoch');
        $today = intdiv(World::gameNow() - $epoch, 86400);
        $counts = self::counts($ids);
        foreach (DB::table('player_dividends')->whereIn('company_id', $ids)->where('day', '>=', $today - $days)
                     ->where('day', '<', $today)->groupBy('company_id')
                     ->selectRaw('company_id, SUM(amount) AS paid')->get() as $r) {
            $out[(int) $r->company_id] = intdiv((int) $r->paid, $counts[(int) $r->company_id]);
        }
        return $out;
    }

    /**
     * The bank's prices for a company's shares and the range players trade
     * in: ['buys_at', 'sells_at', 'low', 'high', 'bank_holds']. $cash: its
     * cashOut() already worked out, so a page that shows its parts quotes the
     * same figures (#716: the company page's credits differed from its tile)
     */
    public static function prices(int $company, ?array $cash = null): array
    {
        return self::quote(($cash ?? self::cashOut($company))['per_share'], 0, self::bankHolds([$company])[$company]);
    }

    /** The highest price a share order may name (#928: players set prices, with no band) */
    public const MAX_PRICE = 9999999;

    /**
     * prices() from its parts (#928): the bank buys and sells at close-out
     * value (its sells_at is its buys_at: no dividend premium, so nobody
     * gains by buying from it and selling straight back), and players trade
     * at any price they agree, with no floor or band ($premium is unused)
     */
    private static function quote(int $buys, int $premium, int $bankHolds): array
    {
        return ['buys_at' => $buys, 'sells_at' => $buys, 'low' => 1, 'high' => self::MAX_PRICE, 'bank_holds' => $bankHolds];
    }

    /**
     * The tick (#928): the shares the bank holds sell into players' standing
     * buy orders priced above close-out value, best price first, at the
     * order's price; what it gets leaves the game. How many shares it sold
     */
    public static function bankSells(): int
    {
        $sold = 0;
        foreach (DB::table('bank_shares')->where('units', '>', 0)->pluck('units', 'company_id') as $company => $units) {
            $company = (int) $company;
            if (!self::company($company) || self::dissolving($company)) {
                continue;
            }
            $closeOut = self::cashOut($company)['per_share'];
            $orders = DB::table('player_share_orders')->where('company_id', $company)->where('side', 'buy')
                ->where('price', '>', $closeOut)->orderByDesc('price')->orderBy('id')->get();
            foreach ($orders as $o) {
                $left = (int) (DB::table('bank_shares')->where('company_id', $company)->value('units') ?? 0);
                if ($left <= 0) {
                    break;
                }
                $buyer = (int) $o->user_id;
                $n = min($left, (int) $o->units);
                if (Ownership::buyProblem($buyer, $company, $n) !== null) {
                    continue;
                }
                $done = DB::transaction(function () use ($o, $buyer, $company, $n, $closeOut) {
                    $at = (int) $o->price;
                    if (!self::charge($buyer, $n * $at, $at, $closeOut)) {   // starting credits only at or below close-out
                        DB::table('player_share_orders')->where('id', $o->id)->delete();   // it can't pay: its order goes
                        return 0;
                    }
                    self::move(null, $buyer, $company, $n);
                    CreditFlows::record('bank_sold_shares', -$n * $at, $company, $buyer);   // leaves the game (#937)
                    DB::table('player_share_trades')->insert(['company_id' => $company, 'seller_id' => null, 'buyer_id' => $buyer,
                        'units' => $n, 'price' => $at, 'game_t' => World::gameNow()]);
                    $rest = (int) $o->units - $n;
                    $rest > 0 ? DB::table('player_share_orders')->where('id', $o->id)->update(['units' => $rest])
                              : DB::table('player_share_orders')->where('id', $o->id)->delete();
                    if (Ownership::soleHolder($company) === $buyer) {
                        Loans::takeover($company);
                    }
                    return $n;
                });
                $sold += $done;
            }
        }
        return $sold;
    }

    /** The shares the bank holds of each company: [company => units] */
    private static function bankHolds(array $companies): array
    {
        $ids = array_values(array_unique(array_map('intval', $companies)));
        $out = array_fill_keys($ids, 0);
        if ($ids) {
            foreach (DB::table('bank_shares')->whereIn('company_id', $ids)->pluck('units', 'company_id') as $id => $units) {
                $out[(int) $id] = (int) $units;
            }
        }
        return $out;
    }

    public static function held(int $user, int $company): int
    {
        return (int) (DB::table('player_shares')->where('user_id', $user)->where('company_id', $company)->value('units') ?? 0);
    }

    private static function move(?int $from, ?int $to, int $company, int $units): void
    {
        foreach ([[$from, -$units], [$to, $units]] as [$who, $delta]) {
            if ($who === null) {
                DB::statement('INSERT INTO bank_shares (company_id, units) VALUES (?, ?) ON DUPLICATE KEY UPDATE units = units + VALUES(units)',
                    [$company, $delta]);
            } else {
                DB::statement('INSERT INTO player_shares (user_id, company_id, units) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE units = units + VALUES(units)',
                    [$who, $company, $delta]);
            }
        }
        DB::table('player_shares')->where('units', '<=', 0)->delete();
    }

    /**
     * Takes `amount` from a buyer: its starting credits first when the price
     * is at or below the bank's selling price, then its other credits. False
     * when it can't pay.
     */
    private static function charge(int $user, int $amount, int $price, int $bankSells): bool
    {
        $row = DB::table('users')->where('user_id', $user)->lockForUpdate()->first(['credits', 'share_credits']);
        $fromStart = $price <= $bankSells ? min((int) $row->share_credits, $amount) : 0;
        $rest = $amount - $fromStart;
        if ($rest > (int) $row->credits) {
            return false;
        }
        DB::table('users')->where('user_id', $user)->update(['share_credits' => DB::raw('share_credits - ' . $fromStart),
            'credits' => DB::raw('credits - ' . $rest)]);
        return true;
    }

    private static function fail(int $status, string $error): array
    {
        return ['ok' => false, 'status' => $status, 'error' => $error];
    }

    /** Both accounts in the same market: the public one, or the same class group */
    public static function sameMarket(int $a, int $b): bool
    {
        $groups = DB::table('users')->whereIn('user_id', [$a, $b])->pluck('class_group_id', 'user_id');
        return ($groups[$a] ?? null) === ($groups[$b] ?? null);
    }

    /** Sells shares to the bank: paid when due, at the lower of the two cash-out values */
    public static function sellToBank(User $user, int $company, int $units): array
    {
        if (!self::company($company)) {
            return self::fail(404, DB::table('companies')->where('company_id', $company)->whereNotNull('eliminated_at')->exists()
                ? 'That company has ended: its shares are worth nothing, and nobody buys them.'
                : 'The bank buys only shares of a matured company that is still trading.');
        }
        if ($units < 1) {
            return self::fail(422, 'Sell at least 1 whole share.');   // #674
        }
        if (self::held((int) $user->user_id, $company) < $units) {
            return self::fail(422, 'You don\'t hold that many of its shares.');
        }
        $price = self::cashOut($company)['per_share'];
        $now = World::gameNow();
        $due = $now + (int) config('forge.bank_pay_days') * 86400;
        DB::transaction(function () use ($user, $company, $units, $price, $now, $due) {
            self::move((int) $user->user_id, null, $company, $units);
            DB::table('bank_payments')->insert(['user_id' => $user->user_id, 'company_id' => $company, 'units' => $units,
                'price_at_sale' => $price, 'sold_t' => $now, 'due_t' => $due]);
            DB::table('player_share_trades')->insert(['company_id' => $company, 'seller_id' => $user->user_id,
                'buyer_id' => null, 'units' => $units, 'price' => $price, 'game_t' => $now]);
        });
        // The first share sold makes it public and locks its code; the seller
        // can't buy its shares back for a while (#923)
        Ownership::sold((int) $user->user_id, $company);
        // Its sell orders can't offer shares it no longer holds (#683)
        $trimmed = self::trimSellOrders((int) $user->user_id, $company);
        return ['ok' => true, 'units' => $units, 'price_now' => $price, 'due' => DronePage::when($due), 'due_t' => $due,
            'orders_trimmed' => $trimmed];
    }

    /**
     * Cuts an account's sell orders for a company so that together they
     * never offer more shares than it holds (#683): the newest orders shrink
     * or go first. Returns how many shares it took off its orders.
     */
    public static function trimSellOrders(int $user, int $company): int
    {
        $over = (int) DB::table('player_share_orders')->where('user_id', $user)->where('company_id', $company)
            ->where('side', 'sell')->sum('units') - self::held($user, $company);
        $cut = 0;
        if ($over <= 0) {
            return 0;
        }
        foreach (DB::table('player_share_orders')->where('user_id', $user)->where('company_id', $company)
                     ->where('side', 'sell')->orderByDesc('id')->get(['id', 'units']) as $o) {
            $n = min($over - $cut, (int) $o->units);
            $n === (int) $o->units ? DB::table('player_share_orders')->where('id', $o->id)->delete()
                : DB::table('player_share_orders')->where('id', $o->id)->update(['units' => (int) $o->units - $n]);
            $cut += $n;
            if ($cut >= $over) {
                break;
            }
        }
        return $cut;
    }

    /** Buys shares the bank holds, at its selling price */
    public static function buyFromBank(User $user, int $company, int $units): array
    {
        if (!self::company($company)) {
            return self::fail(404, 'There is no such matured company.');
        }
        $p = self::prices($company);
        if ($units < 1) {
            return self::fail(422, 'Buy at least 1 whole share.');   // #665
        }
        // The bank doesn't sell the shares of a company that is dissolving (#757)
        if (self::dissolving($company)) {
            $left = ProcessorTax::broke($company)['dissolves_in'];
            return self::fail(409, 'The bank doesn\'t sell its shares now: it has had no credits for a while, and dissolves in '
                . $left . ' game days unless it earns some.');
        }
        if ($p['bank_holds'] < $units) {
            return self::fail(409, 'The bank holds ' . $p['bank_holds'] . ' of its shares.');
        }
        if ($problem = Ownership::buyProblem((int) $user->user_id, $company, $units)) {
            return self::fail(409, $problem);
        }
        $cost = $units * $p['sells_at'];
        return DB::transaction(function () use ($user, $company, $units, $p, $cost) {
            if (!self::charge((int) $user->user_id, $cost, $p['sells_at'], $p['sells_at'])) {
                return self::fail(409, 'You can\'t pay ' . number_format($cost) . ' credits.');
            }
            self::move(null, (int) $user->user_id, $company, $units);
            if (Ownership::soleHolder($company) === (int) $user->user_id) {
                Loans::takeover($company);   // a takeover makes its loans due at once (#928)
            }
            // What the bank is paid leaves the game (#937)
            CreditFlows::record('bank_sold_shares', -$cost, $company, (int) $user->user_id);
            DB::table('player_share_trades')->insert(['company_id' => $company, 'seller_id' => null,
                'buyer_id' => $user->user_id, 'units' => $units, 'price' => $p['sells_at'], 'game_t' => World::gameNow()]);
            return ['ok' => true, 'units' => $units, 'price' => $p['sells_at'], 'cost' => $cost];
        });
    }

    /**
     * Posts a standing order to buy or sell a company's shares, and trades it
     * at once against the best orders on the other side that it meets (at
     * their price). Returns what traded and what is left standing.
     */
    /** The peg columns to read, when the table has them (#766) */
    private static function pegColumns(string $alias): array
    {
        return \Illuminate\Support\Facades\Schema::hasColumn('player_share_orders', 'peg_ref')
            ? ["$alias.peg_ref", "$alias.peg_offset_bp"] : [];
    }

    /** An order's peg, for the book and the account (#766): peg (buys_at, sells_at or null) and offset_percent */
    private static function pegOf(object $o): array
    {
        $ref = $o->peg_ref ?? null;
        return ['peg' => $ref, 'offset_percent' => $ref === null ? null : round((int) $o->peg_offset_bp / 100, 2)];
    }

    /** The bank prices a pegged order can follow (#766) */
    public const PEGS = ['buys_at', 'sells_at'];

    /** The furthest a pegged order may sit from its bank price, in percent (#766) */
    public const PEG_MAX_PERCENT = 25;

    /**
     * A pegged order's price now (#766): its bank price plus or minus
     * offset_bp hundredths of a percent, rounded, kept inside the range
     * players trade in
     */
    public static function pegPrice(array $p, string $ref, int $offsetBp, string $side = 'buy'): int
    {
        $price = (int) round($p[$ref] * (10000 + $offsetBp) / 10000);
        // A pegged sell never stands under what the bank pays (#831: "sells_at
        // -0.5%" fell under cash-out when the bank's spread was narrower)
        if ($side === 'sell') {
            $price = max($price, (int) $p['buys_at']);
        }
        return max($p['low'], min($p['high'], max(1, $price)));
    }

    /**
     * Posts a standing order to buy or sell a company's shares, and trades it
     * at once against the best orders on the other side that it meets (at
     * their price). Returns what traded and what is left standing. With $peg
     * (['ref' => 'buys_at' or 'sells_at', 'offset_bp' => hundredths of a
     * percent]) its price follows that bank price (#766): $price is ignored,
     * and repeg() moves it as the bank's prices move. $placedT: the game time
     * a re-placed order was first placed, so it keeps its rest (#782).
     * $byScript: placed by the account's trading script, shown as such (#779).
     * $fillOnly: trade what meets it now and leave nothing standing (the
     * order ticket's Buy now / Sell now, #845).
     */
    public static function order(User $user, int $company, string $side, int $units, int $price, ?array $peg = null, ?int $placedT = null,
                                 bool $byScript = false, bool $fillOnly = false): array
    {
        if (!self::company($company)) {
            return self::fail(404, self::endedNote($company) ?? 'There is no such matured company.');
        }
        $pegNote = null;
        $p = null;
        if ($peg !== null) {
            $ref = (string) ($peg['ref'] ?? '');
            $bp = (int) ($peg['offset_bp'] ?? 0);
            if (!in_array($ref, self::PEGS, true) || abs($bp) > self::PEG_MAX_PERCENT * 100) {
                return self::fail(422, 'A pegged order follows buys_at or sells_at, within ' . self::PEG_MAX_PERCENT . '% of it.');
            }
            // The peg sets the price; one sent with it is set aside, and the answer says so (#831)
            $pegNote = $price > 0 ? 'A pegged order follows its peg: the price you sent was set aside.' : null;
            // One look at the bank's prices for the peg and the range alike (#831: a
            // clamp to one look fell under the range of the next)
            $p = self::prices($company);
            $price = self::pegPrice($p, $ref, $bp, $side);
            $peg = ['ref' => $ref, 'offset_bp' => $bp];
        }
        if (!in_array($side, ['buy', 'sell'], true) || $units < 1 || $price < 1) {
            return self::fail(422, 'An order is buy or sell, some shares, at a price of at least 1.');
        }
        $p ??= self::prices($company);
        if ($price < $p['low'] || $price > $p['high']) {
            return self::fail(422, 'That price is too far from the market: trades go between ' . $p['low'] . ' and '
                . $p['high'] . ' a share now.');
        }
        $me = (int) $user->user_id;
        if ($side === 'buy' && ($problem = Ownership::buyProblem($me, $company, $units))) {
            return self::fail(409, $problem);
        }
        if ($side === 'sell') {
            // Its sell orders together never offer more than it holds (#683)
            $offered = (int) DB::table('player_share_orders')->where('user_id', $me)->where('company_id', $company)
                ->where('side', 'sell')->sum('units');
            if (self::held($me, $company) - $offered < $units) {
                return self::fail(422, $offered > 0 ? 'You don\'t hold that many of its shares that aren\'t already in your sell orders ('
                    . $offered . ' are).' : 'You don\'t hold that many of its shares.');
            }
        }
        // Nor do its own buy and sell orders cross (#683): it would trade with itself
        $mine = DB::table('player_share_orders')->where('user_id', $me)->where('company_id', $company)
            ->where('side', $side === 'buy' ? 'sell' : 'buy');
        $crosses = $side === 'buy' ? (clone $mine)->where('price', '<=', $price)->min('price')
            : (clone $mine)->where('price', '>=', $price)->max('price');
        if ($crosses !== null) {
            // The standing order's price is what a new one must stay clear of
            // (#715: it quoted the new order's own price, "a buy at 600 or
            // more would cross it" against a sell at 50)
            return self::fail(422, 'You have an order to ' . ($side === 'buy' ? 'sell' : 'buy') . ' its shares at '
                . (int) $crosses . ': ' . ($side === 'buy' ? 'a buy at ' . (int) $crosses . ' or more' : 'a sell at ' . (int) $crosses . ' or less')
                . ' would cross it. Cancel that order first, or ' . ($side === 'buy' ? 'bid under ' : 'ask over ') . (int) $crosses . '.');
        }
        return DB::transaction(function () use ($me, $company, $side, $units, $price, $p, $peg, $placedT, $byScript, $pegNote, $fillOnly) {
            $traded = 0;
            $spent = 0;
            $other = $side === 'buy' ? 'sell' : 'buy';
            $book = DB::table('player_share_orders')->where('company_id', $company)->where('side', $other)
                ->where('user_id', '<>', $me)->where('price', $side === 'buy' ? '<=' : '>=', $price)
                ->orderBy('price', $side === 'buy' ? 'asc' : 'desc')->orderBy('id')->lockForUpdate()->get();
            foreach ($book as $o) {
                if ($traded >= $units) {
                    break;
                }
                if (!self::sameMarket($me, (int) $o->user_id)) {
                    continue;
                }
                $seller = $side === 'sell' ? $me : (int) $o->user_id;
                $buyer = $side === 'buy' ? $me : (int) $o->user_id;
                $n = min($units - $traded, (int) $o->units, self::held($seller, $company));
                if ($n < 1) {
                    if ($seller !== $me) {   // a seller who no longer holds them: its order goes
                        DB::table('player_share_orders')->where('id', $o->id)->delete();
                    }
                    continue;
                }
                $at = (int) $o->price;
                if ($at < $p['low'] || $at > $p['high']) {
                    continue;   // a standing order the market has moved away from
                }
                // A standing buy order that would now give its owner more
                // companies owned outright than they may have goes (#923)
                if ($buyer !== $me && Ownership::buyProblem($buyer, $company, $n) !== null) {
                    DB::table('player_share_orders')->where('id', $o->id)->delete();
                    continue;
                }
                if (!self::charge($buyer, $n * $at, $at, $p['sells_at'])) {
                    if ($buyer !== $me) {
                        DB::table('player_share_orders')->where('id', $o->id)->delete();
                        continue;
                    }
                    break;
                }
                DB::table('users')->where('user_id', $seller)->update(['credits' => DB::raw('credits + ' . ($n * $at))]);
                self::move($seller, $buyer, $company, $n);
                Ownership::sold($seller, $company);   // public, its code locked, and no buying back for a while (#923)
                if (Ownership::soleHolder($company) === $buyer) {
                    Loans::takeover($company);   // a takeover makes its loans due at once (#928)
                }
                // The resting order's owner earns a rebate from the bank (#782)
                $rebate = self::rebate($n * $at, $o->game_t ?? null);
                if ($rebate > 0) {
                    DB::table('users')->where('user_id', $o->user_id)->update(['credits' => DB::raw('credits + ' . $rebate)]);
                    CreditFlows::record('share_rebate', $rebate, $company, (int) $o->user_id);   // #937
                }
                DB::table('player_share_trades')->insert(['company_id' => $company, 'seller_id' => $seller,
                    'buyer_id' => $buyer, 'units' => $n, 'price' => $at, 'game_t' => World::gameNow()]
                    + ($rebate > 0 ? ['rebate' => $rebate, 'rebate_user_id' => (int) $o->user_id] : []));
                $left = (int) $o->units - $n;
                $left > 0 ? DB::table('player_share_orders')->where('id', $o->id)->update(['units' => $left])
                          : DB::table('player_share_orders')->where('id', $o->id)->delete();
                $traded += $n;
                $spent += $n * $at;
            }
            $id = null;
            $standing = $fillOnly ? 0 : $units - $traded;
            if ($side === 'sell' && $standing > 0) {
                Ownership::offered($company);   // its code is public from the moment its shares are offered (#931)
            }
            $note = null;
            // What stands of a buy order can't commit more than the account
            // can pay (#665): its buy orders together, with starting credits
            // only at or below the bank's selling price
            if ($standing > 0 && $side === 'buy') {
                $row = DB::table('users')->where('user_id', $me)->first(['credits', 'share_credits']);
                $can = (int) $row->credits + ($price <= $p['sells_at'] ? (int) $row->share_credits : 0);
                $committed = (int) DB::table('player_share_orders')->where('user_id', $me)->where('side', 'buy')
                    ->sum(DB::raw('units * price'));
                if ($committed + $standing * $price > $can) {
                    if ($traded === 0) {
                        return self::fail(422, 'You can\'t pay for that: your buy orders would commit '
                            . number_format($committed + $standing * $price) . ' credits, and you have ' . number_format($can)
                            . ' to buy at that price' . ($price > $p['sells_at'] ? ' (starting credits pay only at or below the bank\'s price, '
                            . $p['sells_at'] . ')' : '') . '.');
                    }
                    $note = 'The rest didn\'t stand: you can\'t pay for it.';
                    $standing = 0;
                }
            }
            if ($standing > 0) {
                $id = DB::table('player_share_orders')->insertGetId(['user_id' => $me, 'company_id' => $company,
                    'side' => $side, 'units' => $standing, 'price' => $price, 'created_at' => now(), 'game_t' => $placedT ?? World::gameNow()]
                    + ($byScript ? ['by_script' => 1] : [])
                    + ($peg !== null ? ['peg_ref' => $peg['ref'], 'peg_offset_bp' => $peg['offset_bp']] : []));
            }
            $note = trim(($note ?? '') . ' ' . ($pegNote ?? '')) ?: null;
            return ['ok' => true, 'traded' => $traded, 'credits' => $spent, 'standing' => $standing, 'order' => $id, 'price' => $price]
                + ($note ? ['note' => $note] : []);
        });
    }

    /**
     * The bank's rebate to a resting order for a trade of $credits (#782):
     * rebate_permille of it, rounded down, once the order has rested
     * rebate_rest_game_seconds since $placedT (an order from before the
     * rebate, with no placed time, counts as rested); 0 otherwise. The two
     * sides are always different players: order() never matches an account's
     * own orders
     */
    public static function rebate(int $credits, $placedT): int
    {
        $rest = (int) config('forge.rebate_rest_game_seconds', 3600);
        if ($placedT !== null && World::gameNow() - (int) $placedT < $rest) {
            return 0;
        }
        return intdiv(max(0, $credits) * max(0, (int) config('forge.rebate_permille', 5)), 1000);
    }

    /** How far back a trade between players sets the market price of new shares, in game seconds */
    public const OFFER_MARKET_DAYS = 7;

    /**
     * What a new share costs (#874): the higher of its cash-out value and
     * the market, the best standing player bid or the last trade between
     * two players over OFFER_MARKET_DAYS game days. At cash-out alone an
     * owner minted shares and sold them straight into a higher bid, and took
     * part of the other holders' dividends for no more than they were worth
     */
    public static function offerPrice(int $company, ?array $p = null): int
    {
        $p ??= self::prices($company);
        $bid = (int) DB::table('player_share_orders')->where('company_id', $company)->where('side', 'buy')->max('price');
        $last = DB::table('player_share_trades')->where('company_id', $company)->whereNotNull('seller_id')->whereNotNull('buyer_id')
            ->where(fn ($q) => $q->whereNull('issued')->orWhere('issued', 0))
            ->where('game_t', '>=', World::gameNow() - self::OFFER_MARKET_DAYS * 86400)->orderByDesc('id')->value('price');
        return max((int) $p['buys_at'], $bid, (int) ($last ?? 0));
    }

    /**
     * A company's open offering of new shares (#780), or null:
     * {id, units, left, raised, created_t, price (offerPrice: cash-out a share now, or the market if higher)}
     */
    public static function offering(int $company): ?array
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('player_share_offerings')) {
            return null;
        }
        $o = DB::table('player_share_offerings')->where('company_id', $company)->whereNull('closed_t')
            ->where('units_left', '>', 0)->orderByDesc('id')->first();
        return $o ? ['id' => (int) $o->id, 'units' => (int) $o->units, 'left' => (int) $o->units_left,
            'raised' => (int) $o->raised, 'created_t' => (int) $o->created_t, 'price' => self::offerPrice($company)] : null;
    }

    /**
     * Offering new shares of a matured company (#780), retired by #923:
     * always fails with OFFERS_RETIRED. Offerings already sold stay sold
     */
    public static function offer(User $user, int $company): array
    {
        // Retired (#923): nobody adds credits to a company, so new shares are
        // no longer sold; a public company's code borrows instead (#928)
        return self::fail(410, self::OFFERS_RETIRED);
    }

    /** Its owner withdraws a company's open offering; the shares already sold stay sold */
    public static function withdraw(User $user, int $company): bool
    {
        $owner = DB::table('companies')->where('company_id', $company)->value('owner_id');
        if ($owner === null || (int) $owner !== (int) $user->user_id) {
            return false;
        }
        return DB::table('player_share_offerings')->where('company_id', $company)->whereNull('closed_t')
            ->update(['closed_t' => World::gameNow()]) > 0;
    }

    /**
     * Buys new shares from a company's open offering (#780), at offerPrice:
     * its cash-out value a share now, or the market if that is higher
     * (#874). The credits go to the company, and its share count grows;
     * buying at cash-out or more leaves every share's cash-out as it was or
     * raises it. Starting credits pay, as they do at or below the bank's price
     */
    public static function buyOffered(User $user, int $company, int $units): array
    {
        if (!self::company($company)) {
            return self::fail(404, self::endedNote($company) ?? 'There is no such matured company.');
        }
        if ($units < 1) {
            return self::fail(422, 'Buy at least 1 new share.');
        }
        return DB::transaction(function () use ($user, $company, $units) {
            $o = DB::table('player_share_offerings')->where('company_id', $company)->whereNull('closed_t')
                ->where('units_left', '>', 0)->lockForUpdate()->first();
            if (!$o) {
                return self::fail(409, 'It has no new shares on offer now.');
            }
            if ($units > (int) $o->units_left) {
                return self::fail(409, 'Only ' . (int) $o->units_left . ' of its new shares are left.');
            }
            $p = self::prices($company);
            $price = self::offerPrice($company, $p);
            $cost = $units * $price;
            if ($price < 1 || !self::charge((int) $user->user_id, $cost, $price, $p['sells_at'])) {
                return self::fail(422, 'You can\'t pay ' . number_format($cost) . ' credits for them.');
            }
            DB::table('companies')->where('company_id', $company)->update(['credits' => DB::raw('credits + ' . $cost),
                'player_share_count' => DB::raw('IFNULL(player_share_count, ' . self::COUNT . ') + ' . $units)]);
            DB::statement('INSERT INTO player_shares (user_id, company_id, units) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE units = units + VALUES(units)',
                [(int) $user->user_id, $company, $units]);
            $left = (int) $o->units_left - $units;
            DB::table('player_share_offerings')->where('id', $o->id)->update(['units_left' => $left,
                'raised' => DB::raw('raised + ' . $cost)] + ($left === 0 ? ['closed_t' => World::gameNow()] : []));
            DB::table('player_share_trades')->insert(['company_id' => $company, 'seller_id' => null, 'buyer_id' => $user->user_id,
                'units' => $units, 'price' => $price, 'game_t' => World::gameNow(), 'issued' => 1]);
            return ['ok' => true, 'units' => $units, 'price' => $price, 'cost' => $cost, 'left' => $left, 'count' => self::count($company)];
        });
    }

    /** A company's offerings of new shares, newest first, for its charts' marks: [{units, sold, raised, created_t, closed_t}] */
    public static function offerings(int $company): array
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('player_share_offerings')) {
            return [];
        }
        return DB::table('player_share_offerings')->where('company_id', $company)->orderByDesc('id')->get()
            ->map(fn ($o) => ['units' => (int) $o->units, 'sold' => (int) $o->units - (int) $o->units_left, 'raised' => (int) $o->raised,
                'created_t' => (int) $o->created_t, 'closed_t' => $o->closed_t === null ? null : (int) $o->closed_t])->all();
    }

    /**
     * Moves a standing order to a new price (#845: the desk's move-price
     * button). The order keeps its id and its time on the book (and so its
     * rebate's rest) when it only waits at the new price; one that meets
     * the other side trades at once, as a new order would. A pegged order
     * moved by hand stops following its peg.
     */
    public static function moveOrder(User $user, int $order, int $price): array
    {
        $o = DB::table('player_share_orders')->where('id', $order)->where('user_id', $user->user_id)->first();
        if (!$o) {
            return self::fail(404, 'You have no such order.');
        }
        if ($price === (int) $o->price && ($o->peg_ref ?? null) === null) {
            return ['ok' => true, 'order' => $order, 'price' => $price, 'traded' => 0, 'standing' => (int) $o->units];
        }
        try {
            return self::moveInside($user, $o, $price);
        } catch (\App\Exceptions\ShareRefused $e) {
            return $e->result;
        }
    }

    private static function moveInside(User $user, object $o, int $price): array
    {
        return DB::transaction(function () use ($user, $o, $price) {
            DB::table('player_share_orders')->where('id', $o->id)->delete();
            $r = self::order($user, (int) $o->company_id, (string) $o->side, (int) $o->units, $price, null,
                $o->game_t === null ? null : (int) $o->game_t, (bool) ($o->by_script ?? false));
            if (!$r['ok']) {
                throw new \App\Exceptions\ShareRefused($r);
            }
            // Back under its old id, as repeg() keeps it (#873)
            if ($r['order'] !== null) {
                DB::table('player_share_orders')->where('id', $r['order'])->update(['id' => $o->id]);
                $r['order'] = (int) $o->id;
            }
            return $r;
        });
    }

    public static function cancel(User $user, int $order): bool
    {
        return DB::table('player_share_orders')->where('id', $order)->where('user_id', $user->user_id)->delete() > 0;
    }

    /**
     * Who holds a matured company's shares (#666): players by name, and the
     * bank, most first: [['name', 'units', 'bank']]
     */
    public static function holders(int $company): array
    {
        return self::holdersMany([$company])[$company];
    }

    /** holders() for several companies at once: [company => holders] */
    private static function holdersMany(array $companies, ?array $bank = null): array
    {
        $ids = array_values(array_unique(array_map('intval', $companies)));
        $out = array_fill_keys($ids, []);
        if (!$ids) {
            return $out;
        }
        foreach (DB::table('player_shares as s')->join('users as u', 'u.user_id', '=', 's.user_id')
                     ->whereIn('s.company_id', $ids)->where('s.units', '>', 0)->orderByDesc('s.units')->orderBy('s.user_id')
                     ->get(['s.company_id', 'u.login', 's.units']) as $r) {
            $out[(int) $r->company_id][] = ['name' => $r->login, 'units' => (int) $r->units, 'bank' => false];
        }
        $bank ??= self::bankHolds($ids);
        $counts = self::counts($ids);
        foreach ($ids as $id) {
            if (($bank[$id] ?? 0) > 0) {
                $out[$id][] = ['name' => 'The bank', 'units' => $bank[$id], 'bank' => true];
                usort($out[$id], fn ($a, $b) => $b['units'] <=> $a['units']);
            }
            // Each holder's part of the company (#780: new shares dilute it)
            foreach ($out[$id] as &$h) {
                $h['percent'] = round(100 * $h['units'] / $counts[$id], 1);
            }
            unset($h);
        }
        return $out;
    }

    /**
     * The latest trades of matured companies' shares between players (#676),
     * newest first: one company's, or every company's: [company, name,
     * units, price, seller, buyer, seller_name, buyer_name, seller_is_bank,
     * buyer_is_bank, when, game_time]. The bank is a side with no account:
     * seller or buyer null, its name "The bank" (#686: the side was blank)
     */
    public static function trades(?int $company = null, int $limit = 25): array
    {
        return DB::table('player_share_trades as t')
            ->join('companies as c', 'c.company_id', '=', 't.company_id')
            ->leftJoin('users as s', 's.user_id', '=', 't.seller_id')
            ->leftJoin('users as b', 'b.user_id', '=', 't.buyer_id')
            ->when($company !== null, fn ($q) => $q->where('t.company_id', $company))
            ->orderByDesc('t.id')->limit($limit)
            ->get(['t.company_id', 'c.name', 't.units', 't.price', 's.login as seller', 'b.login as buyer', 't.game_t', 't.rebate', 't.rebate_user_id', 't.seller_id', 't.issued'])
            ->map(fn ($t) => ['company' => (int) $t->company_id, 'name' => $t->name, 'units' => (int) $t->units,
                'price' => (int) $t->price, 'seller' => $t->seller, 'buyer' => $t->buyer,
                // New shares the company sold (#780): no seller, and not the bank
                'issued' => (bool) ($t->issued ?? false),
                // The bank's rebate to the resting order, and which side it was (#782)
                'rebate' => (int) ($t->rebate ?? 0),
                'rebate_to' => ($t->rebate_user_id ?? null) === null ? null : ((int) $t->rebate_user_id === (int) $t->seller_id ? 'seller' : 'buyer'),
                'seller_name' => $t->seller ?? (($t->issued ?? 0) ? 'New shares' : 'The bank'), 'buyer_name' => $t->buyer ?? 'The bank',
                'seller_is_bank' => $t->seller === null && !($t->issued ?? 0), 'buyer_is_bank' => $t->buyer === null,
                'when' => DronePage::when((int) $t->game_t), 'game_time' => (int) $t->game_t])->all();
    }

    /**
     * Every matured company still trading, for the stock exchange page and
     * API (#666): id, name, its prices, the bank's shares, and its holders.
     * A handful of queries for them all (#726), not several a company
     */
    public static function market(): array
    {
        $companies = DB::table('companies')->where('startup', 0)->whereNotNull('owner_id')->whereNotNull('matured_t')
            ->whereNull('eliminated_at')->orderBy('name')->get(['company_id', 'name']);
        $ids = $companies->pluck('company_id')->map(fn ($id) => (int) $id)->all();
        $cash = self::cashOuts($ids);
        $premium = self::dividendsOverMany($ids, self::premiumDays());
        $dividends = self::dividends30Many($ids);
        $bank = self::bankHolds($ids);
        $holders = self::holdersMany($ids, $bank);
        $counts = self::counts($ids);
        $policy = self::dividendPermilles($ids);   // the share of profit its code pays (#930)
        // Open offerings of new shares (#780): units left
        $offered = $ids && \Illuminate\Support\Facades\Schema::hasTable('player_share_offerings')
            ? DB::table('player_share_offerings')->whereIn('company_id', $ids)->whereNull('closed_t')->where('units_left', '>', 0)
                ->pluck('units_left', 'company_id')->all() : [];
        return $companies->map(fn ($c) => ['company' => (int) $c->company_id, 'name' => $c->name]
            + self::quote($cash[(int) $c->company_id]['per_share'], $premium[(int) $c->company_id], $bank[(int) $c->company_id])
            + ['dividends30' => $dividends[(int) $c->company_id], 'count' => $counts[(int) $c->company_id],
                'dividend_percent' => $policy[(int) $c->company_id] / 10,
                'offered' => (int) ($offered[(int) $c->company_id] ?? 0), 'holders' => $holders[(int) $c->company_id]])->all();
    }

    /** Game days a trade between players stays recent enough to quote its premium (#716) */
    public const PREMIUM_DAYS = 7;

    /**
     * The stock exchange page's table (#699): market(), and for each company
     * its dividends and yield over 30 game days, its cash-out a share over
     * them (spark), the last trade between two players against cash-out, and
     * its health; best yield first. Its queries cover every company at once
     * (#726); the page keeps it a minute (StockExchange)
     */
    public static function board(): array
    {
        $market = self::market();
        $ids = array_column($market, 'company');
        $now = World::gameNow();
        $today = MarketHistory::today();
        $spark = array_fill_keys($ids, []);
        if ($ids && \Illuminate\Support\Facades\Schema::hasColumn('company_days', 'cash_out')) {
            $withShares = \Illuminate\Support\Facades\Schema::hasColumn('company_days', 'shares');
            foreach (DB::table('company_days')->whereIn('company_id', $ids)->where('day', '>', $today - 30)
                         ->whereNotNull('cash_out')->orderBy('company_id')->orderBy('day')
                         ->get(array_merge(['company_id', 'day', 'cash_out'], $withShares ? ['shares'] : [])) as $r) {
                // Over the shares it had that day (#780)
                $spark[(int) $r->company_id][] = ['day' => (int) $r->day, 'v' => intdiv(max(0, (int) $r->cash_out), max(1, (int) ($r->shares ?? self::COUNT)))];
            }
        }
        // The last trade between two players (#716): the bank's trades are at
        // its own prices, and an old trade says little about today's, so the
        // premium is shown only for a recent one
        $last = $ids ? DB::table('player_share_trades as t')
            ->joinSub(DB::table('player_share_trades')->whereIn('company_id', $ids)->whereNotNull('seller_id')->whereNotNull('buyer_id')
                ->groupBy('company_id')->selectRaw('MAX(id) AS id'), 'l', 'l.id', '=', 't.id')
            ->get(['t.company_id', 't.price', 't.game_t'])->keyBy('company_id')->all() : [];
        $health = CompanyHealth::many($ids);
        $windsUp = WindUp::daysLeftMany(array_column($market, 'company'));
        foreach ($market as &$m) {
            $id = $m['company'];
            $l = $last[$id] ?? null;
            $h = $health[$id];
            $m['yield_percent'] = $m['buys_at'] > 0 ? round(100 * $m['dividends30'] / $m['buys_at'], 1) : null;
            $m['spark'] = $spark[$id];
            $m['last_price'] = $l ? (int) $l->price : null;
            $m['last_days_ago'] = $l ? intdiv(max(0, $now - (int) $l->game_t), 86400) : null;
            $m['premium_percent'] = $l && $m['buys_at'] > 0 && $m['last_days_ago'] <= self::PREMIUM_DAYS
                ? (int) round(100 * ($l->price - $m['buys_at']) / $m['buys_at']) : null;
            $m['stalled'] = (bool) $h['stalled'];
            $m['at_risk'] = (bool) $h['at_risk'];
            $m['failing'] = $h['failing_reason'] !== null;
            // Counting down to dissolving (#758: round 72's top row by yield
            // dissolved a game day later, shown only as "stalled")
            $m['dissolves_in'] = $h['dissolves_in'] ?? null;
            // Winding up after 60 game days without a sale (#875)
            $m['winds_up_in'] = $windsUp[(int) $m['company']] ?? null;
            $m['health_note'] = $m['dissolves_in'] !== null ? $h['at_risk_reason']
                : ($h['at_risk'] ? $h['at_risk_reason'] : ($h['stalled'] ? $h['stalled_reason'] : $h['failing_reason']));
            // Its share's cash-out over the sparkline's 30 game days, and with
            // the dividends a share paid, its total return (#758: cash-out
            // growth, 5 to 80% in round 72, is most of what a share returns,
            // and only the 1 to 3% yield was shown)
            $first = $m['spark'][0]['v'] ?? null;
            $m['growth_percent'] = $first ? round(100 * ($m['buys_at'] - $first) / $first, 1) : null;
            $m['return_percent'] = $first ? round(100 * ($m['buys_at'] - $first + $m['dividends30']) / $first, 1) : null;
        }
        unset($m);
        // Best total return first, then yield (#716, #758); a company
        // counting down to dissolving last, whatever it paid
        $key = fn ($m) => [$m['dissolves_in'] === null ? 1 : 0, $m['return_percent'] ?? $m['yield_percent'] ?? -INF, $m['yield_percent'] ?? -INF];
        usort($market, fn ($a, $b) => [$key($b), $a['name']] <=> [$key($a), $b['name']]);
        return $market;
    }

    /**
     * A company's standing orders, best first on each side: those within
     * the range players trade in now. An order the range has moved away from
     * can't trade, so it isn't shown until the range comes back (#665)
     */
    public static function book(int $company): array
    {
        $p = self::prices($company);
        $rows = fn ($side, $dir) => DB::table('player_share_orders as o')->join('users as u', 'u.user_id', '=', 'o.user_id')
            ->where('o.company_id', $company)->where('o.side', $side)->whereBetween('o.price', [$p['low'], $p['high']])
            ->orderBy('o.price', $dir)->orderBy('o.id')
            ->limit(20)->get(array_merge(['o.id', 'o.units', 'o.price', 'u.login as by'], self::pegColumns('o'),
                \Illuminate\Support\Facades\Schema::hasColumn('player_share_orders', 'by_script') ? ['o.by_script'] : []))
            // A trading script's order says so (#779)
            ->map(fn ($o) => ['id' => (int) $o->id, 'units' => (int) $o->units, 'price' => (int) $o->price, 'by' => $o->by,
                'script' => (bool) ($o->by_script ?? false)] + self::pegOf($o))->all();
        return ['sell' => $rows('sell', 'asc'), 'buy' => $rows('buy', 'desc')];
    }

    /**
     * An account's share trades since a game time, newest first (#715:
     * nothing told a player when an order filled): company_id, name, side
     * (bought or sold), units, price, with (the other side's name, or "the
     * bank"), when, ago, game_time
     */
    public static function fills(int $user, int $since, int $limit = 20): array
    {
        return DB::table('player_share_trades as t')->join('companies as c', 'c.company_id', '=', 't.company_id')
            ->leftJoin('users as s', 's.user_id', '=', 't.seller_id')->leftJoin('users as b', 'b.user_id', '=', 't.buyer_id')
            ->where(fn ($q) => $q->where('t.seller_id', $user)->orWhere('t.buyer_id', $user))
            ->where('t.game_t', '>', $since)->orderByDesc('t.id')->limit($limit)
            ->get(['t.company_id', 'c.name', 't.seller_id', 't.units', 't.price', 't.game_t', 's.login as seller', 'b.login as buyer',
                't.rebate', 't.rebate_user_id', 't.issued'])
            ->map(function ($t) use ($user) {
                $sold = (int) $t->seller_id === $user;
                return ['company_id' => (int) $t->company_id, 'name' => $t->name, 'side' => $sold ? 'sold' : 'bought',
                    'units' => (int) $t->units, 'price' => (int) $t->price,
                    // What the bank paid this account for its resting order (#782)
                    'rebate' => (int) ($t->rebate_user_id ?? 0) === $user ? (int) $t->rebate : 0,
                    'with' => ($sold ? $t->buyer : $t->seller) ?? (!$sold && ($t->issued ?? 0) ? 'the company (new shares)' : 'the bank'),
                    'when' => DronePage::when((int) $t->game_t), 'ago' => DronePage::ago((int) $t->game_t), 'game_time' => (int) $t->game_t];
            })->all();
    }

    /**
     * An account's shares, orders and payments due from the bank, and its
     * share trades over the last FILLS_DAYS game days (#715). Each order
     * says whether it can trade now: one outside the range players trade
     * in (out_of_range) stands, but can't fill until the range comes back
     */
    public static function of(User $user): array
    {
        $held = DB::table('player_shares as s')->join('companies as c', 'c.company_id', '=', 's.company_id')
            ->where('s.user_id', $user->user_id)->where('s.units', '>', 0)->orderBy('c.name')
            ->get(['s.company_id', 's.units', 'c.name', 'c.eliminated_at']);
        // Shares of companies that have ended are worth nothing and don't
        // trade: listed apart, not among the holdings (#758)
        $ended = $held->whereNotNull('eliminated_at')->map(fn ($h) => ['company_id' => (int) $h->company_id, 'name' => $h->name,
            'units' => (int) $h->units, 'ended' => DronePage::when((int) $h->eliminated_at)])->values()->all();
        $holdings = $held->whereNull('eliminated_at')
            ->map(function ($h) {
                $p = self::prices((int) $h->company_id);
                return ['company_id' => (int) $h->company_id, 'name' => $h->name, 'units' => (int) $h->units,
                    'cash_out' => $p['buys_at'], 'value' => (int) $h->units * $p['buys_at']];
            })->values()->all();
        $range = [];
        $orders = DB::table('player_share_orders as o')->join('companies as c', 'c.company_id', '=', 'o.company_id')
            ->where('o.user_id', $user->user_id)->orderBy('o.id')
            ->get(array_merge(['o.id', 'o.company_id', 'c.name', 'o.side', 'o.units', 'o.price'], self::pegColumns('o')))
            ->map(function ($o) use (&$range) {
                $p = $range[$o->company_id] ??= self::tradable((int) $o->company_id) ? self::prices((int) $o->company_id) : null;
                return ['id' => (int) $o->id, 'company_id' => (int) $o->company_id, 'name' => $o->name,
                    'side' => $o->side, 'units' => (int) $o->units, 'price' => (int) $o->price,
                    'low' => $p['low'] ?? null, 'high' => $p['high'] ?? null,
                    'out_of_range' => $p === null || (int) $o->price < $p['low'] || (int) $o->price > $p['high']] + self::pegOf($o);
            })->all();
        $due = DB::table('bank_payments as b')->join('companies as c', 'c.company_id', '=', 'b.company_id')
            ->where('b.user_id', $user->user_id)->whereNull('b.paid_t')->orderBy('b.due_t')
            ->get(['b.id', 'b.company_id', 'c.name', 'b.units', 'b.price_at_sale', 'b.due_t'])
            ->map(fn ($b) => ['id' => (int) $b->id, 'company_id' => (int) $b->company_id, 'name' => $b->name,
                'units' => (int) $b->units, 'price_at_sale' => (int) $b->price_at_sale, 'due' => DronePage::when((int) $b->due_t)])->all();
        $epoch = (int) DB::table('game_clock')->where('id', 1)->value('epoch');
        $today = intdiv(World::gameNow() - $epoch, 86400);
        $dividends = (int) DB::table('player_dividends')->where('user_id', $user->user_id)->where('day', '>=', $today - 30)->sum('amount');
        // Its last FILLS share trades, however old (#758: 7 game days was
        // under 6 real minutes at full pace, and players missed their fills)
        return ['holdings' => $holdings, 'ended_holdings' => $ended, 'orders' => $orders, 'bank_payments' => $due,
            'dividends_30_days' => $dividends, 'fills' => self::fills((int) $user->user_id, 0, self::FILLS)];
    }

    /**
     * Pays matured companies' dividends for the game days the events engine
     * has settled (company_profits): forge.dividend_permille of each day's
     * profit after losses carried, never more than the company's credits,
     * to its holders by their shares: players' accounts, and the bank (which
     * keeps what its shares earn). Days before it matured are not paid.
     * Returns how many company-days it paid.
     */
    public static function payDividends(): int
    {
        $epoch = (int) DB::table('game_clock')->where('id', 1)->value('epoch');
        $permille = (int) config('forge.dividend_permille');
        $paid = 0;
        $companies = DB::table('companies')->where('startup', 0)->whereNotNull('owner_id')->whereNotNull('matured_t')
            ->whereNull('eliminated_at')->get(['company_id', 'matured_t', 'player_dividend_day']);
        $own = self::dividendPermilles($companies->pluck('company_id')->all());   // set by each one's code (#930)
        foreach ($companies as $c) {
            $from = $c->player_dividend_day !== null ? (int) $c->player_dividend_day
                : intdiv((int) $c->matured_t - $epoch, 86400);
            $days = DB::table('company_profits')->where('company', $c->company_id)->where('day', '>', $from)
                ->orderBy('day')->get(['day', 'profit', 'carry_before']);
            $share = $own[(int) $c->company_id] ?? $permille;
            // In default on a loan (#928): its dividends stop until its lenders are paid
            if (Loans::inDefault((int) $c->company_id)) {
                $share = 0;
            }
            foreach ($days as $d) {
                DB::transaction(function () use ($c, $d, $share) {
                    $pool = max(0, min(0, (int) $d->carry_before) + (int) $d->profit);
                    $due = intdiv($pool * $share, 1000);
                    $credits = (int) DB::table('companies')->where('company_id', $c->company_id)->lockForUpdate()->value('credits');
                    $due = min($due, max(0, $credits));
                    $holders = DB::table('player_shares')->where('company_id', $c->company_id)->where('units', '>', 0)
                        ->get(['user_id', 'units'])->map(fn ($h) => [(int) $h->user_id, (int) $h->units])->all();
                    $bank = (int) (DB::table('bank_shares')->where('company_id', $c->company_id)->value('units') ?? 0);
                    if ($bank > 0) {
                        $holders[] = [null, $bank];
                    }
                    $total = 0;
                    foreach ($holders as [$user, $units]) {
                        $amount = intdiv($due * $units, self::count((int) $c->company_id));
                        if ($amount <= 0) {
                            continue;
                        }
                        if ($user !== null) {
                            DB::table('users')->where('user_id', $user)->update(['credits' => DB::raw('credits + ' . $amount)]);
                        } else {
                            // The bank's part leaves the game (#937)
                            CreditFlows::record('dividend_to_bank', -$amount, (int) $c->company_id);
                        }
                        DB::table('player_dividends')->insert(['company_id' => $c->company_id, 'user_id' => $user,
                            'day' => (int) $d->day, 'units' => $units, 'amount' => $amount]);
                        $total += $amount;
                    }
                    DB::table('companies')->where('company_id', $c->company_id)->update([
                        'credits' => DB::raw('credits - ' . $total), 'player_dividend_day' => (int) $d->day]);
                });
                $paid++;
            }
        }
        return $paid;
    }

    /**
     * The share of profit each company pays as dividends, in thousandths, as
     * its code set it (set_dividend_share, #930); a company whose code never
     * set one pays forge.dividend_permille
     */
    public static function dividendPermilles(array $companies): array
    {
        $out = array_fill_keys(array_map('intval', $companies), (int) config('forge.dividend_permille'));
        if ($companies && \Illuminate\Support\Facades\Schema::hasColumn('companies', 'dividend_permille')) {
            foreach (DB::table('companies')->whereIn('company_id', $companies)->whereNotNull('dividend_permille')
                         ->pluck('dividend_permille', 'company_id') as $id => $p) {
                $out[(int) $id] = max(0, min(1000, (int) $p));
            }
        }
        return $out;
    }

    public static function dividendPermille(int $company): int
    {
        return self::dividendPermilles([$company])[$company];
    }

    /**
     * Pays out what companies' code asked to pay out (pay_out, #930): each to
     * the shareholders by their shares, as a dividend is paid, up to what the
     * company holds then; the bank's part leaves the game. A company that has
     * ended pays nothing. Returns how many payouts it settled.
     */
    public static function settlePayouts(): int
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('company_payouts')) {
            return 0;
        }
        $epoch = (int) DB::table('game_clock')->where('id', 1)->value('epoch');
        $due = DB::table('company_payouts')->whereNull('paid_t')->orderBy('id')->limit(200)->get();
        foreach ($due as $p) {
            DB::transaction(function () use ($p, $epoch) {
                $c = DB::table('companies')->where('company_id', $p->company_id)->lockForUpdate()
                    ->first(['credits', 'eliminated_at', 'startup']);
                $now = World::gameNow();
                $amount = ($c && $c->eliminated_at === null && !$c->startup && !Loans::inDefault((int) $p->company_id))
                    ? min((int) $p->amount, max(0, (int) $c->credits)) : 0;   // nothing while in default (#928)
                $holders = DB::table('player_shares')->where('company_id', $p->company_id)->where('units', '>', 0)
                    ->get(['user_id', 'units'])->map(fn ($h) => [(int) $h->user_id, (int) $h->units])->all();
                $bank = (int) (DB::table('bank_shares')->where('company_id', $p->company_id)->value('units') ?? 0);
                if ($bank > 0) {
                    $holders[] = [null, $bank];
                }
                $count = self::count((int) $p->company_id);
                $total = 0;
                foreach ($amount > 0 ? $holders : [] as [$user, $units]) {
                    $part = intdiv($amount * $units, $count);
                    if ($part <= 0) {
                        continue;
                    }
                    if ($user !== null) {
                        DB::table('users')->where('user_id', $user)->update(['credits' => DB::raw('credits + ' . $part)]);
                    } else {
                        CreditFlows::record('payout_to_bank', -$part, (int) $p->company_id);   // #937
                    }
                    DB::table('player_dividends')->insert(['company_id' => $p->company_id, 'user_id' => $user,
                        'day' => intdiv($now - $epoch, 86400), 'units' => $units, 'amount' => $part, 'payout' => 1]);
                    $total += $part;
                }
                if ($total > 0) {
                    DB::table('companies')->where('company_id', $p->company_id)->update(['credits' => DB::raw('credits - ' . $total)]);
                }
                DB::table('company_payouts')->where('id', $p->id)->update(['paid_t' => $now, 'paid' => $total]);
            });
        }
        return $due->count();
    }

    /**
     * Pays the bank's payments that are due: each at the lower of the share's
     * cash-out value on the day of the sale and now. Returns how many it paid.
     */
    /** Is the company counting down to dissolving (no credits for a while, #757)? */
    public static function dissolving(int $company): bool
    {
        return ProcessorTax::broke($company)['dissolves_in'] !== null;
    }

    public static function settleBankPayments(): int
    {
        $now = World::gameNow();
        $due = DB::table('bank_payments')->whereNull('paid_t')->where('due_t', '<=', $now)->orderBy('id')->limit(200)->get();
        foreach ($due as $b) {
            // A company that ended before the payment is paid for at 0, and
            // one counting down to dissolving waits until it recovers or ends
            // (#757: round 72's bank paid 4,000 for Juno 70-2's shares a game
            // day before it dissolved, its hulls still counted at cash-out)
            $c = DB::table('companies')->where('company_id', $b->company_id)->first(['eliminated_at', 'broke_since']);
            $ended = $c === null || $c->eliminated_at !== null;
            if (!$ended && self::dissolving((int) $b->company_id)) {
                continue;
            }
            $price = $ended ? 0 : min((int) $b->price_at_sale, self::cashOut((int) $b->company_id)['per_share']);
            $amount = (int) $b->units * $price;
            DB::transaction(function () use ($b, $now, $amount) {
                $done = DB::table('bank_payments')->where('id', $b->id)->whereNull('paid_t')->update(['paid_t' => $now, 'paid' => $amount]);
                if ($done) {
                    DB::table('users')->where('user_id', $b->user_id)->update(['credits' => DB::raw('credits + ' . $amount)]);
                    CreditFlows::record('bank_paid', $amount, (int) $b->company_id, (int) $b->user_id);   // #937
                }
            });
        }
        return $due->count();
    }
}
