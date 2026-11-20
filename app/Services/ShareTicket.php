<?php

namespace App\Services;

use App\User;
use Illuminate\Support\Facades\DB;

/**
 * The share order ticket's Buy now and Sell now (#845). Round 73's players
 * had three pages and five forms, each with its own price, and had to work
 * out themselves which of players' orders, an offering and the bank was
 * cheapest; only scripts traded. quote() finds the best route for N shares
 * now, in price order across those, and says it in words; now() trades it
 * in one transaction, all of it or none.
 */
class ShareTicket
{
    /** Routes, in the order they go first at the same price */
    private const ORDER = ['player' => 0, 'offering' => 1, 'bank' => 2];

    /**
     * The best way to buy or sell $units of a company's shares now:
     * ['ok', 'side', 'units', 'filled', 'short', 'legs' => [{route, by,
     * units, price, credits}], 'total', 'worst', 'after' => {held, count,
     * percent}, 'payback_days', 'can_pay', 'text']. $user may
     * be null (a visitor sees the quote; trading needs an account). $limit:
     * leave out any route dearer than it (a buy) or cheaper (a sell).
     * $playersOnly: only players' orders (what an order at a price meets at
     * once, for the ticket's Name my price). 'max_units': the most you could
     * buy now with your credits, or the shares you hold to sell
     */
    public static function quote(?User $user, int $company, string $side, int $units, ?int $limit = null, bool $playersOnly = false): array
    {
        $c = PlayerShares::company($company);
        if (!$c) {
            return self::fail(404, PlayerShares::endedNote($company) ?? 'There is no such matured company.');
        }
        if (!in_array($side, ['buy', 'sell'], true) || $units < 1) {
            return self::fail(422, 'Buy or sell at least 1 whole share.');
        }
        $me = $user ? (int) $user->user_id : null;
        $p = PlayerShares::prices($company);
        $held = $me ? PlayerShares::held($me, $company) : 0;
        if ($side === 'sell' && $me && $held < $units) {
            return self::fail(422, $held ? 'You hold ' . $held . ' of its shares.' : 'You hold none of its shares.');
        }
        $routes = $side === 'buy' ? self::buyRoutes($me, $company, $p) : self::sellRoutes($me, $company, $p);
        if ($limit !== null) {
            $routes = array_values(array_filter($routes, fn ($r) => $side === 'buy' ? $r['price'] <= $limit : $r['price'] >= $limit));
        }
        if ($playersOnly) {
            $routes = array_values(array_filter($routes, fn ($r) => $r['route'] === 'player'));
        }
        $legs = [];
        $left = $units;
        foreach ($routes as $r) {
            if ($left <= 0) {
                break;
            }
            $n = min($left, $r['units']);
            $last = count($legs) - 1;
            if ($last >= 0 && $legs[$last]['route'] === $r['route'] && $legs[$last]['by'] === $r['by'] && $legs[$last]['price'] === $r['price']) {
                $legs[$last]['units'] += $n;
            } else {
                $legs[] = ['route' => $r['route'], 'by' => $r['by'], 'units' => $n, 'price' => $r['price']];
            }
            $left -= $n;
        }
        foreach ($legs as &$l) {
            $l['credits'] = $l['units'] * $l['price'];
        }
        unset($l);
        $filled = $units - $left;
        $total = array_sum(array_column($legs, 'credits'));
        $count = PlayerShares::count($company);
        $after = $side === 'buy' ? $held + $filled : $held - $filled;
        $div = PlayerShares::dividends30($company);
        $avg = $filled ? $total / $filled : 0;
        $out = ['ok' => true, 'side' => $side, 'units' => $units, 'filled' => $filled, 'short' => $left, 'legs' => $legs,
            'total' => $total, 'worst' => $legs ? end($legs)['price'] : null,
            'after' => ['held' => $after, 'count' => $count + ($side === 'buy' ? self::issued($legs) : 0),
                'percent' => round(100 * $after / max(1, $count + ($side === 'buy' ? self::issued($legs) : 0)), 1)],
            // Days of dividends at the last 30 days' pace that pay a share's price back
            'payback_days' => $side === 'buy' && $filled && $div > 0 ? (int) round($avg / ($div / 30)) : null,
            'can_pay' => $side === 'sell' || !$me ? null : self::canPay($me, $legs, (int) $p['sells_at']),
            'max_units' => !$me ? null : ($side === 'sell' ? $held : self::affordable($me, $routes, (int) $p['sells_at'])),
            'prices' => $p];
        $out['text'] = self::words($out, (string) $c->name);
        return $out;
    }

