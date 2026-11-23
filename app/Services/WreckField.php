<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * The wreck field (#929): the wrecks near each place that still hold
 * something to salvage, how far each has decayed, and who plundered and
 * salvaged it (flagged when the same player is behind the wreck and the
 * drone that took from it). A wreck decays by 2% of what is left a game day.
 */
class WreckField
{
    /** Most wrecks listed */
    public const LIMIT = 40;

    /** A wreck's condition in thousandths after this many game seconds: 2% of what is left lost a game day */
    public static function condition(int $ageSeconds): int
    {
        // 1000 x 0.98^days, rounded down, as the engine and query 660 work it out
        return (int) floor(1000 * pow(0.98, intdiv(max(0, $ageSeconds), 86400)) + 1e-9);
    }

    /**
     * The wrecks with something left to salvage, newest first: {drone, name,
     * company, startup, place, place_name, wrecked (when), condition (percent),
     * modules, scrap, plundered_by, salvaged_by, same_player}
     */
    public static function list(int $limit = self::LIMIT): array
    {
        $now = World::gameNow();
        $rows = DB::table('agents AS a')
            ->join('companies AS c', 'c.company_id', '=', 'a.company_id')
            ->leftJoin('objects AS o', 'o.object_id', '=', 'a.object_id')
            ->whereNotNull('a.wrecked_at')->where('c.house', 0)
            ->where(fn ($q) => $q->whereNull('a.wreck_cause')->orWhereNotIn('a.wreck_cause', ['wound_up', 'reset']))
            ->where(fn ($q) => $q->whereNull('a.scrap_left')->orWhere('a.scrap_left', '>', 0))
            ->orderByDesc('a.wrecked_at')->limit(max(1, $limit))
            ->get(['a.agent_id', 'a.name', 'a.object_id', 'o.object_name', 'a.wrecked_at', 'a.hull_type', 'a.scrap_left',
                   'a.plundered_by', 'a.salvaged_by', 'c.company_id', 'c.name AS company', 'c.startup', 'c.owner_id']);
        if ($rows->isEmpty()) {
            return [];
        }
        $ids = $rows->pluck('agent_id')->all();
        $modules = DB::table('holdings')->whereIn('drone', $ids)->where('resource', '>=', 5000)->where('resource', '<', 5900)
            ->groupBy('drone')->select('drone', DB::raw('SUM(units) AS n'))->pluck('n', 'drone');
        $hulls = DB::table('resources')->whereIn('id', $rows->pluck('hull_type')->filter()->unique()->all())->pluck('mass', 'id');
        $takers = $rows->pluck('plundered_by')->merge($rows->pluck('salvaged_by'))->filter()->unique()->all();
        $owners = $takers ? DB::table('agents AS a')->join('companies AS c', 'c.company_id', '=', 'a.company_id')
            ->whereIn('a.agent_id', $takers)->get(['a.agent_id', 'c.owner_id', 'c.company_id'])->keyBy('agent_id') : collect();
        $founder = fn ($owner, $company) => $owner !== null ? 'u' . $owner : 'c' . $company;
        $out = [];
        foreach ($rows as $r) {
            $mine = $founder($r->owner_id, $r->company_id);
            $same = false;
            foreach (['plundered_by', 'salvaged_by'] as $k) {
                $t = $r->$k !== null ? ($owners[(int) $r->$k] ?? null) : null;
                if ($t && (int) $t->company_id !== (int) $r->company_id && $founder($t->owner_id, $t->company_id) === $mine) {
                    $same = true;
                }
            }
            $out[] = [
                'drone' => (int) $r->agent_id, 'name' => $r->name, 'company' => $r->company, 'startup' => (bool) $r->startup,
                'place' => (int) $r->object_id, 'place_name' => $r->object_name,
                'wrecked' => DronePage::when((int) $r->wrecked_at),
                'condition' => round(self::condition($now - (int) $r->wrecked_at) / 10, 1),
                'modules' => (int) ($modules[$r->agent_id] ?? 0),
                'scrap' => $r->scrap_left !== null ? (int) $r->scrap_left : max(10, (int) ($hulls[$r->hull_type] ?? 0)),
                'plundered_by' => $r->plundered_by !== null ? (int) $r->plundered_by : null,
                'salvaged_by' => $r->salvaged_by !== null ? (int) $r->salvaged_by : null,
                'same_player' => $same,
            ];
        }
        return $out;
    }
}
