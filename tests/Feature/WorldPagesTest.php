<?php

namespace Tests\Feature;

use App\Services\Release;
use App\Services\World;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The public view of the world (#324 step 17): the Home page's feed, the
 * Market page and the Map.
 */
class WorldPagesTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
    }

    private function release(string $email, string $company)
    {
        $user = User::create(['name' => 'Forge Player', 'email' => $email, 'password' => bcrypt('secret-password')]);
        return Release::release($user, $company, ': launched ;');
    }

    public function testHomeFeedTellsWhatHappened()
    {
        $agent = $this->release('a@example.com', 'Feed Testers');
        DB::table('agents')->where('agent_id', $agent->agent_id)->update(['wrecked_at' => World::gameNow(), 'wreck_cause' => 'fuel']);
        $this->get('/')->assertOk()->assertSee("What's happening", false)
            ->assertSee('Feed Testers released a new drone, ' . $agent->name)
            ->assertSee($agent->name . ' became a wreck: it ran out of fuel.');
        // A broadcast (#380) is in the feed for all to see
        DB::table('broadcasts')->insert(['drone' => $agent->agent_id, 'company_id' => $agent->company_id, 'object_id' => 3,
            'game_t' => World::gameNow(), 'body' => 'Fuel wanted at Phobos']);
        $this->get('/')->assertOk()->assertSee($agent->name . ' broadcast: &quot;Fuel wanted at Phobos&quot;', false);
    }

    public function testMarketShowsOrdersAndTradesButNoHouseInformation()
    {
        $seller = $this->release('a@example.com', 'Sellers');
        $buyer = $this->release('b@example.com', 'Buyers');
        // A station whose price list players must not see (#380): the House
        // trades through station drones, like any other drone
        DB::table('stations')->insert(['id' => 901, 'name' => 'Test Depot', 'object_id' => 3, 'budget_cap' => 5000, 'refill_per_day' => 5000]);
        $resource = (int) DB::table('resources')->value('id');   // any resource the test data has
        DB::table('station_goods')->insert(['station_id' => 901, 'resource' => $resource, 'side' => 1, 'price' => 20, 'daily_units' => 200, 'from_day' => 0]);
        DB::table('trades')->insert(['resource' => $resource, 'units' => 10, 'credits' => 90, 'seller' => $seller->agent_id,
            'buyer' => $buyer->agent_id, 'location' => 3, 'game_t' => World::gameNow()]);
        $this->get('/market')->assertOk()->assertSee('Order book')->assertSee($seller->name)->assertSee($buyer->name)
            ->assertDontSee('Test Depot')->assertDontSee('House stations');
    }

    public function testMarketHistoryChartsTradedResources()
    {
        $seller = $this->release('a@example.com', 'Sellers');
        $buyer = $this->release('b@example.com', 'Buyers');
        $resource = DB::table('resources')->first();
        DB::table('trades')->insert(['resource' => $resource->id, 'units' => 10, 'credits' => 90, 'seller' => $seller->agent_id,
            'buyer' => $buyer->agent_id, 'location' => 3, 'game_t' => World::gameNow()]);
        $this->get('/market/history')->assertOk()->assertSee('Market history')->assertSee('Price a unit')
            ->assertSee('<option value="' . $resource->id . '">' . e($resource->name) . '</option>', false);
        $this->get('/market')->assertOk()->assertSee(url('market/history'));
    }

    public function testMarketShowsTheBestOrderForEveryResource()
    {
        // Round 30: a plain top 25 by resource ID filled up with fuel, ores
        // and metals, so nobody saw the rod buyer. Three buyers bid on every
        // resource below rods, and one bids on rods
        $drones = [$this->release('a@example.com', 'Bidders A'), $this->release('b@example.com', 'Bidders B'),
                   $this->release('c@example.com', 'Bidders C')];
        $low = DB::table('resources')->where('id', '<', 3011)->orderBy('id')->pluck('id');
        $rows = [];
        foreach ($drones as $k => $drone) {
            foreach ($low as $resource) {
                $rows[] = ['event_type' => 5, 'action' => 0, 'outcome' => 1, 'drone' => $drone->agent_id, 'resource' => $resource,
                           'new_quantity' => 50, 'new_credits' => 10 + $k, 'game_t' => World::gameNow()];
            }
        }
        $rows[] = ['event_type' => 5, 'action' => 0, 'outcome' => 1, 'drone' => $drones[0]->agent_id, 'resource' => 3011,
                   'new_quantity' => 50, 'new_credits' => 225, 'game_t' => World::gameNow()];
        DB::table('events')->insert($rows);

        $orders = World::orders(5);
        $keys = array_map(fn ($o) => $o['resource'] . '@' . $o['place'], $orders);
        $this->assertSame(count($keys), count(array_unique($keys)), 'one order for each resource at each place');
        $rods = array_values(array_filter($orders, fn ($o) => (int) $o['resource'] === 3011));
        $this->assertCount(1, $rods, 'the rod buyer is listed');
        $this->assertSame(225, (int) $rods[0]['price']);
        // Where only these three bid, the best of them is the one shown
        $ours = array_filter($orders, fn ($o) => (int) $o['drone'] === $drones[2]->agent_id);
        $this->assertNotEmpty($ours, 'the highest bidder is shown');
        $this->assertEmpty(array_filter($orders, fn ($o) => (int) $o['drone'] === $drones[1]->agent_id), 'a lower bid is not');
        $this->get('/market')->assertOk()->assertSee($drones[0]->name)->assertSee($drones[2]->name)->assertDontSee($drones[1]->name);
    }

    public function testMapShowsWhereTheDronesAre()
    {
        $agent = $this->release('a@example.com', 'Mappers');
        $this->get('/map')->assertOk()->assertSee('Earth')->assertSee('<svg', false);
        $earth = collect(World::map())->firstWhere('id', 3);
        $this->assertGreaterThanOrEqual(1, $earth['working']);
        $this->assertTrue($earth['x'] >= 0 && $earth['x'] <= 600 && $earth['y'] >= 0 && $earth['y'] <= 600);
    }

    public function testALocationShowsWhatIsLeftInItsDeposits()
    {
        // One deposit worked a game day ago (100 left, refilling 400 a day),
        // one nobody has worked (full)
        DB::table('deposits')->where('object_id', 3)->delete();
        DB::table('deposit_state')->where('object_id', 3)->delete();
        DB::table('deposits')->insert([
            ['object_id' => 3, 'resource' => 1100, 'capacity' => 2000, 'refill_per_day' => 400],
            ['object_id' => 3, 'resource' => 1130, 'capacity' => 2000, 'refill_per_day' => 400],
        ]);
        DB::table('deposit_state')->insert(['object_id' => 3, 'resource' => 1100, 'amount' => 100,
            'refilled_t' => World::gameNow() - 86400]);

        $deposits = collect(World::deposits(3))->keyBy('resource');
        $this->assertEqualsWithDelta(500, $deposits[1100]['left'], 2);
        $this->assertSame(2000, $deposits[1130]['left']);

        $this->get('/locations/3')->assertOk()->assertSee('Deposits')->assertSee('Refills a day');
    }
}
