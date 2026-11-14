<?php

namespace App\Services;

use App\User;
use Illuminate\Support\Facades\DB;

/**
 * Players' shares and the bank (#638, step 3 of the new rules in #635).
 *
 * A matured company's 100 shares (#667) are its player's (#637), and players trade
 * them. The price that anchors everything is the company's cash-out value: its
 * credits, plus what the House would pay for its goods and equipment where
 * they are now, plus each working drone's hull at a fixed value that falls
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

    /** Can players trade this company's shares: a matured company of the new rules, still trading? */
    public static function tradable(int $company): bool
    {
        return self::company($company) !== null;
    }

    /** A matured company of the new rules, still trading, or null */
    private static function company(int $company): ?object
    {
        $c = DB::table('companies')->where('company_id', $company)->first();
        return $c && (int) $c->seedling === 0 && $c->owner_id !== null && $c->matured_t !== null ? $c : null;
    }

    /**
     * What the company is worth if it were sold off now, and its parts:
     * ['total', 'per_share', 'credits', 'goods', 'hulls', 'debts']
     */
    public static function cashOut(int $company): array
    {
        $c = DB::table('companies')->where('company_id', $company)->first();
        if (!$c || $c->eliminated_at !== null) {
            return ['total' => 0, 'per_share' => 0, 'credits' => 0, 'goods' => 0, 'hulls' => 0, 'debts' => 0];
        }
        // Goods and equipment at the House's bid where each drone is
        $goods = (int) DB::table('holdings as h')
            ->join('agents as a', 'a.agent_id', '=', 'h.drone')
            ->join('agents as s', fn ($j) => $j->on('s.object_id', '=', 'a.object_id')->whereNotNull('s.station_id')->whereNull('s.wrecked_at'))
            ->join('latest_orders as o', fn ($j) => $j->on('o.drone', '=', 's.agent_id')->on('o.resource', '=', 'h.resource'))
            ->where('a.company_id', $company)->whereNull('a.wrecked_at')->where('h.units', '>', 0)
            ->where('o.event_type', 5)->where('o.price', '>', 0)->where('o.is_open', 1)
            ->sum(DB::raw('h.units * o.price'));

        // Hulls: a fixed value falling with age, nothing for a stranded drone
        $now = World::gameNow();
        $hullValue = (int) config('forge.seedling_hull_value');
        $life = max(1, (int) config('forge.hull_life_days')) * 86400;
        $strandedSince = $now - (int) config('forge.stranded_days') * 86400;
        $hulls = 0;
        foreach (DB::table('agents')->where('company_id', $company)->whereNull('wrecked_at')->get(['agent_id']) as $a) {
            $fuel = (int) DB::table('holdings')->where('drone', $a->agent_id)->where('resource', Release::FUEL)
                ->where('installed', 0)->where('locked', 0)->sum('units');
            if ($fuel <= 0 && !DB::table('events')->where('drone', $a->agent_id)->where('resource', Release::FUEL)
                    ->where('event_type', 1)->where('outcome', 1)->where('game_t', '>', $strandedSince)->exists()) {
                continue;
            }
            $born = (int) (DB::table('events')->where('drone', $a->agent_id)->min('game_t') ?? $now);
            $hulls += (int) floor($hullValue * max(0, 1 - ($now - $born) / $life));
        }

        $credits = (int) $c->credits;
        $debts = (int) $c->compute_debt + (int) $c->debt;
        $total = $credits + $goods + $hulls - $debts;
        return ['total' => $total, 'per_share' => max(0, intdiv(max(0, $total), self::COUNT)),
            'credits' => $credits, 'goods' => $goods, 'hulls' => $hulls, 'debts' => $debts];
    }

    /** A share's dividends over the last 30 game days, estimated from the company's profit */
    public static function dividends30(int $company): int
    {
        $epoch = (int) DB::table('game_clock')->where('id', 1)->value('epoch');
        $today = intdiv(World::gameNow() - $epoch, 86400);
        $profit = (int) DB::table('company_profits')->where('company', $company)
            ->where('day', '>=', $today - 30)->where('day', '<', $today)->where('profit', '>', 0)->sum('profit');
        return intdiv($profit * (int) config('forge.dividend_permille'), 1000 * self::COUNT);
    }

    /**
     * The bank's prices for a company's shares and the range players trade
     * in: ['buys_at', 'sells_at', 'low', 'high', 'bank_holds']
     */
    public static function prices(int $company): array
    {
        $buys = self::cashOut($company)['per_share'];
        $sells = $buys + self::dividends30($company);
        return ['buys_at' => $buys, 'sells_at' => $sells,
            'low' => (int) floor($sells * (float) config('forge.share_range_low')),
            'high' => (int) ceil($sells * (float) config('forge.share_range_high')),
            'bank_holds' => (int) (DB::table('bank_shares')->where('company_id', $company)->value('units') ?? 0)];
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
    private static function sameMarket(int $a, int $b): bool
    {
        $groups = DB::table('users')->whereIn('user_id', [$a, $b])->pluck('class_group_id', 'user_id');
        return ($groups[$a] ?? null) === ($groups[$b] ?? null);
    }

    /** Sells shares to the bank: paid when due, at the lower of the two cash-out values */
    public static function sellToBank(User $user, int $company, int $units): array
    {
        if (!self::company($company)) {
            return self::fail(404, 'The bank buys only shares of a matured company that is still trading.');
        }
        if ($units < 1 || self::held((int) $user->user_id, $company) < $units) {
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
        return ['ok' => true, 'units' => $units, 'price_now' => $price, 'due' => DronePage::when($due), 'due_t' => $due];
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
        if ($p['bank_holds'] < $units) {
            return self::fail(409, 'The bank holds ' . $p['bank_holds'] . ' of its shares.');
        }
        $cost = $units * $p['sells_at'];
        return DB::transaction(function () use ($user, $company, $units, $p, $cost) {
            if (!self::charge((int) $user->user_id, $cost, $p['sells_at'], $p['sells_at'])) {
                return self::fail(409, 'You can\'t pay ' . number_format($cost) . ' credits.');
            }
            self::move(null, (int) $user->user_id, $company, $units);
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
    public static function order(User $user, int $company, string $side, int $units, int $price): array
    {
        if (!self::company($company)) {
            return self::fail(404, 'There is no such matured company.');
        }
        if (!in_array($side, ['buy', 'sell'], true) || $units < 1 || $price < 1) {
            return self::fail(422, 'An order is buy or sell, some shares, at a price of at least 1.');
        }
        $p = self::prices($company);
        if ($price < $p['low'] || $price > $p['high']) {
            return self::fail(422, 'That price is too far from the market: trades go between ' . $p['low'] . ' and '
                . $p['high'] . ' a share now.');
        }
        $me = (int) $user->user_id;
        if ($side === 'sell' && self::held($me, $company) < $units) {
            return self::fail(422, 'You don\'t hold that many of its shares.');
        }
        return DB::transaction(function () use ($me, $company, $side, $units, $price, $p) {
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
                if (!self::charge($buyer, $n * $at, $at, $p['sells_at'])) {
                    if ($buyer !== $me) {
                        DB::table('player_share_orders')->where('id', $o->id)->delete();
                        continue;
                    }
                    break;
                }
                DB::table('users')->where('user_id', $seller)->update(['credits' => DB::raw('credits + ' . ($n * $at))]);
                self::move($seller, $buyer, $company, $n);
                DB::table('player_share_trades')->insert(['company_id' => $company, 'seller_id' => $seller,
                    'buyer_id' => $buyer, 'units' => $n, 'price' => $at, 'game_t' => World::gameNow()]);
                $left = (int) $o->units - $n;
                $left > 0 ? DB::table('player_share_orders')->where('id', $o->id)->update(['units' => $left])
                          : DB::table('player_share_orders')->where('id', $o->id)->delete();
                $traded += $n;
                $spent += $n * $at;
            }
            $id = null;
            $standing = $units - $traded;
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
                    'side' => $side, 'units' => $standing, 'price' => $price, 'created_at' => now()]);
            }
            return ['ok' => true, 'traded' => $traded, 'credits' => $spent, 'standing' => $standing, 'order' => $id]
                + ($note ? ['note' => $note] : []);
        });
    }

    public static function cancel(User $user, int $order): bool
    {
        return DB::table('player_share_orders')->where('id', $order)->where('user_id', $user->user_id)->delete() > 0;
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
            ->limit(20)->get(['o.id', 'o.units', 'o.price', 'u.login as by'])
            ->map(fn ($o) => ['id' => (int) $o->id, 'units' => (int) $o->units, 'price' => (int) $o->price, 'by' => $o->by])->all();
        return ['sell' => $rows('sell', 'asc'), 'buy' => $rows('buy', 'desc')];
    }

    /** An account's shares, orders and payments due from the bank */
    public static function of(User $user): array
    {
        $holdings = DB::table('player_shares as s')->join('companies as c', 'c.company_id', '=', 's.company_id')
            ->where('s.user_id', $user->user_id)->orderBy('c.name')->get(['s.company_id', 's.units', 'c.name'])
            ->map(function ($h) {
                $p = self::prices((int) $h->company_id);
                return ['company_id' => (int) $h->company_id, 'name' => $h->name, 'units' => (int) $h->units,
                    'cash_out' => $p['buys_at'], 'value' => (int) $h->units * $p['buys_at']];
            })->all();
        $orders = DB::table('player_share_orders as o')->join('companies as c', 'c.company_id', '=', 'o.company_id')
            ->where('o.user_id', $user->user_id)->orderBy('o.id')->get(['o.id', 'o.company_id', 'c.name', 'o.side', 'o.units', 'o.price'])
            ->map(fn ($o) => ['id' => (int) $o->id, 'company_id' => (int) $o->company_id, 'name' => $o->name,
                'side' => $o->side, 'units' => (int) $o->units, 'price' => (int) $o->price])->all();
        $due = DB::table('bank_payments as b')->join('companies as c', 'c.company_id', '=', 'b.company_id')
            ->where('b.user_id', $user->user_id)->whereNull('b.paid_t')->orderBy('b.due_t')
            ->get(['b.id', 'b.company_id', 'c.name', 'b.units', 'b.price_at_sale', 'b.due_t'])
            ->map(fn ($b) => ['id' => (int) $b->id, 'company_id' => (int) $b->company_id, 'name' => $b->name,
                'units' => (int) $b->units, 'price_at_sale' => (int) $b->price_at_sale, 'due' => DronePage::when((int) $b->due_t)])->all();
        $epoch = (int) DB::table('game_clock')->where('id', 1)->value('epoch');
        $today = intdiv(World::gameNow() - $epoch, 86400);
        $dividends = (int) DB::table('player_dividends')->where('user_id', $user->user_id)->where('day', '>=', $today - 30)->sum('amount');
        return ['holdings' => $holdings, 'orders' => $orders, 'bank_payments' => $due, 'dividends_30_days' => $dividends];
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
        $companies = DB::table('companies')->where('seedling', 0)->whereNotNull('owner_id')->whereNotNull('matured_t')
            ->whereNull('eliminated_at')->get(['company_id', 'matured_t', 'player_dividend_day']);
        foreach ($companies as $c) {
            $from = $c->player_dividend_day !== null ? (int) $c->player_dividend_day
                : intdiv((int) $c->matured_t - $epoch, 86400);
            $days = DB::table('company_profits')->where('company', $c->company_id)->where('day', '>', $from)
                ->orderBy('day')->get(['day', 'profit', 'carry_before']);
            foreach ($days as $d) {
                DB::transaction(function () use ($c, $d, $permille) {
                    $pool = max(0, min(0, (int) $d->carry_before) + (int) $d->profit);
                    $due = intdiv($pool * $permille, 1000);
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
                        $amount = intdiv($due * $units, self::COUNT);
                        if ($amount <= 0) {
                            continue;
                        }
                        if ($user !== null) {
                            DB::table('users')->where('user_id', $user)->update(['credits' => DB::raw('credits + ' . $amount)]);
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
     * Pays the bank's payments that are due: each at the lower of the share's
     * cash-out value on the day of the sale and now. Returns how many it paid.
     */
    public static function settleBankPayments(): int
    {
        $now = World::gameNow();
        $due = DB::table('bank_payments')->whereNull('paid_t')->where('due_t', '<=', $now)->orderBy('id')->limit(200)->get();
        foreach ($due as $b) {
            $price = min((int) $b->price_at_sale, self::cashOut((int) $b->company_id)['per_share']);
            $amount = (int) $b->units * $price;
            DB::transaction(function () use ($b, $now, $amount) {
                $done = DB::table('bank_payments')->where('id', $b->id)->whereNull('paid_t')->update(['paid_t' => $now, 'paid' => $amount]);
                if ($done) {
                    DB::table('users')->where('user_id', $b->user_id)->update(['credits' => DB::raw('credits + ' . $amount)]);
                }
            });
        }
        return $due->count();
    }
}
