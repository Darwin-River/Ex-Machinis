<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * #1034: the starters pursue a production objective and ask the game where
 * to pursue it. None names a place in its logic, so a new exchange, a new
 * season's sites or a crowded site needs no change of code.
 */
class SiteAgnosticStartersTest extends TestCase
{
    private const PLACES = ['mercury', 'venus', 'earth', 'moon', 'mars', 'phobos', 'deimos', 'ceres'];

    /** The code with its comments and strings taken out */
    private static function logic(string $src): string
    {
        $src = preg_replace('/--\[\[.*?\]\]/s', '', $src);
        $src = preg_replace('/"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'/', '""', $src);
        return preg_replace('/--[^\n]*/', '', $src);
    }

    public function testNoStarterNamesAPlace()
    {
        $files = glob(base_path('examples/starters/*.lua'));
        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            $logic = self::logic((string) file_get_contents($file));
            foreach (self::PLACES as $place) {
                $this->assertDoesNotMatchRegularExpression('/\b' . $place . '\b/', $logic,
                    basename($file) . " names $place: ask the game where instead");
            }
        }
    }

    public function testTheMinersChooseTheirSiteFromTheGameAndSpreadOut()
    {
        foreach (['miner', 'refiner'] as $name) {
            $src = (string) file_get_contents(base_path("examples/starters/$name.lua"));
            $this->assertStringContainsString('best_sites(ore)', $src, $name);
            $this->assertStringContainsString('function choose_site(except)', $src, $name);
            // Spread by drone number, not math.random (a rebuilt drone would roll differently)
            $this->assertStringContainsString('good[me() % #good + 1]', $src, $name);
            $this->assertStringNotContainsString('math.random', $src, $name);
            // Moves only for a clear gain, and chooses again when a site runs dry
            $this->assertMatchesRegularExpression('/^move_margin = \d+/m', $src, $name);
            $this->assertStringContainsString('choose_site(site)', $src, $name);
            $this->assertStringContainsString('function fuel_stop()', $src, $name);
        }
    }

    public function testTheMakerAndTraderFindTheirMarketsFromTheGame()
    {
        $maker = (string) file_get_contents(base_path('examples/starters/maker.lua'));
        $this->assertStringContainsString('where_to_buy(metals_a1, batch, HOUR_AWAY_COST)', $maker);
        $this->assertStringContainsString('where_to_sell(rods,', $maker);
        $trader = (string) file_get_contents(base_path('examples/starters/trader.lua'));
        $this->assertStringContainsString('price_board(metal)', $trader);
        $this->assertStringContainsString('buying_at = choose_buying_place()', $trader);
    }
}
