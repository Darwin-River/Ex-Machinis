<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * A resource heat map on the solar-system map (#952): where a mineral is
 * found, or the minerals a refined material comes from, this season or the
 * next (#922). The bodies are the map's: each planet (its moons and rings
 * counted with it), and each asteroid and comet on its own.
 *
 * Since #981 a body's heat is its best site's recovery rate: what one
 * mining run there would bring in now for a drone that arrives, counting
 * crowding (#974) the way the engine does (db_crowd_yield): past
 * house_rules.crowd_capacity drones mining a mineral at a place in the last
 * game hour, a run yields abundance x capacity / crowd, at least 1. For a
 * mineral the rate is the units a run yields; for a refined good, the units
 * of it a run's ore refines to. Next season's homes have nobody mining them
 * yet, so they show their abundance.
 */
class ResourceMap
{
    /** The resources the map can show: every mineral found somewhere, and the refined goods they make */
    public static function options(): array
    {
        $minerals = DB::table('abundancies')->distinct()->pluck('resource')->map(fn ($r) => (int) $r)->all();
        if (\Illuminate\Support\Facades\Schema::hasTable('far_minerals')) {
            $minerals = array_merge($minerals, DB::table('far_minerals')->pluck('resource')->map(fn ($r) => (int) $r)->all());
        }
        $minerals = array_values(array_unique($minerals));
        sort($minerals);
        $names = DB::table('resources')->pluck('name', 'id')->all();
        $out = [];
        $refined = [];
        foreach (Recipes::all() as $r) {
            $uses = array_column($r['uses'], 'resource');
            if (count($uses) === 1 && in_array($uses[0], $minerals, true)) {
                foreach (array_column($r['makes'], 'resource') as $m) {
                    $refined[$m] = true;
                }
            }
        }
        ksort($refined);
        foreach (array_keys($refined) as $m) {
            $out[] = ['resource' => $m, 'name' => $names[$m] ?? (string) $m, 'kind' => 'refined'];
        }
        foreach ($minerals as $m) {
            $out[] = ['resource' => $m, 'name' => $names[$m] ?? (string) $m, 'kind' => 'mineral'];
        }
        return $out;
    }

    /** The minerals a resource comes from: itself for a mineral, those that refine to it for a refined good */
    public static function sources(int $resource): array
    {
        $sources = [];
        foreach (Recipes::making($resource) as $r) {
            $uses = array_column($r['uses'], 'resource');
            if (count($uses) === 1 && $uses[0] < 2000) {
                $sources[] = (int) $uses[0];
            }
        }
        return $sources ?: [$resource];
    }

    /**
     * The map: every body with its position (as World::map draws planets,
     * the asteroids and comets placed the same way) and its heat for the
     * resource. $next: the next season's far minerals, once published.
     */
    public static function build(int $resource, bool $next = false): array
    {
        $minerals = self::sources($resource);
        $names = DB::table('resources')->pluck('name', 'id')->all();
        $perOre = self::perOre($resource, $minerals);
        $crowds = $next ? [] : self::crowds($minerals);
        $capacity = self::capacity();
        $seasons = FarMap::seasons();
        $far = \Illuminate\Support\Facades\Schema::hasTable('far_minerals')
            ? DB::table('far_minerals')->pluck('resource')->map(fn ($r) => (int) $r)->all() : [];
        $nextShown = $next && $seasons && ($seasons['next']['published'] ?? false);

        // Where each chosen mineral is: abundancies (this season), with the
        // far minerals' rows from next season's map when asked and published
        $rows = DB::table('abundancies')->whereIn('resource', $minerals)
            ->when($nextShown, fn ($q) => $q->whereNotIn('resource', $far))
            ->get(['location', 'resource', 'multiplier'])->map(fn ($r) => (array) $r)->all();
        if ($nextShown) {
            foreach (DB::table('far_map')->where('season', $seasons['next']['season'])->whereIn('resource', $minerals)
                         ->get(['object_id', 'resource', 'multiplier']) as $r) {
                $rows[] = ['location' => $r->object_id, 'resource' => $r->resource, 'multiplier' => $r->multiplier];
            }
        }

        $objects = DB::table('objects')->get(['object_id', 'object_name', 'object_type', 'central_body_object_id',
            'semimajor_axis', 'ascending_node_longitude', 'periapsis_argument', 'mean_anomaly', 'mean_angular_motion'])
            ->keyBy('object_id');
        $bodies = self::bodies($objects);
        $byId = [];
        foreach ($bodies as $i => $b) {
            $byId[$b['id']] = $i;
        }
        foreach ($rows as $r) {
            $o = $objects[(int) $r['location']] ?? null;
            if (!$o) {
                continue;
            }
            // A moon or ring counts for its planet
            $body = isset($byId[(int) $o->object_id]) ? (int) $o->object_id : (int) $o->central_body_object_id;
            if (!isset($byId[$body])) {
                continue;
            }
            $i = $byId[$body];
            $mineral = (int) $r['resource'];
            $crowd = $crowds[(int) $o->object_id][$mineral] ?? ['weight' => 0, 'drones' => 0];
            $yield = self::runYield((int) $r['multiplier'], $crowd['weight'], $capacity);
            $rate = isset($perOre[$mineral]) ? round($yield * $perOre[$mineral], 2) : $yield;
            $bodies[$i]['heat'] = max($bodies[$i]['heat'], $rate);
            $bodies[$i]['places'][] = ['object_id' => (int) $o->object_id, 'name' => $o->object_name,
                'mineral' => $mineral, 'mineral_name' => $names[$mineral] ?? null,
                'multiplier' => (int) $r['multiplier'], 'miners' => $crowd['drones'], 'yield' => $yield, 'rate' => $rate];
        }
        foreach ($bodies as &$b) {
            usort($b['places'], fn ($x, $y) => [$y['rate'], $y['multiplier'], $x['name']] <=> [$x['rate'], $x['multiplier'], $y['name']]);
        }
        unset($b);
        $max = max(array_merge([0], array_column($bodies, 'heat')));
        return [
            'resource' => ['resource' => $resource, 'name' => $names[$resource] ?? (string) $resource,
                'kind' => $resource < 2000 ? 'mineral' : 'refined'],
            'minerals' => array_map(fn ($m) => ['resource' => $m, 'name' => $names[$m] ?? (string) $m,
                'moves' => in_array($m, $far, true)], $minerals),
            'season' => $nextShown ? $seasons['next']['season'] : ($seasons['current']['season'] ?? null),
            'next' => $nextShown,
            'next_published' => (bool) ($seasons['next']['published'] ?? false),
            'max_heat' => $max,
            'crowd_capacity' => $capacity,
            'bodies' => $bodies,
        ];
    }

    /**
     * What one mining run yields a drone that arrives where the drones that
     * mined there in the last game hour weigh $weight thousandths (#974, as
     * db_crowd_yield, counting the newcomer as a matured company's drone)
     */
    public static function runYield(int $abundance, int $weight, int $capacity): int
    {
        if ($abundance <= 0) {
            return 0;
        }
        $crowd = $weight + 1000;
        if ($capacity <= 0 || $crowd <= $capacity * 1000) {
            return $abundance;
        }
        return max(1, intdiv($abundance * $capacity * 1000, $crowd));
    }

    /** house_rules.crowd_capacity (#974), 0 before migration 0101 or when crowding is off */
    private static function capacity(): int
    {
        if (!\Illuminate\Support\Facades\Schema::hasColumn('house_rules', 'crowd_capacity')) {
            return 0;
        }
        return (int) DB::table('house_rules')->where('id', 1)->value('crowd_capacity');
    }

    /** By place and mineral: the drones that mined it there in the last game hour, and their weight (thousandths) */
    private static function crowds(array $minerals): array
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('mining_runs')) {
            return [];
        }
        $out = [];
        foreach (DB::table('mining_runs')->whereIn('resource', $minerals)->where('game_t', '>', World::gameNow() - 3600)
                     ->groupBy('object_id', 'resource')
                     ->get(['object_id', 'resource', DB::raw('SUM(weight) AS weight'), DB::raw('COUNT(*) AS drones')]) as $r) {
            $out[(int) $r->object_id][(int) $r->resource] = ['weight' => (int) $r->weight, 'drones' => (int) $r->drones];
        }
        return $out;
    }

    /** Units of the resource a unit of each mineral refines to (1 for the mineral itself) */
    private static function perOre(int $resource, array $minerals): array
    {
        $out = [];
        foreach (Recipes::making($resource) as $r) {
            $uses = $r['uses'];
            if (count($uses) !== 1 || !in_array($uses[0]['resource'], $minerals, true) || $uses[0]['units'] <= 0) {
                continue;
            }
            foreach ($r['makes'] as $m) {
                if ($m['resource'] === $resource) {
                    $out[$uses[0]['resource']] = $m['units'] / $uses[0]['units'];
                }
            }
        }
        return $out;
    }

    /** The map's bodies: the planets as World::map places them, and the asteroids and comets */
    private static function bodies($objects): array
    {
        $out = [];
        foreach (World::map() as $p) {
            $out[] = ['id' => $p['id'], 'name' => $p['name'], 'type' => 'Planet', 'x' => $p['x'], 'y' => $p['y'],
                'heat' => 0, 'places' => []];
        }
        // The same log scale as World::map, from the planets' least and greatest semimajor axes
        $planets = $objects->where('object_type', 'Planet');
        if ($planets->isEmpty()) {
            return $out;
        }
        $min = log10(max(1, (float) $planets->min('semimajor_axis')));
        $max = log10(max(1, (float) $planets->max('semimajor_axis')));
        $days = (World::gameNow() - 946684800) / 86400.0;
        foreach ($objects->whereIn('object_type', ['Asteroid', 'Comet']) as $o) {
            if ((int) $o->central_body_object_id !== 0 || !$o->semimajor_axis) {
                continue;
            }
            $longitude = deg2rad((float) $o->ascending_node_longitude + (float) $o->periapsis_argument
                + (float) $o->mean_anomaly + (float) $o->mean_angular_motion * $days);
            $radius = 30 + 250 * (log10(max(1, (float) $o->semimajor_axis)) - $min) / max(0.001, $max - $min);
            $out[] = ['id' => (int) $o->object_id, 'name' => $o->object_name, 'type' => $o->object_type,
                'x' => round(300 + $radius * cos($longitude), 1), 'y' => round(300 - $radius * sin($longitude), 1),
                'heat' => 0, 'places' => []];
        }
        return $out;
    }
}
