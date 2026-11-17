<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Recipes (#380 round 36): three of four newcomers built their own recipe
 * tables by reading the 149 protocol descriptions. A recipe is worked out
 * from a protocol's resource effects: what it ends with more of is what it
 * makes, what it ends with less of is what it uses (fuel apart), and a
 * resource it locks and gives back is its tool.
 */
class Recipes
{
    /** Every recipe: [protocol => recipe], recipe: protocol, name, makes, uses, fuel, tool, hours */
    public static function all(): array
    {
        $recipes = array_filter(self::processes(), fn ($r) => $r['uses'] && !$r['mined']);
        return array_map(function ($r) { unset($r['mined']); return $r; }, $recipes);
    }

    /**
     * Every protocol that makes something with the drone's own tools (#804):
     * the recipes, and mining (it uses nothing up: what it makes is dug, so
     * `mined` is true and the units are times the place's abundance).
     * Fuel a protocol burns is its fuel, not something it makes less of, so
     * Make Fuel makes 30 and burns 2 (#805: it read as making 28, burning 0).
     */
    public static function processes(): array
    {
        $effects = DB::table('resource_effects as e')->join('protocols as p', 'p.id', '=', 'e.protocol')
            ->where('e.installed', 0)->where('e.local', 1)->whereIn('e.event_type', [1, 2])
            ->orderBy('e.protocol')->orderBy('e.id')
            ->get(['e.protocol', 'p.name', 'e.resource', 'e.event_type', 'e.locked', 'e.quantity', 'e.time', 'e.deplete', 'e.abundancies']);
        $by = [];
        foreach ($effects as $e) {
            $p = (int) $e->protocol;
            $by[$p] ??= ['name' => $e->name, 'net' => [], 'locked' => [], 'seconds' => 0, 'fuel' => 0, 'mined' => false];
            $q = (int) $e->quantity * ((int) $e->event_type === 1 ? 1 : -1);
            $r = (int) $e->resource;
            $by[$p]['seconds'] = max($by[$p]['seconds'], (int) $e->time);
            if ($r <= 0 || (int) $e->quantity <= 0) {
                continue;   // set by the parameters: trading, importing
            }
            if ($r === Release::FUEL && $q < 0 && (int) $e->locked === 0) {
                $by[$p]['fuel'] -= $q;
                continue;
            }
            if ((int) $e->abundancies === 1 && $q > 0) {
                $by[$p]['mined'] = true;
            }
            $by[$p]['net'][$r] = ($by[$p]['net'][$r] ?? 0) + $q;
            if ((int) $e->locked === 1) {
                $by[$p]['locked'][$r] = true;
            }
        }
        $names = DB::table('resources')->pluck('name', 'id')->all();
        $recipes = [];
        foreach ($by as $p => $b) {
            $makes = $uses = [];
            foreach ($b['net'] as $r => $n) {
                if ($n > 0) {
                    $makes[] = ['resource' => $r, 'name' => $names[$r] ?? null, 'units' => $n];
                } elseif ($n < 0) {
                    $uses[] = ['resource' => $r, 'name' => $names[$r] ?? null, 'units' => -$n];
                }
            }
            $tool = array_keys($b['locked'])[0] ?? null;
            if (!$makes || (!$uses && !$tool)) {
                continue;   // not a recipe or mining: trading, travel...
            }
            $recipes[$p] = [
                'protocol' => $p, 'name' => $b['name'], 'makes' => $makes, 'uses' => $uses, 'fuel' => $b['fuel'],
                'tool' => $tool, 'tool_name' => $tool ? ($names[$tool] ?? null) : null,
                'hours' => round($b['seconds'] / 3600, 2), 'mined' => $b['mined'],
            ];
        }
        return $recipes;
    }

    /** The recipes that make a resource */
    public static function making(int $resource, ?array $all = null): array
    {
        return array_values(array_filter($all ?? self::all(),
            fn ($r) => in_array($resource, array_column($r['makes'], 'resource'), true)));
    }

    /**
     * What one unit of a resource takes, all the way down to refined goods
     * ($downTo 2: metals, organics, inorganics, what refineries make) or to
     * ores ($downTo 1),
     * by the first recipe for each: the goods (units), the fuel, and the
     * hours each tool works (a fraction where a recipe makes several).
     */
    public static function tree(int $resource, int $downTo = 2, ?array $all = null, ?array $names = null): array
    {
        $all ??= self::all();
        $goods = $tools = [];
        $fuel = 0.0;
        $steps = [];
        $walk = function (int $res, float $units, int $depth) use (&$walk, $all, $downTo, &$goods, &$tools, &$fuel, &$steps) {
            $recipe = $depth < 12 ? (self::making($res, $all)[0] ?? null) : null;
            // Down to refined goods: a refinery's recipe (it uses ores) is where it stops
            if ($recipe !== null && $downTo === 2
                && array_filter($recipe['uses'], fn ($u) => intdiv($u['resource'], 1000) <= 1)) {
                $recipe = null;
            }
            if ($recipe === null) {
                $goods[$res] = ($goods[$res] ?? 0) + $units;
                return;
            }
            $made = 0;
            foreach ($recipe['makes'] as $m) {
                if ($m['resource'] === $res) {
                    $made = $m['units'];
                }
            }
            $runs = $units / max(1, $made);
            $steps[] = ['protocol' => $recipe['protocol'], 'name' => $recipe['name'], 'runs' => round($runs, 3)];
            $fuel += $recipe['fuel'] * $runs;
            if ($recipe['tool']) {
                $tools[$recipe['tool']] = ($tools[$recipe['tool']] ?? 0) + $recipe['hours'] * $runs;
            }
            foreach ($recipe['uses'] as $u) {
                $walk($u['resource'], $u['units'] * $runs, $depth + 1);
            }
        };
        $walk($resource, 1.0, 0);
        $names ??= DB::table('resources')->pluck('name', 'id')->all();
        ksort($goods);
        return [
            'resource' => $resource, 'name' => $names[$resource] ?? null,
            'goods' => array_map(fn ($r, $n) => ['resource' => $r, 'name' => $names[$r] ?? null, 'units' => round($n, 3)],
                array_keys($goods), $goods),
            'fuel' => round($fuel, 2),
            'tool_hours' => array_map(fn ($t, $h) => ['tool' => $t, 'name' => $names[$t] ?? null, 'hours' => round($h, 2)],
                array_keys($tools), $tools),
            'steps' => array_reverse($steps),
        ];
    }
}
