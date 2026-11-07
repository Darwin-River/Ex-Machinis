<?php

namespace App\Console\Commands;

use App\Services\Release;
use App\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The load generator (#324 step 20): releases many drones running the
 * starter scripts and the example company designs, the way players do, then
 * measures how the engines keep up (#429). Never point it at the live world.
 *
 *   php artisan forge:load-test 1000 --database=pfload      release 1,000 starters
 *   php artisan forge:load-test 60 --designs=systems:6,chemist:12 --database=pfload
 *                                                           ...and 18 companies
 *   php artisan forge:load-test --report --database=pfload  how it's going
 *   php artisan forge:load-test --measure=300 --min-turns=1000 --max-lag=10
 *       --min-scale=1500 --history=storage/load-tests.jsonl --database=pfload
 *                                                           sample for 5 real minutes,
 *                                                           fail if a pass mark is missed
 */
class ForgeLoadTest extends Command
{
    protected $signature = 'forge:load-test {drones=0 : How many drones to release}
        {--database= : The database to load (required: not the live one)}
        {--starters=miner,refiner,trader : Starters, released in turn}
        {--designs= : Example company designs to release too, as name:count,... (examples/companies, factory kit)}
        {--report : Report on the load instead of releasing}
        {--measure=0 : Sample the engines for this many real seconds instead of releasing}
        {--every=30 : Seconds between samples while measuring}
        {--min-turns=0 : Pass mark: the median drone turns a minute}
        {--max-lag=0 : Pass mark: the most real seconds the engines may fall behind (0: no mark)}
        {--min-scale=0 : Pass mark: the slowest the clock may run, in game seconds a real second}
        {--label= : A name for this run in the results history}
        {--history= : Append the results to this JSON-lines file}';
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

        if ((int) $this->option('measure') > 0) {
            return $this->measure();
        }

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

        $designs = [];
        foreach (array_filter(explode(',', (string) $this->option('designs'))) as $item) {
            [$design, $n] = array_pad(explode(':', $item, 2), 2, '1');
            $file = base_path('examples/companies/' . basename($design) . '.lua');
            if (!is_file($file)) {
                $this->error("No company design called $design (examples/companies)");
                return self::FAILURE;
            }
            $designs[ucfirst($design)] = [file_get_contents($file), max(0, (int) $n)];
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
        // The example designs, as players release them, under the built-in owner
        foreach ($designs as $design => [$script, $n]) {
            $owner = Release::owner();
            $have = (int) DB::table('companies')->where('name', 'like', "$design %")->count();
            // The kit its header asks for (#473: the listed miner mines);
            // the factory kit otherwise
            $kit = preg_match('/Release it with the Mining Kit/', $script) ? 'mining' : 'factory';
            for ($i = 1; $i <= $n; $i++) {
                Release::release($owner, "$design " . ($have + $i), $script, null, null, null, $kit);
            }
            $this->info("Released $n $design.");
        }
        return self::SUCCESS;
    }

    /**
     * Samples the engines for --measure real seconds (#429): drone turns a
     * minute, how far they're behind, the clock's pace and the game time
     * that passed, and the statements MySQL spent longest on. Checks the
     * pass marks and appends the results to --history.
     */
    private function measure(): int
    {
        $seconds = (int) $this->option('measure');
        $every = max(5, (int) $this->option('every'));
        $schema = DB::connection()->getDatabaseName();
        $digests = true;
        try {
            DB::statement('TRUNCATE performance_schema.events_statements_summary_by_digest');
        } catch (\Throwable $e) {
            $digests = false;     // no access: measure without the statements
        }
        $start = self::gameNow();
        $began = microtime(true);
        $samples = [];
        while (microtime(true) - $began < $seconds) {
            sleep(min($every, max(1, (int) ceil($seconds - (microtime(true) - $began)))));
            $clock = DB::table('game_clock')->where('id', 1)->first();
            $samples[] = ['t' => (int) round(microtime(true) - $began), 'scale' => (float) $clock->scale,
                'turns' => $clock->slices_per_minute !== null ? (int) $clock->slices_per_minute : null,
                'lag' => $clock->lag_seconds !== null ? (float) $clock->lag_seconds : null];
            $last = end($samples);
            $this->line(sprintf('%4ds  clock %6.0fx  turns/min %5s  behind %5.1f s', $last['t'], $last['scale'],
                $last['turns'] ?? '-', $last['lag'] ?? 0));
        }
        $real = microtime(true) - $began;
        $turns = array_values(array_filter(array_column($samples, 'turns'), fn ($t) => $t !== null));
        sort($turns);
        $result = [
            'label' => (string) $this->option('label'),
            'at' => date('c'),
            'database' => $schema,
            'drones' => (int) DB::table('agents')->whereNull('wrecked_at')->whereNull('station_id')->count(),
            'events' => (int) DB::table('events')->count(),
            'game_day' => round((self::gameNow() - (float) DB::table('game_clock')->where('id', 1)->value('epoch')) / 86400, 1),
            'real_seconds' => (int) round($real),
            'game_hours' => round((self::gameNow() - $start) / 3600, 1),
            'turns_per_minute' => $turns ? $turns[intdiv(count($turns), 2)] : null,
            'max_lag' => max(array_map(fn ($s) => $s['lag'] ?? 0, $samples ?: [['lag' => 0]])),
            'min_scale' => min(array_column($samples, 'scale') ?: [0]),
            'top_statements' => $digests ? self::topStatements($schema) : [],
        ];
        $failed = [];
        if ((int) $this->option('min-turns') > 0 && ($result['turns_per_minute'] ?? 0) < (int) $this->option('min-turns')) {
            $failed[] = "turns a minute {$result['turns_per_minute']} < {$this->option('min-turns')}";
        }
        if ((float) $this->option('max-lag') > 0 && $result['max_lag'] > (float) $this->option('max-lag')) {
            $failed[] = "behind {$result['max_lag']} s > {$this->option('max-lag')} s";
        }
        if ((float) $this->option('min-scale') > 0 && $result['min_scale'] < (float) $this->option('min-scale')) {
            $failed[] = sprintf('clock %.0fx < %sx', $result['min_scale'], $this->option('min-scale'));
        }
        $result['passed'] = !$failed;
        $result['failed'] = $failed;
        if ($history = (string) $this->option('history')) {
            file_put_contents($history, json_encode($result) . "\n", FILE_APPEND);
        }
        $this->line('');
        $this->line(sprintf('%d drones, %d events, game day %.1f: %.1f game hours in %d real s, median %s turns/min, '
            . 'up to %.1f s behind, clock down to %.0fx', $result['drones'], $result['events'], $result['game_day'],
            $result['game_hours'], $result['real_seconds'], $result['turns_per_minute'] ?? '-', $result['max_lag'],
            $result['min_scale']));
        foreach ($result['top_statements'] as $st) {
            $this->line(sprintf('  %7d calls %7.1f s %6.1f ms  %s', $st['calls'], $st['seconds'], $st['avg_ms'], $st['statement']));
        }
        if ($failed) {
            $this->error('Missed: ' . implode('; ', $failed));
            return self::FAILURE;
        }
        $this->info('Passed.');
        return self::SUCCESS;
    }

    /** The game clock now, in game seconds */
    private static function gameNow(): float
    {
        $clock = DB::table('game_clock')->where('id', 1)->first();
        return $clock ? $clock->game_anchor + (time() - $clock->real_anchor) * $clock->scale : time();
    }

    /** The statements MySQL spent longest on in this database since measuring began */
    private static function topStatements(string $schema, int $limit = 6): array
    {
        return array_map(fn ($r) => ['calls' => (int) $r->calls, 'seconds' => round((float) $r->seconds, 1),
            'avg_ms' => round((float) $r->avg_ms, 1), 'statement' => mb_substr((string) $r->statement, 0, 160)],
            DB::select('SELECT count_star AS calls, sum_timer_wait / 1e12 AS seconds, avg_timer_wait / 1e9 AS avg_ms, '
                . 'digest_text AS statement FROM performance_schema.events_statements_summary_by_digest '
                . 'WHERE schema_name = ? ORDER BY sum_timer_wait DESC LIMIT ' . $limit, [$schema]));
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
