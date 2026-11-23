<?php

namespace App\Console\Commands;

use App\Services\CreditCheck;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Do credits balance? (#937) Checks now, as the website's tick does once a
 * game day: the change in what every holder has, against the credits created
 * and destroyed since the last check. Exits 1 when they don't balance.
 *
 *   php artisan forge:check-credits                    the live world
 *   php artisan forge:check-credits --database=pfload  another world
 */
class ForgeCheckCredits extends Command
{
    protected $signature = 'forge:check-credits {--database= : Another world\'s database}';
    protected $description = 'Check that credits balance: what is held changed only by credits created and destroyed';

    public function handle(): int
    {
        $database = (string) $this->option('database');
        if ($database !== '') {
            config(['database.connections.world' => array_merge(config('database.connections.mysql'), ['database' => $database])]);
            DB::purge('world');
            DB::setDefaultConnection('world');
        }
        $c = CreditCheck::check();
        $this->line(sprintf('Game day %d: %s credits held', $c['day'], number_format($c['held'])));
        if ($c['difference'] === null) {
            $this->line('The first check: a baseline. Run it again later to compare.');
            return self::SUCCESS;
        }
        $this->line(sprintf('Changed by %s since the last check; %s created, %s destroyed',
            number_format($c['change']), number_format($c['created']), number_format($c['destroyed'])));
        foreach ($c['kinds'] as $kind => $amount) {
            $this->line(sprintf('  %-28s %s', $kind, number_format($amount)));
        }
        if ($c['difference'] !== 0) {
            $this->error(sprintf('Credits don\'t balance: a difference of %s', number_format($c['difference'])));
            return self::FAILURE;
        }
        $this->info('Credits balance.');
        return self::SUCCESS;
    }
}
