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
