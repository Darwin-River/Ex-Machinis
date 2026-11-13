<?php

namespace App\Console\Commands;

use App\Services\CodeLineage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Code lineage (#616): works out every release's and push's parents now,
 * instead of a few on each website tick.
 *
 *   php artisan forge:lineage                     the live world, to the end
 *   php artisan forge:lineage --rebuild           forget it all and start again
 *   php artisan forge:lineage --database=pfload   another world
 */
class ForgeLineage extends Command
{
    protected $signature = 'forge:lineage {--database= : Another world\'s database}
                                          {--rebuild : Forget the stored lineage and work it all out again}';
    protected $description = 'Work out every code revision\'s parents (code lineage)';

    public function handle(): int
    {
        $database = (string) $this->option('database');
        if ($database !== '') {
            config(['database.connections.world' => array_merge(config('database.connections.mysql'), ['database' => $database])]);
            DB::purge('world');
            DB::setDefaultConnection('world');
        }
        if ($this->option('rebuild')) {
            DB::table('code_parents')->delete();
            DB::table('code_genes')->delete();
            DB::table('code_revisions')->delete();
        }
        $start = microtime(true);
        $total = 0;
        while (($done = CodeLineage::catchUp(200)) > 0) {
            $total += $done;
        }
        $this->line(sprintf('%d revision(s) worked on in %.1f s; %d in all, %d with several parents',
            $total, microtime(true) - $start, DB::table('code_revisions')->count(),
            DB::table('code_parents')->select('revision_id')->groupBy('revision_id')->havingRaw('COUNT(*) > 1')->get()->count()));
        return self::SUCCESS;
    }
}
