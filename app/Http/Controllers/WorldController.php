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
    public function market()
    {
        $trades = World::trades();
        $sells = World::orders(4);
        $buys = World::orders(5);
        return view('world.market', compact('trades', 'sells', 'buys'));
    }

    /** The leaderboard (#324 step 18): net wealth, all time and Rising */
    public function leaderboard()
    {
        Leaderboard::snapshotIfDue();
        $allTime = Leaderboard::allTime();
        $rising = Leaderboard::rising();
        $kit = Leaderboard::kitValue(Leaderboard::prices());
        return view('world.leaderboard', compact('allTime', 'rising', 'kit'));
    }

    /** The Machine view (#324 step 19): the VM, stepped in the browser */
    public function machine(\Illuminate\Http\Request $request)
    {
        $defs = (string)$request->query('defs', ": square ( n -- n*n ) dup * ;\n: cube ( n -- n*n*n ) dup square * ;");
        // An empty starting stack stays empty (it arrives as null)
        $start = $request->exists('stack') ? (string)$request->query('stack') : '3';
        $word = (string)$request->query('word', 'cube');
        // Live data (#380): a working drone at each place to ask as
        $vantages = \Illuminate\Support\Facades\DB::table('agents as a')->join('objects as o', 'o.object_id', '=', 'a.object_id')
            ->whereNull('a.wrecked_at')->groupBy('a.object_id', 'o.object_name')
            ->orderByRaw('COUNT(*) DESC')->limit(12)
            ->get([DB::raw('MAX(a.agent_id) AS drone'), 'o.object_name AS place']);
        $drone = (int) $request->query('drone', $vantages->first()->drone ?? 0);
        return view('world.machine', compact('defs', 'start', 'word', 'vantages', 'drone'));
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
            'id' => 'required|integer', 'drone' => 'required|integer|min:1', 'size' => 'required|integer|min:0|max:65535',
            'v' => 'array|max:9', 'v.*' => 'integer|min:-32768|max:65535', 's' => 'array|max:4', 's.*' => 'nullable|string|max:255',
        ]);
        if (!DB::table('agents')->where('agent_id', $data['drone'])->exists()) {
            return response()->json(['error' => 'There is no drone ' . $data['drone'] . '.'], 422);
        }
        return response()->json(\App\Services\LiveQuery::run((int) $data['id'], (int) $data['drone'], (int) $data['size'],
            array_map('intval', $data['v'] ?? []), $data['s'] ?? []));
    }

    public function map()
    {
        $planets = World::map();
        $places = World::places();
        return view('world.map', compact('planets', 'places'));
    }
}
