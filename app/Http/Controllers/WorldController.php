<?php

namespace App\Http\Controllers;

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
        $stations = World::stations();
        $trades = World::trades();
        $sells = World::orders(4);
        $buys = World::orders(5);
        return view('world.market', compact('stations', 'trades', 'sells', 'buys'));
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

    public function map()
    {
        $planets = World::map();
        $places = World::places();
        return view('world.map', compact('planets', 'places'));
    }
}
