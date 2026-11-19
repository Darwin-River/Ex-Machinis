<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * The menus by what a player is trying to do (#843): Play, Market,
 * Companies and Reference, each page in one of them and each one reachable
 */
class MenusTest extends TestCase
{
    use DatabaseTransactions;

    private const MENUS = [
        'nav-play' => ['release', 'startups/race', 'getting-started'],
        'nav-market' => ['market', 'market/house', 'market/history', 'market/shares', 'market/trade', 'dashboard'],
        'nav-companies' => ['leaderboard', 'players', 'companies', 'spacecraft', 'lineage', 'events', 'map'],
        'nav-reference' => ['manual', 'technology', 'resources', 'protocols', 'queries', 'locations', 'blog'],
    ];

    public function testFourMenusHoldEveryPageAndEachPageOpens()
    {
        config(['forge.new_rules' => true]);
        $html = $this->get('/')->assertOk()->getContent();
        foreach (self::MENUS as $id => $pages) {
            $start = strpos($html, 'aria-labelledby="' . $id . '"');
            $this->assertNotFalse($start, "the $id menu is there");
            $menu = substr($html, $start, strpos($html, '</li>', $start) - $start);
            foreach ($pages as $page) {
                $this->assertStringContainsString('href="' . url($page) . '"', $menu, "$page is in $id");
                $this->get('/' . $page)->assertOk();
            }
        }
        // The old menus are gone, and the raw order lists left the menus (their pages stay)
        foreach (['nav-learn', 'nav-world', 'nav-directory'] as $old) {
            $this->assertStringNotContainsString('id="' . $old . '"', $html);
        }
        $nav = substr($html, 0, strpos($html, '</nav>'));
        $this->assertStringNotContainsString('href="' . url('buys') . '"', $nav);
        $this->get('/buys')->assertOk();
        // The market menu lists its pages in the order of the market's own tabs
        $tabs = file_get_contents(resource_path('views/partials/market-tabs.blade.php'));
        $order = fn ($text) => array_values(array_filter(['market/house', 'market/history', 'market/shares', 'market/trade'],
            fn ($p) => str_contains($text, $p)));
        $this->assertSame($order($tabs), $order($nav));
    }
}
