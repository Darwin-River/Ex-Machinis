<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * The guided first mission (#932): found a startup, change one number,
 * mature it, with a target time to beat. On a startup's page, for its
 * owner, until they own a matured company. The number to change is the
 * first setting its code marks "TRY FIRST" (the starters mark three),
 * with its line, so the player knows exactly what to edit.
 */
class FirstMission
{
    /**
     * The mission for this startup's owner, or null when there is none:
     * {steps: [{key, title, text, done}], try: {name, value, line, why}|null,
     *  target_minutes, elapsed_minutes, starter}
     */
    public static function for(object $company, ?string $script, ?int $ownerUser): ?array
    {
        if (!config('forge.new_rules') || $ownerUser === null || (int) ($company->startup ?? 0) !== 1
            || $company->eliminated_at !== null) {
            return null;
        }
        // Only until the player has a matured company of their own
        if (DB::table('companies')->where('owner_id', $ownerUser)->where('startup', 0)->exists()) {
            return null;
        }
        $try = self::tryFirst((string) $script);
        $starter = self::starter((string) $script);
        $scale = max(1.0, (float) (DB::table('game_clock')->where('id', 1)->value('scale') ?? 1));
        $days = $starter ? (Release::STARTER_CARDS[$starter]['days'] ?? null) : null;
        // The target: the slow end of the starter's range, at today's pace
        $target = $days ? (int) ceil($days[1] * 86400 / $scale / 60) : null;
        $founded = (int) ($company->founded_t ?? 0);
        $elapsed = $founded > 0 ? max(0, (int) floor((World::gameNow() - $founded) / $scale / 60)) : null;
        $pushed = (int) ($company->pushes ?? 0) > 0;
        return [
            'starter' => $starter, 'try' => $try, 'target_minutes' => $target, 'elapsed_minutes' => $elapsed,
            'steps' => [
                ['key' => 'found', 'title' => 'Found a startup', 'done' => true, 'text' => 'Done: it is running your code.'],
                ['key' => 'change', 'title' => 'Change one number', 'done' => $pushed,
                    'text' => $pushed ? 'Done: you pushed new code.' : ($try
                        ? 'Open Push new code below, change ' . $try['name'] . ' on line ' . $try['line'] . ' (now ' . $try['value'] . ') and push it. It is free.'
                        : 'Open Push new code below, change one of the numbers under Settings and push it. It is free.')],
                ['key' => 'mature', 'title' => 'Mature it', 'done' => false,
                    'text' => 'When it holds enough credits, press Mature it: it repays the bank and its shares are yours.'
                        . ($target ? ' Target: within ' . self::minutes($target) . ' of founding it.' : '')],
            ],
        ];
    }

    /** The first setting a script marks "TRY FIRST": {name, value, line, why}, or null */
    public static function tryFirst(string $script): ?array
    {
        foreach (preg_split('/\R/', $script) as $i => $line) {
            if (preg_match('/^\s*([A-Za-z_]\w*)\s*=\s*([^\s-]+)\s*--\s*TRY FIRST:\s*(.+)$/', $line, $m)) {
                return ['name' => $m[1], 'value' => $m[2], 'line' => $i + 1, 'why' => trim($m[3])];
            }
        }
        return null;
    }

    /** Which starter a script began as, from its first line ("-- Miner: a starter script"), or null */
    public static function starter(string $script): ?string
    {
        if (preg_match('/^--\s*(\w+):\s*a starter script/i', ltrim($script), $m)) {
            $name = strtolower($m[1]);
            return isset(Release::STARTER_CARDS[$name]['days']) ? $name : null;
        }
        return null;
    }

    public static function minutes(int $m): string
    {
        return $m < 90 ? $m . ' real minutes' : 'about ' . round($m / 60, 1) . ' real hours';
    }
}
