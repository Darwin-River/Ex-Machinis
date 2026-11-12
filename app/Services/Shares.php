<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Shares (#467, step 1 of the stock market #466). Each company starts with
 * 1,000 shares, and more once it issues new ones (#485, companies.share_count);
 * a share pays its holder its part of the company's profit, which the
 * events engine settles once a game day (#603; until then, its sales after
 * tax, paid at every sale). A company starts
 * holding all its own (migration 0038).
 */
class Shares
{
    public const PER_COMPANY = 1000;

    /** Who holds a company's shares: holder => [units, dividends paid so far] */
    public static function holders(int $company): array
    {
        $out = [];
        foreach (DB::table('shares')->where('company', $company)->orderByDesc('units')->get() as $row) {
            $out[(int) $row->holder] = ['units' => (int) $row->units, 'dividends' => (int) $row->dividends];
        }
        return $out;
    }

    /** Moves units of a company's shares from one holder to another */
    public static function transfer(int $company, int $from, int $to, int $units): void
    {
        if ($units <= 0) {
            throw new InvalidArgumentException('Move at least one share.');
        }
        if ($from === $to) {
            throw new InvalidArgumentException('The holders are the same company.');
        }
        if (! DB::table('companies')->where('company_id', $to)->exists()) {
            throw new InvalidArgumentException("No company $to.");
        }
        DB::transaction(function () use ($company, $from, $to, $units) {
            $held = (int) DB::table('shares')->where(['company' => $company, 'holder' => $from])
                ->lockForUpdate()->value('units');
            if ($held < $units) {
                throw new InvalidArgumentException("Company $from holds $held of company $company's shares, not $units.");
            }
            DB::table('shares')->where(['company' => $company, 'holder' => $from])->decrement('units', $units);
            // A holder that sold its last share leaves the register (the
            // company itself stays, at 0)
            if ($held === $units && $from !== $company) {
                DB::table('shares')->where(['company' => $company, 'holder' => $from])->delete();
            }
            DB::statement('INSERT INTO shares (company, holder, units) VALUES (?, ?, ?) '
                . 'ON DUPLICATE KEY UPDATE units = units + VALUES(units)', [$company, $to, $units]);
        });
    }

    /** Each company's last share trade price (#471): company => price */
    public static function lastPrices(): array
    {
        $out = [];
        $latest = DB::table('share_trades')->groupBy('share_company')->select('share_company', DB::raw('MAX(id) AS id'));
        foreach (DB::table('share_trades as t')->joinSub($latest, 'l', 'l.id', '=', 't.id')
                     ->get(['t.share_company', 't.price']) as $row) {
            $out[(int) $row->share_company] = (int) $row->price;
        }
        return $out;
    }

    /**
     * The most of a company others may hold (#599): the engine's
     * SHARES_MAX_OUTSIDE_PERMILLE / 1000. Keep the two in step
     */
    public const MAX_OUTSIDE = 0.5;

    /** From this much held by others, the company page warns (#599) */
    public const WARN_OUTSIDE = 0.3;

    /** The game days of trades a share's value averages over (#513) */
    public const VALUE_DAYS = 3;

    /**
     * company => what its shares are worth a share (#513): the average price
     * of its share trades over the last VALUE_DAYS game days, weighted by
     * units, or its last trade price if none traded in that time. The last
     * trade alone could be pushed up by a couple of shares traded between a
     * player's own companies, or knocked to 1 by one panic sale (round 57),
     * and every holder's net wealth moved with it.
     * Since #573 the trades averaged also reach back, newest first, until
     * they cover at least half the shares other companies hold: round 60's
     * single trade of 109 shares at 24 tripled Lena 2's value, and (#568)
     * dropped its net wealth by 9,000 in a day. Query 692 does the same
     * (VALUE_SHARE_PRICE): keep the two in step
     */
    public static function valuePrices(): array
    {
        $out = self::lastPrices();
        $since = World::gameNow() - self::VALUE_DAYS * 86400;
        // Half the shares others hold in each company: its recent trades
        // must cover at least that many (#573)
        $half = [];
        foreach (DB::table('shares')->whereColumn('holder', '<>', 'company')->where('units', '>', 0)->groupBy('company')
                     ->select('company', DB::raw('SUM(units) AS units'))->get() as $row) {
            $half[(int) $row->company] = (int) ceil((int) $row->units / 2);
        }
        $sum = $units = [];
        // Newest first: a trade counts while it is within VALUE_DAYS, or the
        // newer ones don't yet cover half the shares others hold
        foreach (DB::table('share_trades')->where('units', '>', 0)->orderByDesc('id')
                     ->get(['share_company', 'units', 'price', 'game_t']) as $t) {
            $c = (int) $t->share_company;
            if ((int) $t->game_t <= $since && ($units[$c] ?? 0) >= ($half[$c] ?? 0)) {
                continue;
            }
            $sum[$c] = ($sum[$c] ?? 0) + (int) $t->units * (int) $t->price;
            $units[$c] = ($units[$c] ?? 0) + (int) $t->units;
        }
        foreach ($units as $c => $u) {
            $out[$c] = max(1, (int) round($sum[$c] / $u));
        }
        return $out;
    }

    /**
     * What a company's shares and share holdings come to (#471), for its page
     * and the API: its last trade price, who holds its shares, the standing
     * orders for them, what it holds of others, and recent trades
     */
    public static function summary(int $company): array
    {
        $names = fn (array $ids) => DB::table('companies')->whereIn('company_id', $ids)->pluck('name', 'company_id')->all();
        $prices = self::lastPrices();
        $values = self::valuePrices();

        $holders = DB::table('shares')->where('company', $company)->where('units', '>', 0)
            ->orderByDesc('units')->get(['holder', 'units', 'dividends']);
        $holdings = DB::table('shares')->where('holder', $company)->where('company', '<>', $company)
            ->where('units', '>', 0)->orderBy('company')->get(['company', 'units', 'dividends']);
        $orders = DB::table('share_orders')->where('share_company', $company)->orderBy('side')->orderBy('price')
            ->get(['company', 'side', 'price', 'limit_units']);
        $trades = DB::table('share_trades')->where('share_company', $company)->orderByDesc('id')->limit(10)
            ->get(['seller', 'buyer', 'units', 'price', 'game_t']);
        $who = $names(array_merge($holders->pluck('holder')->all(), $holdings->pluck('company')->all(),
            $orders->pluck('company')->all(), $trades->pluck('seller')->all(), $trades->pluck('buyer')->all()));
        $name = fn ($id) => $who[$id] ?? "Company $id";
        $ended = $holdings->isEmpty() ? [] : DB::table('companies')->whereIn('company_id', $holdings->pluck('company')->all())
            ->whereNotNull('eliminated_at')->pluck('company_id')->flip()->all();

        $count = (int) (DB::table('companies')->where('company_id', $company)->value('share_count') ?? self::PER_COMPANY);
        $outside = (int) $holders->filter(fn ($h) => (int) $h->holder !== $company)->sum('units');
        return [
            'price' => $prices[$company] ?? null,
            // #599: how much of it other companies hold; offerings and the
            // company's own sales stop at MAX_OUTSIDE
            'outside' => $outside,
            'outside_share' => $count > 0 ? round($outside / $count, 3) : 0,
            'max_outside' => self::MAX_OUTSIDE,
            // What a share counts for in net wealth (#513): the last 3 days' average
            'value_price' => $values[$company] ?? null,
            'count' => $count,
            'offering' => self::offerings($company)[0] ?? null,
            'holders' => $holders->map(fn ($h) => ['company' => (int) $h->holder, 'name' => $name($h->holder),
                'units' => (int) $h->units, 'dividends' => (int) $h->dividends])->all(),
            'orders' => $orders->map(fn ($o) => ['company' => (int) $o->company, 'name' => $name($o->company),
                'side' => (int) $o->side === 1 ? 'sell' : 'buy', 'price' => (int) $o->price,
                ((int) $o->side === 1 ? 'keep' : 'want') => (int) $o->limit_units])->all(),
            'holdings' => $holdings->map(fn ($h) => ['company' => (int) $h->company, 'name' => $name($h->company),
                'units' => (int) $h->units, 'dividends' => (int) $h->dividends,
                'price' => $prices[(int) $h->company] ?? null, 'value_price' => $values[(int) $h->company] ?? null,
                'ended' => isset($ended[(int) $h->company]),
                // An ended company's shares are worth nothing (#493); others
                // count at the 3-day average (#513)
                'value' => isset($ended[(int) $h->company]) ? 0
                    : (int) $h->units * ($values[(int) $h->company] ?? 0)])->all(),
            'trades' => $trades->map(fn ($t) => ['seller' => (int) $t->seller, 'seller_name' => $name($t->seller),
                'buyer' => (int) $t->buyer, 'buyer_name' => $name($t->buyer), 'units' => (int) $t->units,
                'price' => (int) $t->price, 'game_t' => (int) $t->game_t])->all(),
        ];
    }

    /**
     * Companies' sales after tax a day, averaged over the last 7 game days or
     * their life if younger (#492): company => credits. Sales come in lumps;
     * one day is a poor guide to what a share earns
     */
    public static function daySales(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $now = World::gameNow();
        $totals = DB::table('trades AS t')->join('agents AS a', 'a.agent_id', '=', 't.seller')
            ->where('t.game_t', '>', $now - 7 * 86400)->whereIn('a.company_id', $ids)->groupBy('a.company_id')
            ->get(['a.company_id', DB::raw('SUM(t.credits - t.tax) AS net')])->pluck('net', 'company_id')->all();
        $founded = DB::table('companies')->whereIn('company_id', $ids)->pluck('founded_t', 'company_id')->all();
        $out = [];
        foreach ($ids as $id) {
            $days = max(1, min(7, intdiv($now - (int) ($founded[$id] ?? 0), 86400)));
            $out[$id] = intdiv(max(0, (int) ($totals[$id] ?? 0)), $days);
        }
        return $out;
    }

    /**
     * Companies' profit a day (#603), what dividends are paid from: the
     * average of the game days settled in the last 7 (company_profits), 0 for
     * a company with none yet: company => credits (negative for a loss)
     */
    public static function dayProfits(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $since = MarketHistory::today() - 7;
        $rows = DB::table('company_profits')->whereIn('company', $ids)->where('day', '>=', $since)->groupBy('company')
            ->get(['company', DB::raw('FLOOR(SUM(profit) / COUNT(*)) AS profit')])->pluck('profit', 'company')->all();
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = (int) ($rows[$id] ?? 0);
        }
        return $out;
    }

    /**
     * Open offerings of new shares (#485), soonest deadline first, with what
     * is committed so far: one company's (its page) or every company's
     */
    public static function offerings(?int $company = null): array
    {
        $now = World::gameNow();
        $q = DB::table('share_offerings AS o')->join('companies AS c', 'c.company_id', '=', 'o.company')
            ->whereNull('c.eliminated_at')->where('o.deadline_t', '>', $now)->orderBy('o.deadline_t');
        if ($company !== null) {
            $q->where('o.company', $company);
        }
        $offers = $q->get(['o.company', 'c.name', 'c.share_count', 'o.units', 'o.min_units', 'o.price', 'o.deadline_t']);
        if ($offers->isEmpty()) {
            return [];
        }
        $ids = $offers->pluck('company')->all();
        $commits = DB::table('share_commitments AS m')->join('companies AS c', 'c.company_id', '=', 'm.investor')
            ->whereIn('m.company', $ids)->orderByDesc('m.units')->get(['m.company', 'm.investor', 'c.name', 'm.units'])
            ->groupBy('company');
        $sales = self::daySales($ids);
        $profits = self::dayProfits($ids);

        return $offers->map(function ($o) use ($commits, $sales, $profits, $now) {
            $in = $commits[$o->company] ?? collect();
            $committed = (int) $in->sum('units');
            // Dividends come from profit (#603)
            $net = max(0, (int) ($profits[$o->company] ?? 0));
            $after = (int) $o->share_count + (int) $o->units;
            return ['company' => (int) $o->company, 'name' => $o->name, 'count' => (int) $o->share_count,
                // The fewest it issues at its deadline (#516); NULL: all of them
                'units' => (int) $o->units, 'min' => (int) ($o->min_units ?? $o->units),
                'price' => (int) $o->price, 'raise' => (int) $o->units * (int) $o->price,
                'committed' => $committed, 'left' => (int) $o->units - $committed,
                'hours' => (int) max(0, ceil(($o->deadline_t - $now) / 3600)),
                'sales' => max(0, (int) ($sales[$o->company] ?? 0)), 'profit' => (int) ($profits[$o->company] ?? 0),
                'a_day_after' => round($net / $after, 2),
                // What it sells (#572): the part of its future profit the
                // new shares take if it fills, and what they'd be paid a day now
                'income_percent' => round(100 * (int) $o->units / $after, 1),
                'dividends_a_day' => round($net * (int) $o->units / $after, 1),
                'investors' => $in->map(fn ($m) => ['company' => (int) $m->investor, 'name' => $m->name,
                    'units' => (int) $m->units])->values()->all()];
        })->all();
    }

    /**
     * The whole stock exchange (#484), for the Stock exchange page: every
     * company whose shares have traded, are on offer or are held by others,
     * with a day's sales and what a share earns of them; the sell orders that
     * can sell now; the buy orders still buying; and the latest trades
     */
    public static function exchange(int $tradeLimit = 25): array
    {
        $now = World::gameNow();
        $ids = DB::table('share_trades')->distinct()->pluck('share_company')
            ->merge(DB::table('share_orders')->distinct()->pluck('share_company'))
            ->merge(DB::table('shares')->whereColumn('holder', '<>', 'company')->where('units', '>', 0)->distinct()->pluck('company'))
            ->map(fn ($id) => (int) $id)->unique()->values()->all();
        // A company that has ended is off the exchange: its shares are worth nothing (#493)
        $ids = $ids ? DB::table('companies')->whereIn('company_id', $ids)->whereNull('eliminated_at')
            ->pluck('company_id')->map(fn ($id) => (int) $id)->all() : [];
        $prices = self::lastPrices();
        $values = self::valuePrices();
        $sales = self::daySales($ids);
        $profits = self::dayProfits($ids);
        $outside = $ids ? DB::table('shares')->whereIn('company', $ids)->whereColumn('holder', '<>', 'company')
            ->groupBy('company')->get(['company', DB::raw('SUM(units) AS units'), DB::raw('SUM(dividends) AS dividends')])
            ->keyBy('company')->all() : [];

        $held = fn ($o) => (int) (DB::table('shares')->where(['company' => $o->share_company, 'holder' => $o->company])->value('units') ?? 0);
        $orders = DB::table('share_orders AS o')->join('companies AS c', 'c.company_id', '=', 'o.company')
            ->join('companies AS sc', 'sc.company_id', '=', 'o.share_company')
            ->whereNull('c.eliminated_at')->whereNull('sc.eliminated_at')->orderBy('o.side')->orderBy('o.price')
            ->get(['o.company', 'o.share_company', 'o.side', 'o.price', 'o.limit_units']);
        $trades = DB::table('share_trades')->orderByDesc('id')->limit($tradeLimit)
            ->get(['share_company', 'seller', 'buyer', 'units', 'price', 'game_t']);

        $names = DB::table('companies')->whereIn('company_id', array_merge($ids, $orders->pluck('company')->all(),
            $trades->pluck('seller')->all(), $trades->pluck('buyer')->all()))->pluck('name', 'company_id')->all();
        $name = fn ($id) => $names[$id] ?? "Company $id";
        // A share earns its part of the company's profit (#603; sales after tax until then, #485)
        $counts = $ids ? DB::table('companies')->whereIn('company_id', $ids)->pluck('share_count', 'company_id')->all() : [];
        $count = fn ($id) => (int) ($counts[$id] ?? self::PER_COMPANY);
        $aDay = fn ($id) => max(0, (int) ($profits[$id] ?? 0)) / $count($id);
        $days = fn ($price, $id) => $aDay($id) > 0 ? (int) ceil($price / $aDay($id)) : null;

        $companies = [];
        // Whether each can keep its drones flying (#537): about 20 ms a
        // company, so kept a minute (a game half hour at full speed)
        sort($ids);
        $health = \Illuminate\Support\Facades\Cache::remember('share-health:' . md5(implode(',', $ids)), 60,
            fn () => CompanyHealth::many($ids));
        foreach ($ids as $id) {
            $price = $prices[$id] ?? null;
            $companies[] = ['company' => $id, 'name' => $name($id), 'price' => $price,
                'value_price' => $values[$id] ?? null, 'count' => $count($id),
                'sales' => max(0, (int) ($sales[$id] ?? 0)), 'profit' => (int) ($profits[$id] ?? 0),
                'a_day' => round($aDay($id), 2),
                'payback' => $price !== null ? $days($price, $id) : null,
                'held_by_others' => (int) ($outside[$id]->units ?? 0),
                'dividends_paid' => (int) ($outside[$id]->dividends ?? 0), 'health' => $health[$id] ?? null];
        }
        usort($companies, fn ($a, $b) => [$b['sales'], $a['company']] <=> [$a['sales'], $b['company']]);

        $forSale = $buying = [];
        foreach ($orders as $o) {
            $h = $held($o);
            $row = ['company' => (int) $o->share_company, 'name' => $name((int) $o->share_company),
                'by' => (int) $o->company, 'by_name' => $name((int) $o->company), 'price' => (int) $o->price,
                'days' => $days((int) $o->price, (int) $o->share_company)];
            if ((int) $o->side === 1 && $h > (int) $o->limit_units) {
                $forSale[] = $row + ['units' => $h - (int) $o->limit_units];
            } elseif ((int) $o->side === 2 && $h < (int) $o->limit_units) {
                $buying[] = $row + ['units' => (int) $o->limit_units - $h];
            }
        }
        usort($buying, fn ($a, $b) => $b['price'] <=> $a['price']);

        return [
            'offerings' => self::offerings(),
            'companies' => $companies,
            'for_sale' => $forSale,
            'buying' => $buying,
            'trades' => $trades->map(fn ($t) => ['company' => (int) $t->share_company, 'name' => $name((int) $t->share_company),
                'seller' => (int) $t->seller, 'seller_name' => $name((int) $t->seller),
                'buyer' => (int) $t->buyer, 'buyer_name' => $name((int) $t->buyer),
                'units' => (int) $t->units, 'price' => (int) $t->price,
                'when' => DronePage::when((int) $t->game_t)])->all(),
        ];
    }

    /**
     * A company's share price by game day (#551), oldest first: day, low,
     * high, last (the day's last trade), units traded. Gus (round 58) had no
     * price history to judge a share by
     */
    public static function history(int $company): array
    {
        $epoch = (float) (DB::table('game_clock')->where('id', 1)->value('epoch') ?? 0);
        $out = [];
        foreach (DB::table('share_trades')->where('share_company', $company)->where('units', '>', 0)->orderBy('id')
                     ->get(['game_t', 'price', 'units']) as $t) {
            $day = (int) floor(((int) $t->game_t - $epoch) / 86400);
            $d = $out[$day] ?? ['day' => $day, 'low' => (int) $t->price, 'high' => (int) $t->price, 'last' => 0, 'units' => 0,
                'credits' => 0, 'trades' => 0];
            $d['low'] = min($d['low'], (int) $t->price);
            $d['high'] = max($d['high'], (int) $t->price);
            $d['last'] = (int) $t->price;
            $d['units'] += (int) $t->units;
            $d['credits'] += (int) $t->units * (int) $t->price;
            $d['trades']++;
            $out[$day] = $d;
        }
        // The day's average, weighted by shares (#600), and the company as
        // the one "place": the price chart's row format
        $name = (string) DB::table('companies')->where('company_id', $company)->value('name');
        return array_values(array_map(fn ($d) => array_diff_key($d, ['credits' => 0]) + [
            'average' => round($d['credits'] / max(1, $d['units']), 1),
            'place' => $company, 'place_name' => $name], $out));
    }

    /**
     * What each company's holdings of other companies' shares are worth at
     * their last trade price (#471): holder => credits. Shares never traded,
     * or of a company that has ended (#493), count 0; a company's own shares
     * don't count
     */
    /**
     * What the shares other companies hold in each company are worth, at the
     * same value price (#568): company => credits. Net wealth counts them
     * against the company, as the holders count them for themselves: a share
     * sold is income given away (round 59: Jonah 3 reached #1 overall on
     * credits from selling about 72% of its future income)
     */
    public static function claimsValue(): array
    {
        $prices = self::valuePrices();
        $out = [];
        foreach (DB::table('shares')->whereColumn('holder', '<>', 'company')->where('units', '>', 0)
                     ->groupBy('company')->select('company', DB::raw('SUM(units) AS units'))->get() as $row) {
            $out[(int) $row->company] = (int) $row->units * ($prices[(int) $row->company] ?? 0);
        }
        return $out;
    }

    public static function holdingsValue(): array
    {
        $prices = self::valuePrices();
        $out = [];
        // Shares of a company that has ended are worth nothing (#493)
        foreach (DB::table('shares AS s')->join('companies AS c', 'c.company_id', '=', 's.company')
                     ->whereNull('c.eliminated_at')->whereColumn('s.holder', '<>', 's.company')->where('s.units', '>', 0)
                     ->get(['s.company', 's.holder', 's.units']) as $row) {
            $out[(int) $row->holder] = ($out[(int) $row->holder] ?? 0) + (int) $row->units * ($prices[(int) $row->company] ?? 0);
        }
        return $out;
    }
}
