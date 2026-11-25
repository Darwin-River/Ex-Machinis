<?php

namespace Tests\Feature;

use App\Services\MarketBoard;
use App\Services\Release;
use App\Services\World;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The Market page's goods board (#721): one row per good, the cheapest
 * seller and best buyer at each place, the best deal and the 7-day change;
 * each good's whole order book on its own page. And the Found a startup
 * page's starter cards (#719).
 */
class MarketBoardTest extends TestCase
{
    use DatabaseTransactions;

    private function drone(string $email, string $company, int $place)
    {
        $user = User::create(['name' => 'Board Player', 'email' => $email, 'password' => bcrypt('secret-password')]);
        $agent = Release::release($user, $company, ': launched ;');
        DB::table('agents')->where('agent_id', $agent->agent_id)->update(['object_id' => $place]);
        return $agent;
    }

    private function event(int $type, int $drone, int $resource, int $quantity, int $credits = 0): void
    {
        DB::table('events')->insert(['event_type' => $type, 'action' => 0, 'logged' => 1, 'outcome' => 1, 'drone' => $drone,
            'resource' => $resource, 'installed' => 0, 'locked' => 0, 'new_quantity' => $quantity, 'new_credits' => $credits,
            'new_cargo' => 0, 'game_t' => World::gameNow()]);
    }

    /** Two sellers at two places and a buyer: the board row and the good's page */
    private function market(): array
    {
        // A good nobody else trades here
        $good = (int) DB::table('resources')->whereNotIn('id', DB::table('latest_orders')->select('resource'))
            ->whereNotIn('id', DB::table('trades')->select('resource'))->orderBy('id')->value('id');
        $earth = $this->drone('a@example.com', 'Earth Sellers', 3);
        $moon = $this->drone('b@example.com', 'Moon Sellers', 10);
        $buyer = $this->drone('c@example.com', 'Earth Buyers', 3);
        foreach ([[$earth, 9], [$moon, 7]] as [$seller, $price]) {
            $this->event(1, $seller->agent_id, $good, 50);          // holds 50
            $this->event(4, $seller->agent_id, $good, 0, $price);   // sells all of it
        }
        $this->event(5, $buyer->agent_id, $good, 100, 12);         // wants 100 at 12
        // Traded at 10 a unit this week, 8 the week before: +25%
        $now = World::gameNow();
        DB::table('trades')->insert([
            ['resource' => $good, 'units' => 10, 'credits' => 100, 'seller' => $earth->agent_id, 'buyer' => $buyer->agent_id,
             'location' => 3, 'game_t' => $now - 86400, 'tax' => 0],
            ['resource' => $good, 'units' => 10, 'credits' => 80, 'seller' => $earth->agent_id, 'buyer' => $buyer->agent_id,
             'location' => 3, 'game_t' => $now - 9 * 86400, 'tax' => 0],
        ]);
        Cache::forget('market-board');
        return [$good, $earth, $moon, $buyer];
    }

    public function testTheBoardHasARowForEachGood()
    {
        [$good, $earth, $moon, $buyer] = $this->market();
        $rows = MarketBoard::all();
        $this->assertSame(count($rows), count(array_unique(array_column($rows, 'resource'))), 'one row a good');
        $row = MarketBoard::row($good);
        $this->assertSame(['place' => 'Moon', 'price' => 7, 'units' => 50], $row['cheapest']);
        $this->assertSame(['place' => 'Earth', 'price' => 12, 'units' => 100], $row['best_bid']);
        $this->assertSame(5, $row['margin'], 'buy at the Moon for 7, sell at Earth for 12');
        $this->assertSame([7, 12], [$row['low'], $row['high']]);
        $earthRow = collect($row['places'])->firstWhere('place', 'Earth');
        $this->assertSame([9, 12], [$earthRow['ask'], $earthRow['bid']]);
        $this->assertSame(10, $row['units']);
        $this->assertSame(25, $row['change_percent']);
        $this->assertSame(intdiv($good, 1000), $row['tier']);

        // The chips: a tier shows only its goods; fuel only fuel
        foreach (MarketBoard::FILTER_TIERS as $chip => $tier) {
            foreach (MarketBoard::filtered($chip) as $r) {
                $this->assertSame($tier, $r['tier']);
            }
        }
        $this->assertNotContains($good, array_column(MarketBoard::filtered('fuel'), 'resource'));
        $this->assertContains($good, array_column(MarketBoard::filtered('traded'), 'resource'), 'traded this week');
    }

