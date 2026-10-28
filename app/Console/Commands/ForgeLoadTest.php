<?php

namespace App\Console\Commands;

use App\Services\Release;
use App\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The load generator (#324 step 20): releases many drones running the
 * starter scripts, the way players do, then reports how the engines keep up.
 * Never point it at the live world.
 *
 *   php artisan forge:load-test 1000 --database=pfload      release 1,000
 *   php artisan forge:load-test --report --database=pfload  how it's going
 */
class ForgeLoadTest extends Command
{
    protected $signature = 'forge:load-test {drones=0 : How many drones to release}
        {--database= : The database to load (required: not the live one)}
        {--starters=miner,refiner,trader : Starters, released in turn}
        {--report : Report on the load instead of releasing}';
    protected $description = 'Release many starter drones and measure how the engines keep up';

    public const DOMAIN = 'load.test';

    public function handle(): int
    {
        $database = (string) $this->option('database');
        if ($database === '' || $database === config('database.connections.mysql.database')) {
            $this->error('Name a database to load with --database, other than the live one.');
            return self::FAILURE;
        }
        config(['database.connections.load' => array_merge(config('database.connections.mysql'), ['database' => $database])]);
        DB::purge('load');
        DB::setDefaultConnection('load');

        if ($this->option('report')) {
            foreach (self::report() as $label => $value) {
                $this->line(str_pad($label, 34) . $value);
            }
            return self::SUCCESS;
        }

        $starters = array_values(array_filter(explode(',', (string) $this->option('starters'))));
        $sources = [];
        foreach ($starters as $name) {
            if (($sources[$name] = Release::starter($name)) === null) {
                $this->error("No starter called $name");
                return self::FAILURE;
            }
        }

        $count = max(0, (int) $this->argument('drones'));
        $first = (int) DB::table('users')->where('email', 'like', '%@' . self::DOMAIN)->count();
        $started = microtime(true);
        for ($i = $first; $i < $first + $count; $i++) {
            // In blocks of three: the starters pick their site by drone number
            // (mod 3), so taking turns one by one would send every copy of a
            // starter to the same site
            $name = $starters[intdiv($i, 3) % count($starters)];
            $user = User::create(['name' => "Load $i", 'email' => "load$i@" . self::DOMAIN,
                'password' => bcrypt('load-test-' . $i, ['rounds' => 4])]);
            Release::release($user, "Load $i " . ucfirst($name), $sources[$name], null, null, null,
                Release::STARTER_KITS[$name] ?? null);
        }
        $this->info(sprintf('Released %d drones in %.1f s (%d load drones in all).', $count,
            microtime(true) - $started, $first + $count));
        return self::SUCCESS;
    }

    /** How the engines are keeping up with the load drones, now */
    public static function report(): array
    {
        $clock = DB::table('game_clock')->where('id', 1)->first();
        $now = $clock ? $clock->game_anchor + (time() - $clock->real_anchor) * $clock->scale : time();
        $scale = $clock ? (float) $clock->scale : 1.0;
        $load = fn ($q) => $q->whereIn('agent_id', DB::table('agents as a')->join('users as u', 'u.user_id', '=', 'a.user_id')
            ->where('u.email', 'like', '%@' . self::DOMAIN)->select('a.agent_id'));

        $drones = $load(DB::table('agents'));
        $due = $load(DB::table('commands'))->whereNotNull('deliver_at')->where('deliver_at', '<=', $now);
        $oldest = (clone $due)->min('deliver_at');
        $lag = $oldest === null ? 0 : $now - $oldest;
        return [
            'Game clock (game s per real s)' => $scale,
            'Load drones' => (clone $drones)->count(),
            '  working' => (clone $drones)->whereNull('wrecked_at')->count(),
            '  wrecks' => (clone $drones)->whereNotNull('wrecked_at')->count(),
            '  by cause' => json_encode((clone $drones)->whereNotNull('wrecked_at')->groupBy('wreck_cause')
                ->select('wreck_cause', DB::raw('count(*) n'))->pluck('n', 'wreck_cause')),
            'Instructions run' => number_format((int) (clone $drones)->sum('instructions')),
            'Commands due now' => (clone $due)->count(),
            'Oldest due (game s / real s late)' => sprintf('%d / %.0f', $lag, $lag / max($scale, 1e-9)),
            'Events done / waiting' => DB::table('events')->where('outcome', '<>', 0)->count() . ' / '
                . DB::table('events')->where('outcome', 0)->where('game_t', '<=', $now)->count(),
            'Companies trading / ended' => DB::table('companies')->whereNull('eliminated_at')->count() . ' / '
                . DB::table('companies')->whereNotNull('eliminated_at')->count(),
            'Credits (load companies)' => number_format((int) DB::table('companies as c')->join('users as u', 'u.user_id', '=', 'c.user_id')
                ->where('u.email', 'like', '%@' . self::DOMAIN)->whereNull('c.eliminated_at')->sum('c.credits')),
        ];
    }
}
