<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * The test world (#324 step 7): its own database and engines, with the same
 * rules and game data as the live world and a faster clock. The Release
 * page's Test button runs a script there, so a player can see what it does
 * before releasing it for real. Nothing done there touches the live world.
 */
class TestWorld
{
    public const CONNECTION = 'testworld';

    private static function db()
    {
        return DB::connection(self::CONNECTION);
    }

    /** Whether the test world is switched on and its database answers */
    public static function available(): bool
    {
        if (!config('forge.test_world')) {
            return false;
        }
        try {
            self::db()->getPdo();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Starts a test run of the script (#367 step 3: anyone may, no account
     * needed); returns its token, the link to its results
     */
    public static function run(string $script): string
    {
        $owner = Release::owner(self::CONNECTION);
        self::prune(config('forge.test_runs_kept') - 1);
        $token = bin2hex(random_bytes(8));
        Release::release($owner, 'Test ' . $token, $script, self::CONNECTION);
        return $token;
    }

    /** Stops and removes the oldest test runs, keeping $keep */
    public static function prune(int $keep): void
    {
        $old = self::db()->table('companies')->where('name', 'like', 'Test %')
            ->orderBy('company_id', 'desc')->skip(max(0, $keep))->take(1000)->pluck('company_id');
        if ($old->isEmpty()) {
            return;
        }
        $drones = self::db()->table('agents')->whereIn('company_id', $old)->pluck('agent_id');
        self::db()->transaction(function () use ($old, $drones) {
            self::db()->table('commands')->whereIn('agent_id', $drones)->delete();
            self::db()->table('drone_messages')->whereIn('agent_id', $drones)->delete();
            self::db()->table('drone_crashes')->whereIn('drone', $drones)->delete();
            self::db()->table('agents')->whereIn('agent_id', $drones)->delete(); // events cascade
            self::db()->table('companies')->whereIn('company_id', $old)->delete();
        });
    }

    /**
     * A test run by its token: the drone with what it has printed, its latest
     * events (actions, cargo, credits) and its trades; null if there is none
     * (a wrong link, or pruned)
     */
    public static function runOf(string $token): ?object
    {
        if (!preg_match('/^[0-9a-f]{16}$/', $token)) {
            return null;
        }
        $company = self::db()->table('companies')->where('name', 'Test ' . $token)->first();
        $drone = $company ? self::db()->table('agents')->where('company_id', $company->company_id)
            ->orderBy('agent_id')->first() : null;
        if (!$drone) {
            return null;
        }
        return (object) [
            'drone' => $drone,
            'credits' => $company->credits,
            'messages' => self::db()->table('drone_messages')->where('agent_id', $drone->agent_id)
                ->orderBy('id')->get(),
            'events' => self::db()->table('events')
                ->leftJoin('resources', 'resources.id', '=', 'events.resource')
                ->leftJoin('event_types', 'event_types.id', '=', 'events.event_type')
                ->where('events.drone', $drone->agent_id)
                ->orderBy('events.id', 'desc')->limit(20)
                ->get(['events.id', 'events.event_type', 'events.action', 'events.outcome',
                    'events.new_quantity', 'events.new_credits', 'resources.name AS resource_name',
                    'event_types.name AS type_name']),
            'trades' => self::db()->table('trades')
                ->where(function ($q) use ($drone) {
                    $q->where('seller', $drone->agent_id)->orWhere('buyer', $drone->agent_id);
                })->orderBy('id', 'desc')->limit(20)->get(),
            'crashes' => self::db()->table('drone_crashes')->where('drone', $drone->agent_id)->count(),
        ];
    }
}
