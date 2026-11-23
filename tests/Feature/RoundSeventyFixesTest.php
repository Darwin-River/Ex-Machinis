<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Round 70's fixes to the drones' side of the market: buy_here's pulls
 * after a -17 (#710), and the unchanged Miner and Maker starters maturing
 * a startup (#713). The House's own buying is in HouseMarketTest (#691)
 * and house_supply's freshness in its testHouseSupplyShowsStockAndRestocking.
 */
class RoundSeventyFixesTest extends TestCase
{
    private function source(string $path): string
    {
        return (string) file_get_contents(base_path($path));
    }

    /** #710: buy_here never pulls fuel one at a time (the -17 cap it halved for is retired: #923) */
    public function testBuyHerePullsFuelFiveAtLeast()
    {
        $lib = $this->source('game-engine/src/lua_library.lua');
        $this->assertStringNotContainsString('cap = 1; failed = r', $lib, 'no more one unit at a time');
        $this->assertStringContainsString('local least = resource == fuel and 5 or 1', $lib);
        $this->assertStringNotContainsString('r == -17', $lib, 'no daily cap to halve for');
        $this->assertStringContainsString(str_replace(['\\', '"'], ['\\\\', '\\"'], 'local least = resource == fuel and 5 or 1'),
            $this->source('game-engine/src/lua_library.c'), 'the engine\'s copy is rebuilt');
    }

    /** #713: the Miner sells where the House pays most, and refuels instead of resting dry */
    public function testTheMinerSellsToTheBestBidderAndRefuels()
    {
        foreach (['starters/miner'] as $name) {
            $src = $this->source("examples/$name.lua");
            $this->assertStringNotContainsString('if units(fuel) < 15 then wait(rest); return end', $src, $name);
            $this->assertStringContainsString('if units(fuel) < low_fuel then refuel(); return end', $src, $name);
        }
        $miner = $this->source('examples/starters/miner.lua');
        $this->assertStringContainsString('function best_market(except)', $miner);
        $this->assertStringContainsString('house_demand(metal)', $miner);
        $this->assertStringContainsString('markets = {moon, mars, earth}', $miner);
        $this->assertMatchesRegularExpression('/^haul_at = 60/m', $miner);
        $this->assertStringContainsString('local where = best_market() or market()', $miner);
    }

    /** #713: a startup Maker sells to the House's bids, and a broke one rests */
    public function testTheMakerSellsToTheHouseAndRestsWhenBroke()
    {
        $maker = $this->source('examples/starters/maker.lua');
        $this->assertStringContainsString('function house_rods()', $maker);
        $this->assertStringContainsString('if house_only() then return house_rods() == location() end', $maker);
        $this->assertStringContainsString('if house_only() then return house_rods() end', $maker);
        // Clean code (#849): the 60 and 120 are named
        $this->assertMatchesRegularExpression('/if spare\(\) < CREDITS_FOR_METALS then\s+set_status\("short of credits for metals"\)\s+wait\(2 \* HOUR\)/', $maker);
        $this->assertStringContainsString('local CREDITS_FOR_METALS = 60', $maker);
        $this->assertStringContainsString('local HOUR = 60', $maker);
    }
}
