<?php

namespace App\Console\Commands;

use App\Services\FarMap;
use Illuminate\Console\Command;

/**
 * Resources by place (#922): puts this season's homes of the far minerals in
 * place (abundancies and deposits), starting the first season if there is
 * none. Run at start-up, after the game data is loaded, which leaves them out.
 *
 *   php artisan forge:far-map
 */
class ForgeFarMap extends Command
{
    protected $signature = 'forge:far-map';
    protected $description = 'Put this season\'s far mineral homes in place (#922)';

    public function handle(): int
    {
        $season = FarMap::apply();
        if (!$season) {
            $this->line('No far minerals in the game data: nothing to do.');
            return self::SUCCESS;
        }
        $this->line('Season ' . $season->season . ' (' . $season->starts_at . ' to ' . $season->ends_at . ')');
        foreach (FarMap::seasons()['current']['homes'] as $m) {
            $this->line('  ' . $m['name'] . ': ' . implode(', ', array_column($m['places'], 'name')));
        }
        return self::SUCCESS;
    }
}