    /** Buys or sells what quote() finds now, in one transaction: all of it, or nothing */
    public static function now(User $user, int $company, string $side, int $units, ?int $limit = null): array
    {
        $q = self::quote($user, $company, $side, $units, $limit);
        if (!$q['ok']) {
            return $q;
        }
        if ($q['filled'] < 1) {
            return self::fail(409, 'Nothing can ' . ($side === 'buy' ? 'sell you' : 'buy') . ' its shares now'
                . ($limit !== null ? ' at that price' : '') . '.');
        }
        if ($q['can_pay'] === false) {
            return self::fail(409, 'You can\'t pay ' . number_format($q['total']) . ' credits.');
        }
        $want = $q['filled'];
        $done = [];
        try {
            DB::transaction(function () use ($user, $company, $side, $q, $want, &$done) {
                $got = 0;
                $players = array_values(array_filter($q['legs'], fn ($l) => $l['route'] === 'player'));
                if ($players) {
                    $n = array_sum(array_column($players, 'units'));
                    $at = end($players)['price'];
                    $r = PlayerShares::order($user, $company, $side, $n, $at, null, null, false, true);
                    if ($r['ok'] && $r['traded'] > 0) {
                        $done[] = ['route' => 'player', 'units' => $r['traded'], 'credits' => $r['credits']];
                        $got += $r['traded'];
                    }
                }
                // Then the offering and the bank, in the quote's price order; what
                // players didn't fill (one may have traded first) goes on to them
                $order = [];
                foreach ($q['legs'] as $l) {
                    if ($l['route'] !== 'player' && !in_array($l['route'], $order, true)) {
                        $order[] = $l['route'];
                    }
                }
                foreach (['offering', 'bank'] as $route) {
                    if (!in_array($route, $order, true) && self::canTake($route, $side, $company)) {
                        $order[] = $route;
                    }
                }
                foreach ($order as $route) {
                    $n = $want - $got;
                    if ($route === 'offering') {
                        $n = min($n, (int) (PlayerShares::offering($company)['left'] ?? 0));
                    } elseif ($side === 'buy') {
                        $n = min($n, (int) PlayerShares::prices($company)['bank_holds']);
                    }
                    if ($n < 1) {
                        continue;
                    }
                    $r = match (true) {
                        $route === 'offering' => PlayerShares::buyOffered($user, $company, $n),
                        $side === 'buy' => PlayerShares::buyFromBank($user, $company, $n),
                        default => PlayerShares::sellToBank($user, $company, $n),
                    };
                    if ($r['ok']) {
                        $done[] = ['route' => $route, 'units' => (int) $r['units'],
                            'credits' => (int) ($r['cost'] ?? ((int) $r['units'] * (int) ($r['price_now'] ?? 0)))]
                            + (isset($r['due']) ? ['due' => $r['due']] : []);
                        $got += (int) $r['units'];
                    }
                    if ($got >= $want) {
                        break;
                    }
                }
                if ($got < $want) {
                    throw new \RuntimeException('Only ' . $got . ' of the ' . $want . ' shares could be '
                        . ($side === 'buy' ? 'bought' : 'sold') . ' now, so nothing was traded: the market moved. Look again.');
                }
            });
        } catch (\RuntimeException $e) {
            return self::fail(409, $e->getMessage());
        }
        $credits = array_sum(array_column($done, 'credits'));
        return ['ok' => true, 'side' => $side, 'units' => $want, 'credits' => $credits, 'legs' => $done,
            'short' => $units - $want, 'quote' => $q['text']];
    }

    /** Whether a route that wasn't in the quote can still take the rest (the bank always buys) */
    private static function canTake(string $route, string $side, int $company): bool
    {
        if ($route === 'bank') {
            return $side === 'sell' || (!PlayerShares::dissolving($company) && PlayerShares::prices($company)['bank_holds'] > 0);
        }
        return $side === 'buy' && PlayerShares::offering($company) !== null;
    }

    /** Players' sell orders, an open offering and the bank's shares, cheapest first */
    private static function buyRoutes(?int $me, int $company, array $p): array
    {
        $routes = [];
        foreach (DB::table('player_share_orders AS o')->join('users AS u', 'u.user_id', '=', 'o.user_id')
                     ->where('o.company_id', $company)->where('o.side', 'sell')->whereBetween('o.price', [$p['low'], $p['high']])
                     ->when($me !== null, fn ($q) => $q->where('o.user_id', '<>', $me))
                     ->orderBy('o.price')->orderBy('o.id')->get(['o.user_id', 'o.units', 'o.price', 'u.login']) as $o) {
            if ($me !== null && !PlayerShares::sameMarket($me, (int) $o->user_id)) {
                continue;
            }
            $n = min((int) $o->units, PlayerShares::held((int) $o->user_id, $company));
            if ($n > 0) {
                $routes[] = ['route' => 'player', 'by' => $o->login, 'units' => $n, 'price' => (int) $o->price];
            }
        }
        $offer = PlayerShares::offering($company);
        if ($offer && $offer['price'] > 0) {
            $routes[] = ['route' => 'offering', 'by' => 'new shares', 'units' => $offer['left'], 'price' => (int) $offer['price']];
        }
        if ($p['bank_holds'] > 0 && !PlayerShares::dissolving($company)) {
            $routes[] = ['route' => 'bank', 'by' => 'the bank', 'units' => (int) $p['bank_holds'], 'price' => (int) $p['sells_at']];
        }
        usort($routes, fn ($a, $b) => [$a['price'], self::ORDER[$a['route']]] <=> [$b['price'], self::ORDER[$b['route']]]);
        return $routes;
    }

