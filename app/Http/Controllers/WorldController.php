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

    /** Market history (#380): charts of prices and volumes, drawn from /api/v1/history */
    public function history()
    {
        // The resources traded in the last year, most credits first: the choices
        $resources = DB::table('trades AS t')->join('resources AS r', 'r.id', '=', 't.resource')
            ->where('t.game_t', '>=', World::gameNow() - 365 * 86400)->groupBy('t.resource', 'r.name')
            ->orderByRaw('SUM(t.credits) DESC')->get(['t.resource AS id', 'r.name']);
        // Each place keeps one colour: the places trades happen at, in ID order
        $places = DB::table('trades')->whereNotNull('location')->distinct()->orderBy('location')->pluck('location')->map(fn ($id) => (int) $id);
        $epoch = (int) DB::table('game_clock')->where('id', 1)->value('epoch');
        $today = intdiv(max(0, World::gameNow() - $epoch), 86400);
        return view('world.history', compact('resources', 'places', 'today'));
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
            ->whereNull('a.wrecked_at')->whereNull('a.station_id')->groupBy('a.object_id', 'o.object_name')
            ->orderByRaw('COUNT(*) DESC')->limit(12)
            ->get([DB::raw('MAX(a.agent_id) AS drone'), 'o.object_name AS place']);
        $drone = (int) $request->query('drone', $vantages->first()->drone ?? 0);
        // A station drone's own orders are left out of what it sees, so asking
        // as one shows its station's prices as 0 (round 30): the page warns
        $stations = DB::table('agents')->whereNotNull('station_id')->pluck('agent_id')->map(fn ($id) => (int) $id)->all();
        return view('world.machine', compact('defs', 'start', 'word', 'vantages', 'drone', 'stations'));
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
