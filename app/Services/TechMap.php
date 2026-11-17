<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * The tech map (#804): every manufacturing pathway in the game, from the ores
 * drones dig to the ships they build, worked out from the protocols' effects
 * by Recipes, so it is always the game's own data.
 *
 * Resources are laid out in steps: an ore is step 0, and anything made is one
 * step past the furthest of its inputs, by its shortest recipe (unpacking a
 * kit doesn't count: it only frees modules already made). A resource nothing
 * makes or digs (a kit you buy) is step null.
 */
class TechMap
{
    /** Resource families, by the thousands of their IDs */
    public const FAMILIES = [1 => 'Ore', 2 => 'Material', 3 => 'Part', 4 => 'Component', 5 => 'Module', 6 => 'Ship'];

    public static function build(): array
    {
        $processes = Recipes::processes();
        $recipes = array_filter($processes, fn ($p) => $p['uses'] && !$p['mined']);
        $descriptions = DB::table('protocols')->whereIn('id', array_keys($processes))->pluck('description', 'id')->all();
        $rows = DB::table('resources')->orderBy('id')->get(['id', 'name', 'description', 'mass'])->keyBy('id');

        // What each process is and how a Lua drone performs it
        $list = [];
        foreach ($processes as $id => $p) {
            [$call, $perform] = self::calls($id, $descriptions[$id] ?? '');
            $kind = $p['mined'] ? 'mine'
                : (!$p['tool'] ? 'unpack'
                : (array_filter($p['uses'], fn ($u) => intdiv($u['resource'], 1000) === 1) ? 'refine' : 'make'));
            $list[$id] = [
                'protocol' => $id, 'name' => $p['name'], 'kind' => $kind, 'call' => $call, 'perform' => $perform,
                'makes' => self::pairs($p['makes']), 'uses' => self::pairs($p['uses']),
                'tool' => $p['tool'], 'fuel' => $p['fuel'], 'hours' => $p['hours'],
            ];
        }

        // Every resource the processes touch, and its links
        $res = [];
        $touch = function (int $r) use (&$res, $rows) {
            if (!isset($res[$r]) && isset($rows[$r])) {
                $res[$r] = ['id' => $r, 'made_by' => [], 'used_in' => [], 'tool_for' => []];
            }
            return isset($res[$r]);
        };
        foreach ($list as $id => $p) {
            foreach ($p['makes'] as [$r]) {
                $touch($r) && $res[$r]['made_by'][] = $id;
            }
            foreach ($p['uses'] as [$r]) {
                $touch($r) && $res[$r]['used_in'][] = $id;
            }
            if ($p['tool'] && $touch($p['tool'])) {
                $res[$p['tool']]['tool_for'][] = $id;
            }
        }

        // Steps: ores 0, anything made one past the furthest of its inputs
        $step = [];
        foreach ($list as $p) {
            if ($p['kind'] === 'mine') {
                foreach ($p['makes'] as [$r]) {
                    $step[$r] = 0;
                }
            }
        }
        for ($pass = 0, $changed = true; $changed && $pass < 50; $pass++) {
            $changed = false;
            foreach ($list as $p) {
                if ($p['kind'] === 'mine' || $p['kind'] === 'unpack') {
                    continue;
                }
                $inputs = array_map(fn ($u) => $step[$u[0]] ?? null, $p['uses']);
                if (in_array(null, $inputs, true)) {
                    continue;
                }
                $s = max($inputs) + 1;
                foreach ($p['makes'] as [$r]) {
                    if (!isset($step[$r]) || $s < $step[$r]) {
                        $step[$r] = $s;
                        $changed = true;
                    }
                }
            }
        }

        // Pictures, families and what one unit takes, down to refined goods
        $names = $rows->pluck('name', 'id')->all();
        foreach ($res as $r => &$item) {
            $row = $rows[$r];
            $thumb = 'images/resources/' . $r . '-thumb.webp';
            $item += [
                'name' => $row->name, 'description' => $row->description, 'mass' => $row->mass + 0,
                'family' => self::FAMILIES[intdiv($r, 1000)] ?? 'Other',
                'step' => $step[$r] ?? null,
                'url' => url('resources/' . $r),
                'thumb' => is_file(public_path($thumb)) ? url($thumb) : null,
            ];
            $made = array_filter($item['made_by'], fn ($p) => isset($recipes[$p]));
            $tree = $made && ($step[$r] ?? 0) > 1 ? Recipes::tree($r, 2, $recipes, $names) : null;
            $item['tree'] = $tree ? [
                'goods' => array_map(fn ($g) => [$g['resource'], $g['units']], $tree['goods']),
                'fuel' => $tree['fuel'],
                'tools' => array_map(fn ($t) => [$t['tool'], $t['hours']], $tree['tool_hours']),
                'steps' => array_map(fn ($s) => [$s['protocol'], $s['runs']], $tree['steps']),
            ] : null;
        }
        unset($item);
        uasort($res, fn ($a, $b) => [$a['step'] ?? -1, $a['id']] <=> [$b['step'] ?? -1, $b['id']]);

        $steps = array_values(array_unique(array_map(fn ($r) => $r['step'], $res)));
        return [
            'resources' => array_values($res),
            'processes' => $list,
            'columns' => array_map(fn ($s) => ['step' => $s, 'label' => self::stepLabel($s)], $steps),
        ];
    }

    /** A column's heading */
    public static function stepLabel(?int $step): string
    {
        return match ($step) {
            null => 'Bought, not made',
            0 => 'Dug: ores',
            1 => 'Step 1: refined',
            default => 'Step ' . $step,
        };
    }

    /**
     * The Lua call that performs a protocol, and its perform() form, from the
     * start of its description ("make(alloys_a3), or perform(12023): ...")
     */
    public static function calls(int $protocol, string $description): array
    {
        if (preg_match('/^([a-z_]+\([^)]*\))(?:, or (perform\([^)]*\)))?:/', $description, $m)) {
            return [$m[1], $m[2] ?? $m[1]];
        }
        return ["perform($protocol)", "perform($protocol)"];
    }

    /** [{resource, units}] as compact [resource, units] pairs */
    private static function pairs(array $items): array
    {
        return array_map(fn ($i) => [$i['resource'], $i['units']], $items);
    }
}
