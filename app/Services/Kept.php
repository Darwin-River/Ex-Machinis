<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * A figure that is slow to work out and fine a minute or two old (#723,
 * #726): a page view gets the last one worked out, and never waits for a
 * rebuild, except the very first view, with nothing kept yet. The scheduler
 * rebuilds each one once it is its TTL old (keepAll, at /keep, #738), never
 * two at once (a lock). The dashboard and the stock exchange page use it.
 *
 * Until #738 a view started the rebuild itself, after the response
 * (defer()): but this app's HTTP kernel never runs deferred callbacks, so
 * every kept figure froze at its first build, the dashboard for 480 game
 * days.
 */
class Kept
{
    /** The last $key worked out, or worked out now when none is kept */
    public static function get(string $key, int $ttl, callable $work): array
    {
        $kept = Cache::get($key);
        if (is_array($kept) && isset($kept['data'], $kept['at'])) {
            return $kept['data'];
        }
        return self::rebuild($key, $work) ?? $work();
    }

    /** When $key was last worked out (a Unix time), null when never */
    public static function at(string $key): ?int
    {
        $kept = Cache::get($key);
        return is_array($kept) && isset($kept['at']) ? (int) $kept['at'] : null;
    }

    /** Works $key out again under a lock; null when another rebuild holds it */
    public static function rebuild(string $key, callable $work): ?array
    {
        $lock = Cache::lock("$key:building", 600);
        if (!$lock->get()) {
            return null;
        }
        try {
            $data = $work();
            Cache::forever($key, ['data' => $data, 'at' => time()]);
            return $data;
        } finally {
            $lock->release();
        }
    }

    /**
     * Every kept figure the site shows: key => [TTL in real seconds, its work]
     *
     * @return array<string, array{0: int, 1: callable}>
     */
    public static function jobs(): array
    {
        return [
            'dashboard:compact' => [Dashboard::TTL, fn () => Dashboard::summaryNow()],
            'dashboard:full' => [Dashboard::TTL, fn () => Dashboard::buildNow()],
            \App\Http\Controllers\WorldController::sharesKey() => [\App\Http\Controllers\WorldController::SHARES_TTL,
                fn () => \App\Http\Controllers\WorldController::sharesWork()],
            Explorer::KEY => [Explorer::KEEP, fn () => Explorer::work()],
        ];
    }

    /**
     * Rebuilds each kept figure that is its TTL old or more (the scheduler,
     * once a real minute): key => real seconds its rebuild took, or
     * "fresh" / "busy" (another rebuild holds its lock)
     */
    public static function keepAll(): array
    {
        $done = [];
        foreach (self::jobs() as $key => [$ttl, $work]) {
            $at = self::at($key);
            if ($at !== null && time() - $at < $ttl) {
                $done[$key] = 'fresh';
                continue;
            }
            $start = microtime(true);
            $done[$key] = self::rebuild($key, $work) === null ? 'busy' : round(microtime(true) - $start, 1);
        }
        return $done;
    }
}
