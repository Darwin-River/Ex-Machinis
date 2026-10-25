<?php

namespace App\Http\Controllers;

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

    public function map()
    {
        $planets = World::map();
        $places = World::places();
        return view('world.map', compact('planets', 'places'));
    }
}
