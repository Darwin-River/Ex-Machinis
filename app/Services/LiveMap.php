<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The home page's live map (#1049): the solar system as the map page draws
 * it (World::map: each planet where it is now, distance on a log scale), with
 * Earth's Moon and Ceres beside it, how many players' drones are working at
 * each, and the flights under way between them. A moon other than ours counts
 * with its planet. Kept 30 real seconds: a page view reads what it kept.
 */
class LiveMap
{
    private const KEEP = 30;

    /** Earth, the Moon, Ceres: the bodies drawn apart from their planet */
    private const EARTH = 3;
    private const MOON = 10;
    private const CERES = 191;

    /**
     * {bodies: [{id, name, x, y, working, kind (sun|planet|moon|dwarf)}],
     *  flights: [{from, to, drones}], flying (drones in flight), size (the
     *  drawing's width and height)}
     */
    public static function now(): array
    {
        return Cache::remember('live-map', self::KEEP, fn () => self::work());
    }

    private static function work(): array
    {
        $planets = World::map();
        $byId = [];
        foreach ($planets as $p) {
            $byId[$p['id']] = ['id' => $p['id'], 'name' => $p['name'], 'x' => $p['x'], 'y' => $p['y'], 'working' => $p['working'],
                'kind' => 'planet'];
        }
        // Where each body is drawn: its own place, or its planet's (a moon other than ours)
        $objects = DB::table('objects')->get(['object_id', 'object_name', 'object_type', 'central_body_object_id', 'semimajor_axis',
            'ascending_node_longitude', 'periapsis_argument', 'mean_anomaly', 'mean_angular_motion'])->keyBy('object_id');
        $drawnAs = function (int $id) use ($objects): ?int {
            if ($id === self::MOON || $id === self::CERES) {
                return $id;
            }
            $o = $objects[$id] ?? null;
            if (!$o) {
                return null;
            }
            return $o->object_type === 'Planet' || !$o->central_body_object_id ? $id : (int) $o->central_body_object_id;
        };

        // The Moon beside Earth, at its place in its orbit; its drones no longer counted with Earth's
        $moonDrones = self::workingAt([self::MOON]);
        if (isset($byId[self::EARTH])) {
            $e = $byId[self::EARTH];
            $angle = self::longitude($objects[self::MOON] ?? null);
            // Close beside Earth, so it reads as Earth's moon whatever the zoom
            $byId[self::MOON] = ['id' => self::MOON, 'name' => 'Moon', 'x' => round($e['x'] + 9 * cos($angle), 1),
                'y' => round($e['y'] - 9 * sin($angle), 1), 'working' => $moonDrones, 'kind' => 'moon'];
            $byId[self::EARTH]['working'] = max(0, $e['working'] - $moonDrones);
        }
        // Ceres, on the same scale as the planets (it is no planet, so World::map leaves it out)
        $ceres = $objects[self::CERES] ?? null;
        if ($ceres && $ceres->semimajor_axis && $planets) {
            $axes = DB::table('objects')->where('object_type', 'Planet')->pluck('semimajor_axis')->map(fn ($a) => log10(max(1, (float) $a)));
            [$min, $max] = [$axes->min(), $axes->max()];
            $radius = 30 + 250 * (log10((float) $ceres->semimajor_axis) - $min) / max(0.001, $max - $min);
            $angle = self::longitude($ceres);
            $byId[self::CERES] = ['id' => self::CERES, 'name' => 'Ceres', 'x' => round(300 + $radius * cos($angle), 1),
                'y' => round(300 - $radius * sin($angle), 1), 'working' => self::workingAt([self::CERES]), 'kind' => 'dwarf'];
        }

        // Flights under way: an arrival still to come, from where the drone still is
        $flights = [];
        $flying = 0;
        $rows = DB::table('events AS e')->join('agents AS a', 'a.agent_id', '=', 'e.drone')
            ->whereNull('a.station_id')->whereNull('a.wrecked_at')
            ->where('e.game_t', '>', World::gameNow())->where('e.event_type', 3)->whereNotNull('e.new_location')
            ->whereNotExists(fn ($q) => $q->from('actions AS x')->whereColumn('x.id', 'e.action')->where('x.aborted', 1))
            ->groupBy('a.object_id', 'e.new_location')->selectRaw('a.object_id AS origin, e.new_location AS dest, COUNT(DISTINCT e.drone) AS n')->get();
        foreach ($rows as $r) {
            $from = $drawnAs((int) $r->origin);
            $to = $drawnAs((int) $r->dest);
            $flying += (int) $r->n;
            if ($from === null || $to === null || $from === $to || !isset($byId[$from], $byId[$to])) {
                continue;
            }
            $key = $from . '-' . $to;
            $flights[$key] = ['from' => $from, 'to' => $to, 'drones' => ($flights[$key]['drones'] ?? 0) + (int) $r->n];
        }
        return ['bodies' => array_values($byId), 'flights' => array_values($flights), 'flying' => $flying, 'size' => 600];
    }

    /** Players' working drones at these places */
    private static function workingAt(array $places): int
    {
        return World::playerDrones()->whereIn('a.object_id', $places)->whereNull('a.wrecked_at')->count();
    }

    /** A body's angle in its orbit now, as World::map works it out (radians) */
    private static function longitude(?object $o): float
    {
        if (!$o) {
            return 0.0;
        }
        $days = (World::gameNow() - 946684800) / 86400.0;
        return deg2rad((float) $o->ascending_node_longitude + (float) $o->periapsis_argument + (float) $o->mean_anomaly
            + (float) $o->mean_angular_motion * $days);
    }
}
