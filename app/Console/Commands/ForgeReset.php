<?php

namespace App\Console\Commands;

use App\Services\WorldReset;
use Illuminate\Console\Command;

/**
 * The reset for the new rules (#642): ends the playtests' world, keeping it
 * browsable, and opens a fresh one with a few companies the bank owns. See
 * App\Services\WorldReset, and documents/hosting.md ("Reset the world") for
 * the steps around it: a backup first, the engines stopped.
 *
 *   php artisan forge:reset                    shows what it would do
 *   php artisan forge:reset --force            does it
 *   php artisan forge:reset --force --bank=6 --delete-accounts
 */
class ForgeReset extends Command
{
    protected $signature = 'forge:reset {--force : Reset the world (without it, only show what would change)}
        {--bank=3 : Matured companies the bank starts with (they run the Miner, Refiner and Maker starters in turn)}
        {--delete-accounts : Delete the player accounts too (otherwise they are kept, with a fresh start)}';
    protected $description = 'Reset the world for the new rules, keeping the old one browsable';

    public function handle(): int
    {
        $plan = WorldReset::plan();
        $this->line("Companies to end:            {$plan['companies']}");
        $this->line("Working drones to wreck:     {$plan['drones']}");
        $this->line("Wrecks to close to plunder:  {$plan['wrecks']}");
        $this->line("Queued events to drop:       {$plan['pending_events']}");
        $this->line("Releases waiting to drop:    {$plan['queued']}");
        $this->line("Player accounts:             {$plan['accounts']} (" . ($this->option('delete-accounts') ? 'deleted' : 'kept, with a fresh start') . ')');
        $bank = max(0, (int) $this->option('bank'));
        $this->line("Bank companies to start:     $bank");
        if (!$this->option('force')) {
            $this->warn('Nothing changed. Back up first (deploy/backup.sh), stop the engines, then run it again with --force.');
            return self::SUCCESS;
        }
        $done = WorldReset::run($bank, (bool) $this->option('delete-accounts'));
        foreach ($done['bank_companies'] as $id => $name) {
            $this->line("Bank company $id: $name");
        }
        $this->info('The world is reset. Start the engines again.');
        return self::SUCCESS;
    }
}
