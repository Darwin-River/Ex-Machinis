<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Shares (#467, step 1 of the stock market #466). Each company has 1,000
 * shares; each pays its holder 0.1% of the company's sales income after
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