    /** #880: a startup trades only with the House, so the board can show the House's own prices */
    public function testTheStartupViewShowsTheHousesPrices()
    {
        [$good, $earth] = $this->market();
        // The Earth seller is the House: its 9 is what a startup can buy at, not the Moon player's 7
        $houseCo = (int) DB::table('agents')->where('agent_id', $earth->agent_id)->value('company_id');
        DB::table('companies')->where('company_id', $houseCo)->update(['house' => 1]);
        Cache::forget('market-board');
        $row = MarketBoard::row($good);
        $this->assertSame(7, $row['cheapest']['price'], 'every order still counts in the board');
        $this->assertSame(['place' => 'Earth', 'price' => 9, 'units' => 50], $row['house_cheapest']);
        $this->assertNull($row['house_best_bid'], 'only a player buys it');
        $this->get('/market?show=all&startup=1')->assertOk()->assertSee('aria-pressed="true"', false)
            ->assertSee('The House sells')->assertSee("a startup\n                trades only with the House", false);
        $this->get('/market?show=all')->assertOk()->assertSee('aria-pressed="false"', false)->assertSee('What a startup can trade at');
    }

    public function testTheMarketPageShowsTheBoard()
    {
        [$good] = $this->market();
        $name = DB::table('resources')->where('id', $good)->value('name');
        $html = $this->get('/market?show=all')->assertOk()
            ->assertSee('Where is each good cheapest, and where does it pay most?')
            ->assertSee('id="board"', false)
            ->assertSee('href="' . url('resources/' . $good) . '#order-book"', false)
            ->assertSee('Buy at Moon, sell at Earth')->assertSee('+5 a unit')
            ->assertSee('+25%')
            ->assertSee('aria-current="page"', false)
            ->assertSee('class="mk-select"', false)
            ->assertSee('Data: <code>GET /api/v1/orders?side=sell</code>', false)
            ->assertSee('href="' . url('market/house') . '"', false)
            ->assertDontSee('<th>Drone</th>', false)
            ->getContent();
        $this->assertStringContainsString(e($name), $html);
        // One row a good, not an order table a place
        $this->assertSame(count(MarketBoard::all()), substr_count($html, 'class="mk-row"'));
        // An unknown chip shows the most traded
        $this->get('/market?show=nonsense')->assertOk()->assertSee('Most traded');
    }

    public function testAGoodsPageHasItsWholeOrderBook()
    {
        [$good, $earth, $moon, $buyer] = $this->market();
        $html = $this->get('/resources/' . $good)->assertOk()
            ->assertSee('Who sells it, and who buys it?')
            ->assertSee('<th>Seller</th>', false)->assertSee('<th>Buyer</th>', false)
            ->assertSee($earth->name)->assertSee($moon->name)->assertSee($buyer->name)
            ->assertDontSee('resource-orders-table', false)
            ->getContent();
        // Cheapest seller first
        $this->assertLessThan(strpos($html, $earth->name), strpos($html, $moon->name));
        $book = MarketBoard::book($good);
        $this->assertSame([7, 9], array_column($book['sell'], 'price'));
        $this->assertSame([12], array_column($book['buy'], 'price'));
        $this->assertSame(100, $book['buy'][0]['units']);
    }

    public function testTheBoardIsCached()
    {
        [$good] = $this->market();
        $before = MarketBoard::row($good);
        DB::table('trades')->where('resource', $good)->delete();
        $this->assertSame($before, MarketBoard::row($good), 'the same for a minute');
        Cache::forget('market-board');
        $this->assertSame(0, MarketBoard::row($good)['units']);
    }

    /** #719: three starter cards, the Miner loaded, a short note with the rules folded */
    public function testTheStartAStartupPageOffersTheThreeStarters()
    {
        config(['forge.new_rules' => true]);
        $html = $this->get('/release')->assertOk()
            ->assertSee('<title>Found a startup - Ex-Machinis</title>', false)
            ->assertSee('id="needs-account"', false)   // a visitor makes an account first (#871)
            ->assertSee('id="startup-rules"', false)->assertSee('<summary>Rules</summary>', false)
            ->assertDontSee('alert-info', false)
            ->assertDontSee('Hello from orbit')
            ->getContent();
        $this->assertSame(4, substr_count($html, 'class="rl-card"'), 'the Shuttle (#1050), Miner, Refiner and Maker');
        foreach (['shuttle', 'miner', 'refiner', 'maker'] as $name) {
            $this->assertStringContainsString('data-starter="' . $name . '"', $html);
        }
        $this->assertStringNotContainsString('data-starter="trader"', $html);
        $this->assertMatchesRegularExpression('/data-starter="shuttle"\s+role="button" aria-pressed="true"/', $html, 'the Shuttle is picked');
        $this->assertStringContainsString(e(substr(Release::starter('shuttle'), 0, 50)), $html, 'its code in the editor');
        // The cards carry each starter's code for the editor
        $this->assertStringContainsString('window.exStarters = ', $html);

        $html = $this->get('/release?starter=maker')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-starter="maker"\s+role="button" aria-pressed="true"/', $html);
        $this->assertMatchesRegularExpression('/data-starter="miner"\s+role="button" aria-pressed="false"/', $html);
    }
}
