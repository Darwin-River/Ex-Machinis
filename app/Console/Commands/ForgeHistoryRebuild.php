<?php

namespace App\Console\Commands;

use App\Services\HistoryBudget;
use Illuminate\Console\Command;

/**
 * Hands the free space the history tables hold back to the disk (#827).
 * InnoDB keeps a deleted row's space for that table's new rows; after the
 * archiver catches up with a backlog (#818), `events` can hold gigabytes no
 * other table can use. This rebuilds the tables holding at least a gigabyte
 * free and more free than their rows take (OPTIMIZE TABLE, online: the game
 * keeps running; it needs free disk about the size of the rows it copies).
 * Since #944 `actions` is one of them: the keeper deletes its finished
 * actions after 30 game days, and the space they took stays in its file.
 *
 *   php artisan forge:history-rebuild            rebuild the tables worth it
 *   php artisan forge:history-rebuild --dry-run  only say which and how big
 *   php artisan forge:history-rebuild --table=events   that table, whatever its free space
 *   php artisan forge:history-rebuild --table=actions  `actions`, once its backlog is pruned (#944)
 */
class ForgeHistoryRebuild extends Command
{
    protected $signature = 'forge:history-rebuild {--dry-run : Only list the tables and their sizes}
        {--table=* : Rebuild these history tables, worth it or not}';
    protected $description = 'Hand the free space the history tables hold back to the disk';

    public function handle(): int
    {
        $tables = HistoryBudget::tables();
        $names = $this->option('table') ?: array_keys(HistoryBudget::rebuildable($tables));
        $this->table(['Table', 'Rows (GB)', 'Free (GB)', 'Rebuild'], array_map(fn ($n) => [$n,
            round(($tables[$n]['rows'] ?? 0) / 1e9, 2), round(($tables[$n]['free'] ?? 0) / 1e9, 2),
            in_array($n, $names, true) ? 'yes' : 'no'], array_keys($tables)));
        if (!$names) {
            $this->info('No history table holds more free space than rows: nothing to rebuild.');
            return 0;
        }
        if ($this->option('dry-run')) {
            return 0;
        }
        foreach (HistoryBudget::rebuild($names) as $name => $r) {
            $this->info("Rebuilt $name: " . round(max(0, $r['before'] - $r['after']) / 1e9, 2) . ' GB handed back to the disk.');
        }
        return 0;
    }
}
