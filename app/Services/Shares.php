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
}
