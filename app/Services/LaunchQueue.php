<?php

namespace App\Services;

use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The clock floor and the launch queue (#324 step 20). The game engine slows
 * the game clock when the engines can't keep up, down to a floor; behind at
 * the floor it marks the world full (game_clock.is_full), and new releases
 * wait here, then launch in order once there is room.
 */
class LaunchQueue
{
    /** A load check older than this (real seconds) is stale: the engine isn't running */
    public const STALE_SECONDS = 300;

    /** Releases from the queue at most this many at a time, so the world fills gently */
    public const DRAIN_BATCH = 5;

    /** The game clock as players see it, or null before there is one */
    public static function clock(): ?array
    {
        if (!Schema::hasColumn('game_clock', 'is_full')) {
            return null;
        }
        $clock = DB::table('game_clock')->where('id', 1)->first();
        if (!$clock) {
            return null;
        }
        $fresh = $clock->load_checked_at !== null && time() - (int) $clock->load_checked_at <= self::STALE_SECONDS;
        return [
            'scale' => (float) $clock->scale,                    // game seconds per real second
            'target' => $clock->target_scale !== null ? (float) $clock->target_scale : (float) $clock->scale,
            'floor' => $clock->floor_scale !== null ? (float) $clock->floor_scale : null,
            'lag' => $fresh && $clock->lag_seconds !== null ? (float) $clock->lag_seconds : null,
            'slices' => $fresh ? $clock->slices_per_minute : null,
            'full' => $fresh && (int) $clock->is_full === 1,
            'waiting' => self::waiting(),
        ];
    }

    /** Whether new releases must wait: the world is full (by a recent check) */
    public static function full(): bool
    {
        $clock = self::clock();
        return $clock !== null && $clock['full'];
    }

    /** How many releases are waiting */
    public static function waiting(): int
    {
        return Schema::hasTable('launch_queue') ? DB::table('launch_queue')->whereNull('released_at')->count() : 0;
    }

    /** Puts a release in the queue; returns its place in line (1 = next) */
    public static function enqueue(User $user, string $companyName, string $script, ?int $copiedFrom, ?string $copiedWord,
                                   ?string $kit = null): int
    {
        $id = DB::table('launch_queue')->insertGetId([
            'user_id' => $user->user_id,
            'company_name' => $companyName,
            'script' => $script,
            'copied_from' => $copiedFrom,
            'copied_word' => $copiedWord,
            'kit' => $kit,
            'queued_at' => now(),
        ]);
        return self::place($id);
    }

    /** A waiting release's place in line (1 = next), or 0 once it has launched */
    public static function place(int $id): int
    {
        $row = DB::table('launch_queue')->where('id', $id)->first();
        if (!$row || $row->released_at !== null) {
            return 0;
        }
        return DB::table('launch_queue')->whereNull('released_at')->where('id', '<=', $id)->count();
    }

    /** A player's waiting releases, with their places in line */
    public static function of(User $user)
    {
        return DB::table('launch_queue')->where('user_id', $user->user_id)->whereNull('released_at')
            ->orderBy('id')->get()->map(function ($row) {
                $row->place = self::place($row->id);
                return $row;
            });
    }

    /** Whether a company name is taken by a waiting release */
    public static function nameWaiting(string $name): bool
    {
        return Schema::hasTable('launch_queue')
            && DB::table('launch_queue')->whereNull('released_at')->where('company_name', $name)->exists();
    }

    /**
     * Launches waiting releases, oldest first, while the world isn't full: at
     * most DRAIN_BATCH a call (the scheduler calls it every few seconds).
     * Returns how many launched.
     */
    public static function drain(): int
    {
        if (!Schema::hasTable('launch_queue') || self::full()) {
            return 0;
        }
        $launched = 0;
        $rows = DB::table('launch_queue')->whereNull('released_at')->orderBy('id')->limit(self::DRAIN_BATCH)->get();
        foreach ($rows as $row) {
            // Claim it first, so two drains never launch it twice
            $claimed = DB::table('launch_queue')->where('id', $row->id)->whereNull('released_at')
                ->update(['released_at' => now()]);
            if (!$claimed) {
                continue;
            }
            $user = User::find($row->user_id);
            if (!$user) {
                continue;
            }
            $agent = Release::release($user, $row->company_name, $row->script, null, $row->copied_from, $row->copied_word,
                $row->kit ?? null);
            DB::table('launch_queue')->where('id', $row->id)->update(['agent_id' => $agent->agent_id]);
            $launched++;
        }
        return $launched;
    }
}
