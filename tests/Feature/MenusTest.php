<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * The menus by what a player is trying to do (#843): Play, Market,
 * Companies and Reference, each page in one of them and each one reachable;
 * a visitor's shorter Watch, Explore and Learn (#1045) reach them too
 */
class MenusTest extends TestCase
{
    use DatabaseTransactions;

    private const MENUS = [
        'nav-play' => ['try', 'release', 'startups/race', 'getting-started', 'market/shares/first', 'market/loans/first'],
        'nav-market' => ['market', 'market/house', 'market/history', 'market/shares', 'market/trade', 'dashboard'],
        'nav-companies' => ['leaderboard', 'players', 'companies', 'spacecraft', 'lineage', 'events', 'map'],
        'nav-reference' => ['manual', 'badges', 'technology', 'resources', 'protocols', 'queries', 'locations', 'blog'],
    ];

    /** A visitor's menus (#1045): Watch, Explore and Learn hold the same pages */
    private const VISITOR_MENUS = [
        'nav-explore' => ['market', 'market/house', 'market/history', 'market/shares', 'market/loans', 'market/trade', 'dashboard',
            'leaderboard', 'players', 'companies', 'startups/race', 'spacecraft', 'lineage', 'events', 'map'],
        'nav-learn' => ['try', 'getting-started', 'release', 'manual', 'blog', 'badges', 'technology', 'resources', 'protocols', 'queries', 'locations'],
    ];

    private function assertMenus(string $html, array $menus): void
    {
        foreach ($menus as $id => $pages) {
            $start = strpos($html, 'aria-labelledby="' . $id . '"');
            $this->assertNotFalse($start, "the $id menu is there");
            $menu = substr($html, $start, strpos($html, '</li>', $start) - $start);
            foreach ($pages as $page) {
                $this->assertStringContainsString('href="' . url($page) . '"', $menu, "$page is in $id");
                $this->get('/' . $page)->assertOk();
            }
        }
    }

    public function testAPlayersFourMenusHoldEveryPageAndEachPageOpens()
    {
        config(['forge.new_rules' => true]);
        $email = 'menu.reader@example.com';
        $u = \App\Services\Players::create('Menu Reader', 'long-enough', $email);
        \App\Services\Players::verify($u->user_id, sha1($email));
        $this->actingAs(\App\User::where('user_id', $u->user_id)->first());
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertMenus($html, self::MENUS);
        // The old menus are gone, and the raw order lists left the menus (their pages stay)
        foreach (['nav-world', 'nav-directory', 'nav-explore'] as $old) {
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

    public function testAVisitorsShorterMenusHoldThePagesToo()
    {
        config(['forge.new_rules' => true]);
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertMenus($html, self::VISITOR_MENUS);
        $this->assertStringContainsString('id="nav-watch"', $html);
        foreach (['nav-play', 'nav-market', 'nav-companies', 'nav-reference'] as $players) {
            $this->assertStringNotContainsString('id="' . $players . '"', $html);
        }
    }
}
