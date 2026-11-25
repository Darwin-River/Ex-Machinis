<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Routes for the simple drones on /try (#1050, #1070): for the Miner, each
 * ore a Mining Kit can dig and refine, the places to dig it and to sell what
 * it refines into (best); for the Maker, each good a Factory Kit can make
 * from goods the House sells, where to buy them and where to sell it
 * (making). Each with the round trip in game hours and the profit to expect,
 * from today's House prices, the recipes and the trip times. Beside the
 * drone's code, where choosing a row rewrites its settings.
 *
 * An estimate, and the page says so: it leaves out crowding (a crowded site
 * yields less), deposits running down, prices moving and what the House has
 * left to buy, and takes each dig at the place's abundance.
 */
class Routes
{
    /** The kit the Miner buys, and the tools it brings */
    public const KIT = 5901;

    /** The kit the Maker buys */
    public const FACTORY_KIT = 5902;

    /** Real seconds the table is kept */
    private const KEEP = 600;

    /** Rows {ore, ore_name, ore_lua, mine_at, mine_name, mine_lua, sell_at, sell_name, sell_lua, digs, goods: [{resource, name, lua, units, price}],
     *  hours, fuel, income, fuel_cost, profit, per_hour}, best per game hour first: the best $perOre routes of each ore */
    public static function best(int $perOre = 2): array
    {
        return Cache::remember('miner-routes:' . $perOre, self::KEEP, fn () => self::work($perOre));
    }

    public static function forget(): void
    {
        foreach ([1, 2, 3] as $n) {
            Cache::forget('miner-routes:' . $n);
            Cache::forget('maker-routes:' . $n);
        }
    }

    /**
     * The Maker's routes (#1070): rows {product, product_name, product_lua, makes, buy_at, buy_name, buy_lua,
     * sell_at, sell_name, sell_lua, inputs: [{resource, name, units, price}], cost, price, income, hours, fuel,
     * fuel_cost, profit, per_hour}, best per game hour first: the best $perProduct routes of each good. Only goods
     * whose batch (inputs and fuel) a new startup can pay for once it has its kit (forge.starting_credits)
     */
    public static function making(int $perProduct = 1): array
    {
        return Cache::remember('maker-routes:' . $perProduct, self::KEEP, fn () => self::makingWork($perProduct));
    }

    private static function makingWork(int $perProduct): array
    {
        $names = DB::table('resources')->pluck('name', 'id')->all();
        $kitTools = DB::table('resource_effects')->where('protocol', self::FACTORY_KIT + 20000)->where('event_type', 1)
            ->pluck('resource')->map(fn ($r) => (int) $r)->all();
        $acc = (float) (DB::table('game_clock')->where('id', 1)->value('travel_acceleration') ?? 0);
        if (!$kitTools || $acc <= 0) {
            return [];
        }
        $pos = DB::table('objects')->whereNotNull('x_coord')->get(['object_id', 'object_name', 'x_coord', 'y_coord', 'z_coord'])
            ->keyBy('object_id')->all();
        $flight = self::flightFuel();
        $tax = (int) (DB::table('house_rules')->where('id', 1)->value('sales_tax_permille') ?? 0);

        // What the House sells (its cheapest ask) and pays at each exchange, and what fuel costs there
        $stations = [];
        foreach (DB::table('stations')->pluck('object_id') as $obj) {
            $obj = (int) $obj;
            if (!isset($pos[$obj])) {
                continue;
            }
            $asks = [];
            foreach (HouseMarket::supply(null, $obj) as $g) {
                $r = (int) $g['resource'];
                $asks[$r] = isset($asks[$r]) ? min($asks[$r], (int) $g['price']) : (int) $g['price'];
            }
            $bids = [];
            foreach (HouseMarket::demand(null, $obj) as $d) {
                $bids[(int) $d['resource']] = (int) $d['price'];
            }
            if (isset($asks[Release::FUEL])) {
                $stations[$obj] = ['asks' => $asks, 'bids' => $bids, 'fuel' => $asks[Release::FUEL]];
            }
        }

        // What a new startup has left once it has bought its kit: a first batch must cost no more
        $kitPrice = collect(HouseMarket::supply(self::FACTORY_KIT, \App\Company::EARTH_OBJECT_ID))->min('price') ?: 2000;
        $budget = (int) config('forge.starting_credits') - (int) $kitPrice;

        // Every good a recipe makes (protocol = the good + 10000) with the Factory Kit's tools alone
        $products = DB::table('resource_effects')->where('drone', 0)->whereBetween('protocol', [12000, 19999])
            ->distinct()->pluck('protocol')->map(fn ($p) => (int) $p - 10000)->all();
        $rows = [];
        foreach ($products as $product) {
            $rec = self::recipe($product + 10000);
            if (!$rec || !$rec['inputs'] || array_diff($rec['tools'], $kitTools) || !isset($rec['outputs'][$product])
                || $product === Release::FUEL) {
                continue;
            }
            $makes = $rec['outputs'][$product];
            $routes = [];
            foreach ($stations as $buy => $b) {
                $inputs = [];
                $cost = 0;
                foreach ($rec['inputs'] as $r => $n) {
                    if (!isset($b['asks'][$r])) {
                        continue 2;   // the House here doesn't sell this input
                    }
                    $inputs[] = ['resource' => $r, 'name' => $names[$r] ?? (string) $r, 'units' => $n, 'price' => $b['asks'][$r]];
                    $cost += $n * $b['asks'][$r];
                }
                foreach ($stations as $sell => $st) {
                    $price = $st['bids'][$product] ?? 0;
                    if ($price <= 0) {
                        continue;
                    }
                    $income = intdiv($makes * $price * (1000 - $tax), 1000);
                    $trip = $buy === $sell ? 0.0 : self::tripHours($pos[$buy], $pos[$sell], $acc);
                    $hours = $rec['hours'] + 2 * $trip;
                    // Fuel: the making, a flight each way, a pull for each input and a push to sell
                    $fuel = $rec['fuel'] + ($buy === $sell ? 0 : 2 * $flight) + count($inputs) + 1;
                    $fuelCost = $fuel * $b['fuel'];
                    if ($cost + $fuelCost > $budget) {
                        continue 2;   // more than a new startup can spend on a batch
                    }
                    $profit = $income - $cost - $fuelCost;
                    $routes[] = ['product' => $product, 'product_name' => $names[$product] ?? (string) $product,
                        'product_lua' => self::luaName($product, 'resource'), 'makes' => $makes,
                        'buy_at' => $buy, 'buy_name' => $pos[$buy]->object_name, 'buy_lua' => self::luaName($buy, 'place'),
                        'sell_at' => $sell, 'sell_name' => $pos[$sell]->object_name, 'sell_lua' => self::luaName($sell, 'place'),
                        'inputs' => $inputs, 'cost' => $cost, 'price' => $price, 'income' => $income,
                        'hours' => round($hours, 1), 'fuel' => $fuel, 'fuel_cost' => $fuelCost, 'profit' => $profit,
                        'per_hour' => $hours > 0 ? round($profit / $hours, 1) : 0];
                }
            }
            usort($routes, fn ($a, $b) => [$b['per_hour'], $a['hours']] <=> [$a['per_hour'], $b['hours']]);
            $rows = array_merge($rows, array_slice(array_values(array_filter($routes, fn ($r) => $r['profit'] > 0)), 0, $perProduct));
        }
        usort($rows, fn ($a, $b) => $b['per_hour'] <=> $a['per_hour']);
        return $rows;
    }

    private static function work(int $perOre): array
    {
        $names = DB::table('resources')->pluck('name', 'id')->all();
        $kitTools = DB::table('resource_effects')->where('protocol', self::KIT + 20000)->where('event_type', 1)
            ->pluck('resource')->map(fn ($r) => (int) $r)->all();
        $acc = (float) (DB::table('game_clock')->where('id', 1)->value('travel_acceleration') ?? 0);
        if (!$kitTools || $acc <= 0) {
            return [];
        }
        $pos = DB::table('objects')->whereNotNull('x_coord')->get(['object_id', 'object_name', 'x_coord', 'y_coord', 'z_coord'])
            ->keyBy('object_id')->all();
        $flight = self::flightFuel();
        $tax = (int) (DB::table('house_rules')->where('id', 1)->value('sales_tax_permille') ?? 0);

        // What the House pays at each exchange, and what fuel costs there
        $stations = [];
        foreach (DB::table('stations')->pluck('object_id') as $obj) {
            $obj = (int) $obj;
            if (!isset($pos[$obj])) {
                continue;
            }
            $bids = [];
            foreach (HouseMarket::demand(null, $obj) as $d) {
                $bids[(int) $d['resource']] = (int) $d['price'];
            }
            $fuel = collect(HouseMarket::supply(Release::FUEL, $obj))->min('price');
            if ($fuel) {
                $stations[$obj] = ['bids' => $bids, 'fuel' => (int) $fuel];
            }
        }

        $rows = [];
        foreach (DB::table('abundancies')->where('multiplier', '>', 0)->distinct()->pluck('resource') as $ore) {
            $ore = (int) $ore;
            $mine = self::recipe($ore + 10000);
            $refine = self::recipe($ore + 20000);
            // Only what the Mining Kit's tools dig and refine
            if (!$mine || !$refine || array_diff(array_merge($mine['tools'], $refine['tools']), $kitTools)
                || !isset($refine['inputs'][$ore])) {
                continue;
            }
            $need = $refine['inputs'][$ore];
            $routes = [];
            foreach (DB::table('abundancies')->where('resource', $ore)->where('multiplier', '>', 0)->get(['location', 'multiplier']) as $site) {
                $at = (int) $site->location;
                if (!isset($pos[$at])) {
                    continue;
                }
                $yield = max(1, (int) $site->multiplier * max(1, $mine['outputs'][$ore] ?? 1));
                $digs = (int) ceil($need / $yield);
                foreach ($stations as $sell => $st) {
                    $goods = [];
                    $income = 0;
                    foreach ($refine['outputs'] as $r => $n) {
                        $price = $st['bids'][$r] ?? 0;
                        $goods[] = ['resource' => $r, 'name' => $names[$r] ?? (string) $r, 'lua' => self::luaName($r, 'resource'),
                            'units' => $n, 'price' => $price];
                        $income += intdiv($n * $price * (1000 - $tax), 1000);
                    }
                    if ($income <= 0) {
                        continue;
                    }
                    $tripHours = self::tripHours($pos[$at], $pos[$sell], $acc);
                    $hours = $digs * $mine['hours'] + $refine['hours'] + 2 * $tripHours;
                    $fuel = $digs * $mine['fuel'] + $refine['fuel'] + 2 * $flight + count($goods);
                    $fuelCost = $fuel * $st['fuel'];
                    $profit = $income - $fuelCost;
                    $routes[] = ['ore' => $ore, 'ore_name' => $names[$ore] ?? (string) $ore, 'ore_lua' => self::luaName($ore, 'resource'),
                        'mine_at' => $at, 'mine_name' => $pos[$at]->object_name, 'mine_lua' => self::luaName($at, 'place'),
                        'sell_at' => $sell, 'sell_name' => $pos[$sell]->object_name, 'sell_lua' => self::luaName($sell, 'place'),
                        'digs' => $digs, 'goods' => $goods, 'hours' => round($hours, 1), 'fuel' => $fuel,
                        'income' => $income, 'fuel_cost' => $fuelCost, 'profit' => $profit,
                        'per_hour' => $hours > 0 ? round($profit / $hours, 1) : 0];
                }
            }
            usort($routes, fn ($a, $b) => [$b['per_hour'], $a['hours']] <=> [$a['per_hour'], $b['hours']]);
            // The best routes, no two the same pair of places
            $rows = array_merge($rows, array_slice(array_values(array_filter($routes, fn ($r) => $r['profit'] > 0)), 0, $perOre));
        }
        usort($rows, fn ($a, $b) => $b['per_hour'] <=> $a['per_hour']);
        return $rows;
    }

    /** A protocol's tools, fuel, inputs and outputs (the acting drone's), and its game hours, or null */
    private static function recipe(int $protocol): ?array
    {
        $effects = DB::table('resource_effects')->where('protocol', $protocol)->where('drone', 0)->get();
        if ($effects->isEmpty()) {
            return null;
        }
        $out = ['tools' => [], 'inputs' => [], 'outputs' => [], 'fuel' => 0, 'hours' => 0.0];
        foreach ($effects as $e) {
            $r = (int) $e->resource;
            $q = (int) $e->quantity;
            $out['hours'] = max($out['hours'], (int) $e->time / 3600);
            if ((int) $e->event_type === 15 && $r > 0) {
                $out['tools'][] = $r;
            } elseif (in_array((int) $e->event_type, [1, 2], true) && $r > 0) {
                if ($r === Release::FUEL && (int) $e->deplete) {
                    $out['fuel'] += $q;
                } elseif ((int) $e->deplete) {
                    $out['inputs'][$r] = ($out['inputs'][$r] ?? 0) + $q;
                } else {
                    $out['outputs'][$r] = ($out['outputs'][$r] ?? 0) + $q;
                }
            }
        }
        $out['hours'] = max(1 / 60, round($out['hours'], 2));
        $out['tools'] = array_values(array_unique($out['tools']));
        return $out;
    }

    /** Fuel a flight burns (the travel protocol's), 5 when the data doesn't say */
    private static function flightFuel(): int
    {
        foreach (DroneTestDrive::statics()['protocols'] as $p) {
            if (!empty($p['travel']) && !empty($p['inputs'][Release::FUEL])) {
                return (int) $p['inputs'][Release::FUEL];
            }
        }
        return 5;
    }

    /** Game hours to fly between two places now, as travel does: 2·√(distance ÷ acceleration) */
    private static function tripHours(object $a, object $b, float $acc): float
    {
        $km = sqrt(($a->x_coord - $b->x_coord) ** 2 + ($a->y_coord - $b->y_coord) ** 2 + ($a->z_coord - $b->z_coord) ** 2);
        return round(max(1, 2 * sqrt($km * 1000 / $acc)) / 3600, 2);
    }

    /** The drone library's name for a place or resource, or null when it has none (then the code uses its number) */
    public static function luaName(int $id, string $kind): ?string
    {
        $places = ['earth' => 3, 'moon' => 10, 'mars' => 4, 'phobos' => 11, 'deimos' => 12, 'ceres' => 191];
        if ($kind === 'place') {
            return array_search($id, $places, true) ?: null;
        }
        $name = DB::table('resources')->where('id', $id)->value('name');
        if ($name === null) {
            return null;
        }
        // "Metals (A1)" -> metals_a1, "Wolfite (N)" -> wolfite: the library's own names, where it has one
        $lua = strtolower(preg_replace('/[^A-Za-z0-9]+/', '_', preg_replace('/\s*\(N\)$/', '', (string) $name)));
        $lua = trim($lua, '_');
        return in_array($lua, self::libraryNames(), true) ? $lua : null;
    }

    /** The names the drone library gives scripts (its `_G.name = ...` lines) */
    public static function libraryNames(): array
    {
        static $names = null;
        if ($names === null) {
            preg_match_all('/^_G\.([a-z0-9_]+) = /m', (string) @file_get_contents(base_path('game-engine/src/lua_library.lua')), $m);
            $names = $m[1];
        }
        return $names;
    }
}
