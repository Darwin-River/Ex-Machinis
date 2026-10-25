<?php

namespace Tests\Feature;

use App\Services\Release;
use App\Services\World;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
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
        Mail::fake();
        config(['forge.email' => false, 'forge.invite_only' => true]);
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
    }

    public function testStationPriceHalvesForEachDaysWorth()
    {
        $this->assertSame(20, World::buyPrice(20, 0, 200));
        $this->assertSame(10, World::buyPrice(20, 200, 200));
        $this->assertSame(5, World::buyPrice(20, 400, 200));
        $this->assertSame(1, World::buyPrice(20, 100000, 200));
    }

    public function testMarketShowsStationsOrdersAndTrades()
    {
        $seller = $this->release('a@example.com', 'Sellers');
        $buyer = $this->release('b@example.com', 'Buyers');
        DB::table('stations')->insert(['id' => 901, 'name' => 'Test Depot', 'object_id' => 3, 'budget_cap' => 5000, 'refill_per_day' => 5000]);
        $resource = (int) DB::table('resources')->value('id');   // any resource the test data has
        DB::table('station_goods')->insert(['station_id' => 901, 'resource' => $resource, 'side' => 1, 'price' => 20, 'daily_units' => 200, 'from_day' => 0]);
        DB::table('trades')->insert(['resource' => $resource, 'units' => 10, 'credits' => 90, 'seller' => $seller->agent_id,
            'buyer' => $buyer->agent_id, 'location' => 3, 'game_t' => World::gameNow()]);
        $this->get('/market')->assertOk()->assertSee('Test Depot')->assertSee('Price now')
            ->assertSee('<polyline', false)->assertSee($seller->name)->assertSee($buyer->name);
    }

    public function testMapShowsWhereTheDronesAre()
    {
        $agent = $this->release('a@example.com', 'Mappers');
        $this->get('/map')->assertOk()->assertSee('Earth')->assertSee('<svg', false);
        $earth = collect(World::map())->firstWhere('id', 3);
        $this->assertGreaterThanOrEqual(1, $earth['working']);
        $this->assertTrue($earth['x'] >= 0 && $earth['x'] <= 600 && $earth['y'] >= 0 && $earth['y'] <= 600);
    }
}
