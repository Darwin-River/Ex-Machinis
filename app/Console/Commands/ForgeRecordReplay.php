<?php

namespace App\Console\Commands;

use App\Services\GuestDrive;
use App\Services\Replay;
use Illuminate\Console\Command;

/**
 * Records the first drone's fixed replay again (#1042, #1050), shown on /try: a starter's guest test
 * drive against the world as it is. The live server records one by itself
 * while there is none; this replaces it on purpose.
 *
 *   php artisan forge:record-replay simple_miner
 */
class ForgeRecordReplay extends Command
{
    protected $signature = 'forge:record-replay {starter=simple_miner : The starter to record}';
    protected $description = 'Record the replay of a starter drone shown on /try';

    public function handle(): int
    {
        $starter = (string) $this->argument('starter');
        if (!array_key_exists($starter, GuestDrive::starters())) {
            $this->error('No such starter: ' . $starter . ' (one of ' . implode(', ', array_keys(GuestDrive::starters())) . ')');
            return 1;
        }
        $replay = Replay::record($starter);
        if ($replay === null) {
            $this->error('The drive showed the drone doing nothing worth replaying (no trip, mining or sale), or it failed: '
                . 'the replay was not changed.');
            return 1;
        }
        $this->info('Recorded ' . count($replay['lines']) . ' actions to ' . Replay::path($starter));
        return 0;
    }
}
