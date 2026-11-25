<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * #1034: the starters pursue a production objective and ask the game where
 * to pursue it. None names a place in its logic, so a new exchange, a new
 * season's sites or a crowded site needs no change of code. The simple Miner
 * and Maker (#1050, #1070), first drones in a few lines, name their route in
 * settings a player chooses from /try's tables; their logic names no place.
 */
class SiteAgnosticStartersTest extends TestCase
{
    private const PLACES = ['mercury', 'venus', 'earth', 'moon', 'mars', 'phobos', 'deimos', 'ceres'];

    /** The starters and, since #1037, the company designs */
    private static function designs(): array
    {
        return array_merge(glob(base_path('examples/starters/*.lua')), glob(base_path('examples/companies/*.lua')));
    }

    /** The code with its comments and strings taken out */
    private static function logic(string $src): string
    {
        $src = preg_replace('/--\[\[.*?\]\]/s', '', $src);
        $src = preg_replace('/"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'/', '""', $src);
        return preg_replace('/--[^\n]*/', '', $src);
    }

    public function testNoStarterOrDesignNamesAPlace()
    {
        $files = self::designs();
        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            $logic = self::logic((string) file_get_contents($file));
            // The simple drones on /try (#1050, #1070) name their route in their settings, which the tables
            // fill in: places are allowed there and nowhere else in their code
            if (str_starts_with(basename($file), 'simple_')) {
                $logic = preg_replace('/^(mine_at|buy_at|sell_at)\s*=[^\n]*$/m', '', $logic);
            }
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
            $this->assertStringNotContainsString('math.random', self::logic($src), $name);
            // Moves only for a clear gain, and chooses again when a site runs dry
            $this->assertMatchesRegularExpression('/^percent_better_to_move = \d+/m', $src, $name);
            $this->assertStringContainsString('choose_site(site)', $src, $name);
            $this->assertStringContainsString('function cheapest_fuel_stop()', $src, $name);
        }
    }

    public function testTheMakerAndTraderFindTheirMarketsFromTheGame()
    {
        $maker = (string) file_get_contents(base_path('examples/starters/maker.lua'));
        $this->assertStringContainsString('where_to_buy(metals_a1, metals_per_purchase, CREDITS_PER_HOUR_AWAY)', $maker);
        $this->assertStringContainsString('where_to_sell(rods,', $maker);
        $trader = (string) file_get_contents(base_path('examples/starters/trader.lua'));
        $this->assertStringContainsString('price_board(metal)', $trader);
        $this->assertStringContainsString('buying_at = choose_buying_place()', $trader);
    }

    /** The names say what the code does; comments are kept for why (#1034) */
    public function testTheStartersAndDesignsAreSelfDescribing()
    {
        foreach (self::designs() as $file) {
            $lines = array_filter(explode("\n", (string) file_get_contents($file)), fn ($l) => trim($l) !== '');
            $comments = array_filter($lines, fn ($l) => str_starts_with(ltrim($l), '--'));
            $this->assertLessThanOrEqual(0.1, count($comments) / count($lines),
                basename($file) . ': ' . count($comments) . ' of ' . count($lines) . ' lines are comments');
        }
    }
}
