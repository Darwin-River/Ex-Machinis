<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * The fixed replay on the home page (#1042, part of #1039): a starter's guest
 * test drive (GuestDrive) against the live world, recorded once and kept, so
 * the home page shows the same run every time and never a crash.
 *
 * The live server records it by itself: while there is none, the scheduler's
 * tick tries once an hour (recordIfMissing), and keeps a drive only when it
 * shows the drone at work (travelling, mining, refining or selling, not only
 * waiting). Once kept it doesn't change; `php artisan forge:record-replay
 * miner` records it again on purpose.
 */
class Replay
{
    /** How many of a drive's actions a replay keeps */
    public const MAX_LINES = 14;

    /** The starter the home page replays */
    public const HOME = 'miner';

    public static function path(string $starter): string
    {
        return storage_path('app/replays/' . preg_replace('/[^a-z]/', '', $starter) . '.json');
    }

    /** {starter, recorded (a date), place, lines: [{at (game hours:minutes), text}]}, or null when none is recorded */
    public static function of(string $starter): ?array
    {
        $path = self::path($starter);
        if (!is_readable($path)) {
            return null;
        }
        $r = json_decode((string) file_get_contents($path), true);
        return is_array($r) && !empty($r['lines']) ? $r : null;
    }

    /**
     * A guest drive as a replay, or null when it shows nothing worth
     * replaying: it failed, or it never travelled, mined, refined or sold
     */
    public static function fromDrive(string $starter, array $drive): ?array
    {
        if ($drive['error'] !== null || $drive['stopped'] === 'unavailable' || !$drive['actions']) {
            return null;
        }
        $lines = [];
        $working = false;
        foreach ($drive['actions'] as $a) {
            if ((int) $a['result'] !== 0) {
                continue;   // a replay shows what it did, not what it tried
            }
            if (count($lines) >= self::MAX_LINES) {
                break;
            }
            $working = $working || !str_starts_with($a['text'], 'would wait');
            $t = (int) $a['at'];
            $lines[] = ['at' => sprintf('%d:%02d', intdiv($t, 3600), intdiv($t % 3600, 60)), 'text' => $a['text']];
        }
        return $lines && $working ? ['starter' => $starter, 'recorded' => now()->toDateString(), 'place' => $drive['place'], 'lines' => $lines] : null;
    }

    /** Records the starter's replay now; the replay, or null (and nothing kept) when the drive showed nothing worth it */
    public static function record(string $starter): ?array
    {
        $script = Release::starter($starter);
        if (!$script || !array_key_exists($starter, GuestDrive::starters())) {
            return null;
        }
        $replay = self::fromDrive($starter, GuestDrive::run($script));
        if ($replay !== null) {
            @mkdir(dirname(self::path($starter)), 0775, true);
            file_put_contents(self::path($starter), json_encode($replay, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        }
        return $replay;
    }

    /** From the tick: records the home page's replay while there is none, trying at most once an hour */
    public static function recordIfMissing(): void
    {
        if (self::of(self::HOME) !== null || !Cache::add('replay-tried:' . self::HOME, 1, 3600)) {
            return;
        }
        self::record(self::HOME);
    }
}
