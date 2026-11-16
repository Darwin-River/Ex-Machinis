<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * A figure that is slow to work out and fine a minute or two old (#723,
 * #726): a page view gets the last one worked out, and when that is older
 * than its TTL, one rebuild starts after the response has gone (defer),
 * never two at once (a lock). Only the very first view, with nothing kept
 * yet, waits for one. The dashboard and the stock exchange page use it.
 */
class Kept
{
    /** The last $key worked out, starting a rebuild after the response when it is $ttl real seconds old */
    public static function get(string $key, int $ttl, callable $work): array
    {
        $kept = Cache::get($key);
        if (is_array($kept) && isset($kept['data'], $kept['at'])) {
            if (time() - (int) $kept['at'] >= $ttl) {
                defer(fn () => self::rebuild($key, $work));
            }
            return $kept['data'];
        }
        return self::rebuild($key, $work) ?? $work();
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
}
