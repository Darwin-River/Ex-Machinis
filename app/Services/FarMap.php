<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Resources by place (#922): Metals (A3) come only from far away. The
 * minerals that refine to them (far_minerals, from the game data) are found
 * on a few far bodies at a time, their homes, and the homes move each
 * season: every SEASON_DAYS real days. Each season's homes are picked from
 * far_homes the same way every time (a hash of the season, the mineral and
 * the body), so the next season's are known in advance; they are published
 * PUBLISH_DAYS real days before it starts. The current season's homes are
 * added to abundancies and deposits, which the engines read when a drone
 * mines; the game data leaves these minerals out, so the website adds them
 * again at start-up (forge:far-map, after the game data is loaded) and the
 * tick keeps them there.
 */
class FarMap
{
    public const SEASON_DAYS = 28;
    public const PUBLISH_DAYS = 7;

    /** A deposit's size and refill, in mining runs (as build_game_data.py's DEPOSIT_RUNS, REFILL_RUNS_PER_DAY) */
    public const DEPOSIT_RUNS = 400;
    public const REFILL_RUNS_PER_DAY = 80;

    public static function enabled(): bool
    {
        return Schema::hasTable('far_minerals') && DB::table('far_minerals')->exists();
    }

    /** From the tick: keeps the season current and its homes in place; cheap when nothing is due */
    public static function applyIfDue(): bool
    {
        if (Cache::get('far-map-ok')) {
            return false;
        }
        if (!self::enabled()) {
            Cache::put('far-map-ok', true, 60);
            return false;
        }
        $season = self::current();
        $want = DB::table('far_map')->where('season', $season->season)->count();
        $have = DB::table('abundancies')->whereIn('resource', self::minerals())->count();
        $changed = $want !== $have || !self::inPlace($season->season);
        if ($changed) {
            self::sync($season->season);
        }
        Cache::put('far-map-ok', true, 60);
        return $changed;
    }

    /** Puts the current season's homes in place now (forge:far-map, at start-up) */
    public static function apply(): ?object
    {
        if (!self::enabled()) {
            return null;
        }
        $season = self::current();
        self::sync($season->season);
        Cache::forget('far-map-ok');
        return $season;
    }

    /** @return int[] */
    private static function minerals(): array
    {
        return DB::table('far_minerals')->pluck('resource')->map(fn ($r) => (int) $r)->all();
    }

    /**
     * The season now, starting the first and catching up as needed, with the
     * homes of it and the next picked
     */
    public static function current(): object
    {
        return DB::transaction(function () {
            $now = now();
            $last = DB::table('far_seasons')->orderByDesc('season')->lockForUpdate()->first();
            if (!$last) {
                DB::table('far_seasons')->insert(['season' => 1, 'starts_at' => $now,
                    'ends_at' => $now->copy()->addDays(self::SEASON_DAYS)]);
                $last = DB::table('far_seasons')->where('season', 1)->first();
            }
            // Enough seasons to reach past now, and one more: the next is planned
            while (\Carbon\Carbon::parse($last->starts_at) <= $now) {
                $start = \Carbon\Carbon::parse($last->ends_at);
                DB::table('far_seasons')->insert(['season' => $last->season + 1, 'starts_at' => $start,
                    'ends_at' => $start->copy()->addDays(self::SEASON_DAYS)]);
                $last = DB::table('far_seasons')->where('season', $last->season + 1)->first();
            }
            $current = DB::table('far_seasons')->where('starts_at', '<=', $now)->orderByDesc('season')->first();
            foreach ([$current->season, $current->season + 1] as $s) {
                if (!DB::table('far_map')->where('season', $s)->exists()) {
                    DB::table('far_map')->insert(self::pick($s));
                }
            }
            DB::table('far_map')->where('season', '<', $current->season)->delete();
            return $current;
        });
    }

    /** A season's homes: for each far mineral, its `homes` far bodies, chosen by a hash (sha1) of the season, the mineral and the body */
    public static function pick(int $season): array
    {
        $rows = [];
        foreach (DB::table('far_minerals')->orderBy('resource')->get() as $m) {
            $bodies = DB::table('far_homes')->where('resource', $m->resource)->pluck('object_id')
                ->map(fn ($o) => (int) $o)->all();
            // (sha1, not crc32: crc32 is close to linear, so the order hardly changed from one season to the next)
            usort($bodies, fn ($a, $b) => [sha1("far-map:$season:{$m->resource}:$a"), $a]
                <=> [sha1("far-map:$season:{$m->resource}:$b"), $b]);
            foreach (array_slice($bodies, 0, (int) $m->homes) as $o) {
                $rows[] = ['season' => $season, 'resource' => (int) $m->resource, 'object_id' => $o,
                    'multiplier' => (int) $m->multiplier];
            }
        }
        return $rows;
    }

    /** Are the season's homes the ones in abundancies? */
    private static function inPlace(int $season): bool
    {
        $missing = DB::table('far_map as f')->where('f.season', $season)
            ->leftJoin('abundancies as a', fn ($j) => $j->on('a.location', '=', 'f.object_id')->on('a.resource', '=', 'f.resource'))
            ->whereNull('a.resource')->count();
        return $missing === 0;
    }

    /** The season's homes into abundancies and deposits, in place of any others */
    private static function sync(int $season): void
    {
        $minerals = self::minerals();
        if (!$minerals) {
            return;
        }
        DB::transaction(function () use ($season, $minerals) {
            $homes = DB::table('far_map')->where('season', $season)->get();
            DB::table('abundancies')->whereIn('resource', $minerals)->delete();
            DB::table('deposits')->whereIn('resource', $minerals)->delete();
            // A mineral that has moved on leaves nothing behind; a new home starts full
            $keep = $homes->map(fn ($h) => $h->object_id . ':' . $h->resource)->all();
            foreach (DB::table('deposit_state')->whereIn('resource', $minerals)->get(['object_id', 'resource']) as $s) {
                if (!in_array($s->object_id . ':' . $s->resource, $keep, true)) {
                    DB::table('deposit_state')->where('object_id', $s->object_id)->where('resource', $s->resource)->delete();
                }
            }
            if ($homes->isNotEmpty()) {
                DB::table('abundancies')->insert($homes->map(fn ($h) => ['location' => (int) $h->object_id,
                    'resource' => (int) $h->resource, 'multiplier' => (int) $h->multiplier])->all());
                DB::table('deposits')->insert($homes->map(fn ($h) => ['object_id' => (int) $h->object_id,
                    'resource' => (int) $h->resource, 'capacity' => (int) $h->multiplier * self::DEPOSIT_RUNS,
                    'refill_per_day' => (int) $h->multiplier * self::REFILL_RUNS_PER_DAY])->all());
            }
        });
    }

    /**
     * For the API and the map page: this season's homes, and the next
     * season's once published (PUBLISH_DAYS before it starts)
     */
    public static function seasons(): ?array
    {
        if (!self::enabled()) {
            return null;
        }
        $current = self::current();
        $next = DB::table('far_seasons')->where('season', $current->season + 1)->first();
        $published = $next && now() >= \Carbon\Carbon::parse($next->starts_at)->subDays(self::PUBLISH_DAYS);
        $names = DB::table('resources')->whereIn('id', self::minerals())->pluck('name', 'id');
        $homes = function (int $season) use ($names) {
            $rows = DB::table('far_map as f')->join('objects as o', 'o.object_id', '=', 'f.object_id')
                ->where('f.season', $season)->orderBy('f.resource')->orderBy('o.object_name')
                ->get(['f.resource', 'f.object_id', 'o.object_name', 'f.multiplier']);
            $out = [];
            foreach ($rows->groupBy('resource') as $resource => $places) {
                $out[] = ['resource' => (int) $resource, 'name' => $names[$resource] ?? (string) $resource,
                    'places' => $places->map(fn ($p) => ['object_id' => (int) $p->object_id, 'name' => $p->object_name,
                        'multiplier' => (int) $p->multiplier])->values()->all()];
            }
            return $out;
        };
        $season = fn ($s, $withHomes) => ['season' => (int) $s->season,
            'starts_at' => \Carbon\Carbon::parse($s->starts_at)->toIso8601String(),
            'ends_at' => \Carbon\Carbon::parse($s->ends_at)->toIso8601String(),
            'homes' => $withHomes ? $homes((int) $s->season) : null];
        return [
            'current' => $season($current, true),
            'next' => $next ? $season($next, $published) + ['published' => $published,
                'published_at' => \Carbon\Carbon::parse($next->starts_at)->subDays(self::PUBLISH_DAYS)->toIso8601String()] : null,
            'season_days' => self::SEASON_DAYS,
            'publish_days' => self::PUBLISH_DAYS,
        ];
    }
}
