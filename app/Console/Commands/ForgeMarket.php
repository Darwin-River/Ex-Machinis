<?php

namespace App\Console\Commands;

use App\Services\HouseMarket;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Markets that move (#380 round 36): posts the House station drones' orders
 * at today's prices: what the website's tick does.
 *
 *   php artisan forge:market                         once, the live world
 *   php artisan forge:market --database=pfload       another world
 *   php artisan forge:market --watch=5               every 5 real seconds
 */
class ForgeMarket extends Command
{
    protected $signature = 'forge:market {--database= : Another world\'s database}
                                         {--watch=0 : Repeat every this many real seconds}';
    protected $description = 'Post the House station drones\' orders at today\'s prices';

    public function handle(): int
    {
        $database = (string) $this->option('database');
        if ($database !== '') {
            config(['database.connections.world' => array_merge(config('database.connections.mysql'), ['database' => $database])]);
            DB::purge('world');
            DB::setDefaultConnection('world');
        }
        $every = (int) $this->option('watch');
        do {
            $posted = HouseMarket::tick();
            if ($every <= 0 || $posted > 0) {
                $this->line(date('H:i:s') . " $posted order(s) posted");
            }
            if ($every > 0) {
                sleep($every);
            }
        } while ($every > 0);
        return self::SUCCESS;
    }
}
