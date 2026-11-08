<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Shares (#467, step 1 of the stock market #466). Each company starts with
 * 1,000 shares, and more once it issues new ones (#485, companies.share_count);
 * a share pays its holder its part of the company's sales income after
 * sales tax (the events engine pays them at every sale). A company starts
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
     * What a company's shares and share holdings come to (#471), for its page
     * and the API: its last trade price, who holds its shares, the standing
     * orders for them, what it holds of others, and recent trades
     */
    public static function summary(int $company): array
    {
        $names = fn (array $ids) => DB::table('companies')->whereIn('company_id', $ids)->pluck('name', 'company_id')->all();
        $prices = self::lastPrices();

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

        return [
            'price' => $prices[$company] ?? null,
            'count' => (int) (DB::table('companies')->where('company_id', $company)->value('share_count') ?? self::PER_COMPANY),
            'holders' => $holders->map(fn ($h) => ['company' => (int) $h->holder, 'name' => $name($h->holder),
                'units' => (int) $h->units, 'dividends' => (int) $h->dividends])->all(),
            'orders' => $orders->map(fn ($o) => ['company' => (int) $o->company, 'name' => $name($o->company),
                'side' => (int) $o->side === 1 ? 'sell' : 'buy', 'price' => (int) $o->price,
                ((int) $o->side === 1 ? 'keep' : 'want') => (int) $o->limit_units])->all(),
            'holdings' => $holdings->map(fn ($h) => ['company' => (int) $h->company, 'name' => $name($h->company),
                'units' => (int) $h->units, 'dividends' => (int) $h->dividends,
                'price' => $prices[(int) $h->company] ?? null,
                'value' => (int) $h->units * ($prices[(int) $h->company] ?? 0)])->all(),
            'trades' => $trades->map(fn ($t) => ['seller' => (int) $t->seller, 'seller_name' => $name($t->seller),
                'buyer' => (int) $t->buyer, 'buyer_name' => $name($t->buyer), 'units' => (int) $t->units,
                'price' => (int) $t->price, 'game_t' => (int) $t->game_t])->all(),
        ];
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
        $prices = self::lastPrices();
        $sales = $ids ? DB::table('trades AS t')->join('agents AS a', 'a.agent_id', '=', 't.seller')
            ->where('t.game_t', '>', $now - 86400)->whereIn('a.company_id', $ids)->groupBy('a.company_id')
            ->get(['a.company_id', DB::raw('SUM(t.credits - t.tax) AS net')])->pluck('net', 'company_id')->all() : [];
        $outside = $ids ? DB::table('shares')->whereIn('company', $ids)->whereColumn('holder', '<>', 'company')
            ->groupBy('company')->get(['company', DB::raw('SUM(units) AS units'), DB::raw('SUM(dividends) AS dividends')])
            ->keyBy('company')->all() : [];

        $held = fn ($o) => (int) (DB::table('shares')->where(['company' => $o->share_company, 'holder' => $o->company])->value('units') ?? 0);
        $orders = DB::table('share_orders AS o')->join('companies AS c', 'c.company_id', '=', 'o.company')
            ->whereNull('c.eliminated_at')->orderBy('o.side')->orderBy('o.price')
            ->get(['o.company', 'o.share_company', 'o.side', 'o.price', 'o.limit_units']);
        $trades = DB::table('share_trades')->orderByDesc('id')->limit($tradeLimit)
            ->get(['share_company', 'seller', 'buyer', 'units', 'price', 'game_t']);

        $names = DB::table('companies')->whereIn('company_id', array_merge($ids, $orders->pluck('company')->all(),
            $trades->pluck('seller')->all(), $trades->pluck('buyer')->all()))->pluck('name', 'company_id')->all();
        $name = fn ($id) => $names[$id] ?? "Company $id";
        // A share earns its part of the company's sales after tax (#485)
        $counts = $ids ? DB::table('companies')->whereIn('company_id', $ids)->pluck('share_count', 'company_id')->all() : [];
        $count = fn ($id) => (int) ($counts[$id] ?? self::PER_COMPANY);
        $aDay = fn ($id) => max(0, (int) ($sales[$id] ?? 0)) / $count($id);
        $days = fn ($price, $id) => $aDay($id) > 0 ? (int) ceil($price / $aDay($id)) : null;

        $companies = [];
        foreach ($ids as $id) {
            $price = $prices[$id] ?? null;
            $companies[] = ['company' => $id, 'name' => $name($id), 'price' => $price, 'count' => $count($id),
                'sales' => max(0, (int) ($sales[$id] ?? 0)), 'a_day' => round($aDay($id), 2),
                'payback' => $price !== null ? $days($price, $id) : null,
                'held_by_others' => (int) ($outside[$id]->units ?? 0),
                'dividends_paid' => (int) ($outside[$id]->dividends ?? 0)];
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
     * What each company's holdings of other companies' shares are worth at
     * their last trade price (#471): holder => credits. Shares never traded
     * count 0; a company's own shares don't count
     */
    public static function holdingsValue(): array
    {
        $prices = self::lastPrices();
        $out = [];
        foreach (DB::table('shares')->whereColumn('holder', '<>', 'company')->where('units', '>', 0)
                     ->get(['company', 'holder', 'units']) as $row) {
            $out[(int) $row->holder] = ($out[(int) $row->holder] ?? 0) + (int) $row->units * ($prices[(int) $row->company] ?? 0);
        }
        return $out;
    }
}
