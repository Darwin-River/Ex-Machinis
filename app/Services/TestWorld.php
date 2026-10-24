<?php

namespace App\Services;

use App\Agent;
use App\User;
use Illuminate\Support\Collection;
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

    /** Starts a test run of the script; returns the test drone */
    public static function run(User $user, string $script): Agent
    {
        // The player's account, as the test world's drones need an owner
        self::db()->table('users')->updateOrInsert(
            ['user_id' => $user->user_id],
            ['name' => $user->name, 'email' => $user->email]
        );

        self::prune($user, config('forge.test_runs_kept') - 1);

        $name = 'Test ' . $user->user_id . '-' . substr(md5(uniqid('', true)), 0, 6);

        return Release::release($user, $name, $script, self::CONNECTION);
    }

    /** Stops and removes the player's oldest test runs, keeping $keep */
    public static function prune(User $user, int $keep): void
    {
        $old = self::db()->table('companies')->where('user_id', $user->user_id)
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
     * The player's test runs, newest first: each drone with what it has
     * printed, its latest events (actions, cargo, credits) and its trades
     */
    public static function runs(User $user): Collection
    {
        $companies = self::db()->table('companies')->where('user_id', $user->user_id)
            ->orderBy('company_id', 'desc')->get()->keyBy('company_id');
        if ($companies->isEmpty()) {
            return collect();
        }
        $drones = self::db()->table('agents')->whereIn('company_id', $companies->keys())
            ->orderBy('agent_id', 'desc')->get();

        return $drones->map(function ($drone) use ($companies) {
            $company = $companies[$drone->company_id];
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
        });
    }
}
