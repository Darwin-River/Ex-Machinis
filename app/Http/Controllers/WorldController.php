<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

use App\Services\Leaderboard;
use App\Services\World;

/**
 * The Market and Map pages (#324 step 17); the Home page's feed is in
 * HomeController
 */
class WorldController extends Controller
{
    public function market(\Illuminate\Http\Request $request)
    {
        $trades = World::trades();
        $sells = World::orders(4);
        $buys = World::orders(5);
        // One resource's book, or all (#591: the whole book ran to 600 rows)
        $bookResources = collect($sells)->merge($buys)->pluck('resource_name', 'resource')->sort()->all();
        $bookResource = is_numeric($request->query('resource')) ? (int) $request->query('resource') : null;
        if ($bookResource !== null) {
            $sells = array_values(array_filter($sells, fn ($o) => (int) $o['resource'] === $bookResource));
            $buys = array_values(array_filter($buys, fn ($o) => (int) $o['resource'] === $bookResource));
        }
        // The House's demand (#550): one good everywhere, or the bids closest to full
        $houseResource = is_numeric($request->query('house')) ? (int) $request->query('house') : null;
        $demand = \App\Services\HouseMarket::demand($houseResource);
        $houseGoods = collect($demand)->pluck('resource_name', 'resource')->sort()->all();
        if ($houseResource === null) {
            usort($demand, fn ($a, $b) => [$a['left'] / $a['daily'], $a['resource']] <=> [$b['left'] / $b['daily'], $b['resource']]);
            $demand = array_slice($demand, 0, 30);
        }
        $fuel = World::fuelForSale();
        return view('world.market', compact('trades', 'sells', 'buys', 'demand', 'houseGoods', 'houseResource',
            'bookResources', 'bookResource', 'fuel'));
    }

    /** Market history (#380): charts of prices and volumes, drawn from /api/v1/history */
    public function history()
    {
        // The resources traded in the last year, most credits first: the choices
        $resources = DB::table('trades AS t')->join('resources AS r', 'r.id', '=', 't.resource')
            ->where('t.game_t', '>=', World::gameNow() - 365 * 86400)->groupBy('t.resource', 'r.name')
            ->orderByRaw('SUM(t.credits) DESC')->get(['t.resource AS id', 'r.name']);
        // Each place keeps one colour: the places trades happen at, in ID order
        ['places' => $places, 'today' => $today] = \App\Services\MarketHistory::frame();
        return view('world.history', compact('resources', 'places', 'today'));
    }

    /** The stock exchange (#484): every company's shares, what's for sale, and the latest trades */
    public function shares()
    {
        $exchange = \App\Services\Shares::exchange();
        return view('world.shares', compact('exchange'));
    }

    /** The leaderboard (#324 step 18): net wealth, all time and Rising */
    public function leaderboard(\Illuminate\Http\Request $request)
    {
        Leaderboard::snapshotIfDue();
        // Only companies up to this many game days old (#548): kept for old links
        $maxAge = is_numeric($request->query('max_age')) ? max(0, (float) $request->query('max_age')) : null;
        // Every company at the same age (#614), from its history
        $age = is_numeric($request->query('age')) ? min(100000, max(0, round((float) $request->query('age'), 1))) : null;
        $board = Leaderboard::wealth();
        $atAge = $age === null ? null : Leaderboard::atAge($age, 100, $board);
        $allTime = Leaderboard::allTime(50, $maxAge);
        $rising = Leaderboard::rising(50, $maxAge);
        $kit = Leaderboard::kitValue(Leaderboard::prices());
        return view('world.leaderboard', compact('allTime', 'rising', 'kit', 'maxAge', 'age', 'atAge'));
    }

    /** The game's queries: how many parameters each takes (#380) */
    public function machineQueries()
    {
        return response()->json(\App\Services\LiveQuery::catalog());
    }

    /** The game's protocols: how many parameters each takes (#380) */
    public function machineProtocols()
    {
        return response()->json(\App\Services\LiveQuery::protocols());
    }

    /**
     * Runs one of the game's queries as a drone would, for the Machine
     * page's live data (#380): the rows as the bytes a drone's memory gets
     */
    public function machineQuery(\Illuminate\Http\Request $request)
    {
        $data = $request->validate([
            'id' => 'required|integer', 'drone' => 'required|integer|min:1', 'size' => 'integer|min:0|max:65535',
            'v' => 'array|max:9', 'v.*' => 'integer|min:-32768|max:65535', 's' => 'array|max:4', 's.*' => 'nullable|string|max:255',
        ]);
        if (!DB::table('agents')->where('agent_id', $data['drone'])->exists()) {
            return response()->json(['error' => 'There is no drone ' . $data['drone'] . '.'], 422);
        }
        return response()->json(\App\Services\LiveQuery::run((int) $data['id'], (int) $data['drone'], (int) ($data['size'] ?? 65535),
            array_map('intval', $data['v'] ?? []), $data['s'] ?? []));
    }

    public function map()
    {
        $planets = World::map();
        $places = World::places();
        return view('world.map', compact('planets', 'places'));
    }
}
