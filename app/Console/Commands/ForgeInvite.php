<?php

namespace App\Console\Commands;

use App\Invite;
use Illuminate\Console\Command;

/**
 * Creates invitation codes for an invite-only world:
 *   php artisan forge:invite 5 --note="first testers"
 */
class ForgeInvite extends Command
{
    protected $signature = 'forge:invite {count=1 : How many codes} {--note= : Who they are for}';
    protected $description = 'Create invitation codes for opening accounts';

    public function handle(): int
    {
        $count = max(1, min(100, (int) $this->argument('count')));
        for ($i = 0; $i < $count; $i++) {
            $this->line(Invite::generate($this->option('note'))->code);
        }
        return self::SUCCESS;
    }
}
