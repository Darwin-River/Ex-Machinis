<?php

namespace App\Console\Commands;

use App\Services\Shares;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Shares (#467): shows who holds a company's shares, or moves some by hand,
 * so dividends can be seen working before the exchange (#466 step 2).
 *
 *   php artisan forge:shares 12                        who holds company 12's shares
 *   php artisan forge:shares 12 --to=40 --units=100    company 12 gives 100 of its shares to company 40
 *   php artisan forge:shares 12 --from=40 --to=41 --units=50
 */
class ForgeShares extends Command
{
    protected $signature = 'forge:shares {company : The company whose shares}
        {--from= : The holder they come from (the company itself by default)}
        {--to= : The holder they go to}
        {--units= : How many}
        {--database= : Another world\'s database}';
    protected $description = 'Show or move a company\'s shares';

    public function handle(): int
    {
        $database = (string) $this->option('database');
        if ($database !== '') {
            config(['database.connections.world' => array_merge(config('database.connections.mysql'), ['database' => $database])]);
            DB::purge('world');
            DB::setDefaultConnection('world');
        }
        $company = (int) $this->argument('company');
        if ($this->option('to') !== null) {
            try {
                $from = $this->option('from') !== null ? (int) $this->option('from') : $company;
                Shares::transfer($company, $from, (int) $this->option('to'), (int) $this->option('units'));
            } catch (InvalidArgumentException $e) {
                $this->error($e->getMessage());
                return self::FAILURE;
            }
        }
        $rows = [];
        foreach (Shares::holders($company) as $holder => $h) {
            $name = DB::table('companies')->where('company_id', $holder)->value('name');
            $rows[] = [$holder, $name, $h['units'], $h['dividends']];
        }
        $this->table(['Holder', 'Name', 'Shares', 'Dividends'], $rows);
        return self::SUCCESS;
    }
}
