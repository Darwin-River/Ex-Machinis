<?php

namespace App\Console\Commands;

use App\Services\HouseStations;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * House station drones (#380): makes the House's company and a station drone
 * at each exchange, where missing. Safe to run on every start.
 *
 *   php artisan forge:stations                     the live world
 *   php artisan forge:stations --database=pfload   another world
 */
class ForgeStations extends Command
{
    protected $signature = 'forge:stations {--database= : Another world\'s database}';
    protected $description = 'Make the House station drones that are missing';

    public function handle(): int
    {
        $database = (string) $this->option('database');
        if ($database !== '') {
            config(['database.connections.world' => array_merge(config('database.connections.mysql'), ['database' => $database])]);
            DB::purge('world');
            DB::setDefaultConnection('world');
        }
        $made = HouseStations::ensure();
        foreach ($made as $name => $id) {
            $this->line("$name: station drone $id");
        }
        $this->info(count($made) . ' station drone(s) made.');
        return self::SUCCESS;
    }
}