    /** Players' buy orders, dearest first, and the bank, which buys any number at cash-out */
    private static function sellRoutes(?int $me, int $company, array $p): array
    {
        $routes = [];
        foreach (DB::table('player_share_orders AS o')->join('users AS u', 'u.user_id', '=', 'o.user_id')
                     ->where('o.company_id', $company)->where('o.side', 'buy')->whereBetween('o.price', [$p['low'], $p['high']])
                     ->when($me !== null, fn ($q) => $q->where('o.user_id', '<>', $me))
                     ->orderByDesc('o.price')->orderBy('o.id')->get(['o.user_id', 'o.units', 'o.price', 'u.login']) as $o) {
            if ($me !== null && !PlayerShares::sameMarket($me, (int) $o->user_id)) {
                continue;
            }
            $routes[] = ['route' => 'player', 'by' => $o->login, 'units' => (int) $o->units, 'price' => (int) $o->price];
        }
        if ($p['buys_at'] > 0) {
            $routes[] = ['route' => 'bank', 'by' => 'the bank', 'units' => PHP_INT_MAX, 'price' => (int) $p['buys_at']];
        }
        usort($routes, fn ($a, $b) => [-$a['price'], self::ORDER[$a['route']]] <=> [-$b['price'], self::ORDER[$b['route']]]);
        return $routes;
    }

    /** New shares a buy's legs issue (an offering's) */
    private static function issued(array $legs): int
    {
        return array_sum(array_column(array_filter($legs, fn ($l) => $l['route'] === 'offering'), 'units'));
    }

    /** How many shares an account can pay for along the routes, cheapest first */
    private static function affordable(int $me, array $routes, int $bankSells): int
    {
        $row = DB::table('users')->where('user_id', $me)->first(['credits', 'share_credits']);
        $start = (int) ($row->share_credits ?? 0);
        $credits = (int) ($row->credits ?? 0);
        $n = 0;
        foreach ($routes as $r) {
            $price = max(1, $r['price']);
            $purse = $credits + ($price <= $bankSells ? $start : 0);
            $k = min($r['units'], intdiv($purse, $price));
            $cost = $k * $price;
            $fromStart = $price <= $bankSells ? min($start, $cost) : 0;
            $start -= $fromStart;
            $credits -= $cost - $fromStart;
            $n += $k;
            if ($k < $r['units']) {
                break;
            }
        }
        return $n;
    }

    /** Whether an account can pay for a buy's legs: starting credits pay only at or below the bank's selling price */
    private static function canPay(int $me, array $legs, int $bankSells): bool
    {
        $row = DB::table('users')->where('user_id', $me)->first(['credits', 'share_credits']);
        $start = (int) ($row->share_credits ?? 0);
        $credits = (int) ($row->credits ?? 0);
        foreach ($legs as $l) {
            $fromStart = $l['price'] <= $bankSells ? min($start, $l['credits']) : 0;
            $start -= $fromStart;
            $credits -= $l['credits'] - $fromStart;
        }
        return $credits >= 0;
    }

    /** The quote in words: "Buy 5 now: 3 from Bram at 1,120 and 2 from the bank at 1,143 = 5,646 credits" */
    private static function words(array $q, string $name): string
    {
        $n = fn ($v) => number_format($v);
        $verb = $q['side'] === 'buy' ? 'Buy' : 'Sell';
        if ($q['filled'] < 1) {
            return 'Nothing can ' . ($q['side'] === 'buy' ? 'sell you ' . $name . '\'s shares' : 'buy them') . ' now: no player order, offering or bank shares.';
        }
        $parts = array_map(fn ($l) => $l['units'] . ($q['side'] === 'buy' ? ' from ' : ' to ')
            . ($l['route'] === 'offering' ? 'its offering of new shares' : $l['by']) . ' at ' . $n($l['price']), $q['legs']);
        $list = count($parts) > 1 ? implode(', ', array_slice($parts, 0, -1)) . ' and ' . end($parts) : $parts[0];
        $t = $verb . ' ' . $q['filled'] . ' now: ' . $list . ' = ' . $n($q['total']) . ' credits.';
        if ($q['short'] > 0) {
            $t .= ' Only ' . $q['filled'] . ' of the ' . $q['units'] . ' can be ' . ($q['side'] === 'buy' ? 'bought' : 'sold') . ' now.';
        }
        if ($q['side'] === 'sell' && collect($q['legs'])->contains('route', 'bank')) {
            $t .= ' The bank pays in ' . (int) config('forge.bank_pay_days') . ' game days, at the lower of today\'s cash-out and cash-out then.';
        }
        $t .= ' You\'d hold ' . $q['after']['held'] . ' of its ' . $q['after']['count'] . ' shares (' . $q['after']['percent'] . '%).';
        if ($q['payback_days'] !== null) {
            $t .= ' At the last 30 days\' dividends, a share pays its price back in about ' . $n($q['payback_days']) . ' game days.';
        }
        if ($q['can_pay'] === false) {
            $t .= ' You can\'t pay that much.';
        }
        return $t;
    }

    private static function fail(int $status, string $error): array
    {
        return ['ok' => false, 'status' => $status, 'error' => $error];
    }
}
