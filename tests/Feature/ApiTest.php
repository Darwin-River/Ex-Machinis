<?php

namespace Tests\Feature;

use App\Services\Release;
use App\Services\World;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The JSON API (#380): what the website shows, for players' tools and AI
 * agents, with no account and no House information.
 */
class ApiTest extends TestCase
{
    use DatabaseTransactions;

    private function release(string $company, string $script = ': launched ;')
    {
        $user = User::create(['name' => 'Forge Player', 'email' => uniqid() . '@example.com', 'password' => bcrypt('secret-password')]);
        return Release::release($user, $company, $script);
    }

    public function testClockSaysTheGameDay()
    {
        $this->getJson('/api/v1/clock')->assertOk()->assertJsonStructure(['game_time', 'game_day', 'when', 'world_full', 'releases_waiting',
            'real_seconds_per_game_day', 'engine' => ['running', 'last_seen_seconds_ago', 'turns_per_minute', 'behind_seconds']]);
    }

    public function testErrorsAreJsonWhateverTheRequestAccepts()
    {
        // #380 round 35: a server error came back as an HTML page
        $this->get('/api/v1/companies/999999999')->assertNotFound()->assertJsonStructure(['error']);
        \Illuminate\Support\Facades\Route::get('/api/v1/boom-test', fn () => throw new \RuntimeException('secret detail'));
        $this->get('/api/v1/boom-test')->assertStatus(500)->assertJsonStructure(['error'])
            ->assertDontSee('secret detail')->assertDontSee('<html', false);
    }

    public function testANewDroneSaysItIsLoading()
    {
        // #380 round 35: a drone waiting to load said "running its script"
        $agent = $this->release('Loading Co', "function launched()\n  wait(60)\nend\n");
        $this->getJson('/api/v1/drones/' . $agent->agent_id)->assertOk()
            ->assertJsonPath('status', 'loading its script (1 piece to go)');
    }

    public function testClockSaysWhenTheEngineHasStopped()
    {
        // #380 round 35: the world froze for three game days, and players took it for a slow drone
        DB::table('game_clock')->updateOrInsert(['id' => 1],
            ['real_anchor' => time(), 'game_anchor' => time(), 'scale' => 600, 'load_checked_at' => time() - 20]);
        $this->getJson('/api/v1/clock')->assertJsonPath('engine.running', true);
        DB::table('game_clock')->where('id', 1)->update(['load_checked_at' => time() - 600]);
        $this->getJson('/api/v1/clock')->assertJsonPath('engine.running', false)->assertJsonPath('engine.last_seen_seconds_ago', 600);
    }

    public function testACompanyAndItsDrones()
    {
        $agent = $this->release('Api Testers');
        $byName = $this->getJson('/api/v1/companies?name=' . urlencode('Api Testers'))->assertOk()
            ->assertJsonPath('name', 'Api Testers')->assertJsonPath('drones.0.id', (int) $agent->agent_id)->json();
        $this->getJson('/api/v1/companies/' . $byName['id'])->assertOk()->assertJsonPath('name', 'Api Testers');
        $this->getJson('/api/v1/companies?name=Nobody%20Here')->assertNotFound()->assertJsonStructure(['error']);
    }

    public function testADroneShowsWhatItCarriesAndSays()
    {
        $agent = $this->release('Drone Watchers', ": launched ( -- ) ;\n\\ my script");
        DB::table('drone_messages')->insert(['agent_id' => $agent->agent_id, 'subject' => 'Report', 'body' => 'all well',
            'game_t' => World::gameNow() - 10]);
        $this->getJson('/api/v1/drones/' . $agent->agent_id)->assertOk()
            ->assertJsonPath('id', (int) $agent->agent_id)
            ->assertJsonPath('company.name', 'Drone Watchers')
            ->assertJsonPath('messages.0.body', 'all well')
            ->assertJsonStructure(['place', 'status', 'fuel', 'cargo', 'timeline', 'trades', 'script']);
        $this->getJson('/api/v1/drones/999999999')->assertNotFound();
    }

    public function testOrdersListEveryOpenOrderAndFilter()
    {
        $a = $this->release('Bidder One');
        $b = $this->release('Bidder Two');
        $resource = (int) DB::table('resources')->value('id');
        foreach ([[$a, 10], [$b, 12]] as [$drone, $price]) {
            DB::table('events')->insert(['event_type' => 5, 'action' => 0, 'outcome' => 1, 'drone' => $drone->agent_id,
                'resource' => $resource, 'new_quantity' => 50, 'new_credits' => $price, 'game_t' => World::gameNow()]);
        }
        $orders = $this->getJson('/api/v1/orders?resource=' . $resource)->assertOk()->json();
        $ours = array_values(array_filter($orders, fn ($o) => in_array($o['drone'], [(int) $a->agent_id, (int) $b->agent_id], true)));
        $this->assertCount(2, $ours, 'both bids, not only the best');
        $this->assertSame(12, $ours[0]['price'], 'best first');
        $this->assertSame([], $this->getJson('/api/v1/orders?side=sell&resource=' . $resource . '&place=Nowhere')->json());
    }

    public function testReleaseChecksAndReleasesAScript()
    {
        $this->postJson('/api/v1/check', ['script' => "variable ask\n: launched ;"])->assertOk()
            ->assertJsonPath('ok', true)->assertJson(['warning' => true]);
        $this->postJson('/api/v1/check', ['script' => ': go-on ;'])->assertOk()->assertJsonPath('ok', false);

        $response = $this->postJson('/api/v1/release', ['company_name' => 'Api Released', 'script' => ': launched ;', 'kit' => 'factory'])
            ->assertCreated()->assertJsonPath('queued', false)->assertJsonPath('company.name', 'Api Released')->json();
        $this->assertTrue(DB::table('agents')->where('agent_id', $response['drone']['id'])->exists());

        // The same rules as the Release page
        $this->postJson('/api/v1/release', ['company_name' => 'Api Released', 'script' => ': launched ;'])
            ->assertStatus(422)->assertJsonStructure(['error', 'errors' => ['company_name']]);
        $this->postJson('/api/v1/release', ['company_name' => 'Api Other', 'script' => ': nothing ;'])->assertStatus(422);
        $this->postJson('/api/v1/release', ['company_name' => 'Api Kit', 'script' => ': launched ;', 'kit' => 'gold'])->assertStatus(422);
    }

    public function testHistorySumsTradesByDayResourceAndPlace()
    {
        // Players asked for it in round 31: the recent trades cover under a game day
        $seller = $this->release('History Sellers');
        $buyer = $this->release('History Buyers');
        DB::table('trades')->delete();
        $epoch = (int) DB::table('game_clock')->where('id', 1)->value('epoch');
        $today = intdiv(World::gameNow() - $epoch, 86400);
        $resource = (int) DB::table('resources')->value('id');
        $at = fn (int $day, int $hour) => $epoch + $day * 86400 + $hour * 3600;
        $trade = fn (int $units, int $credits, int $place, int $t) => ['resource' => $resource, 'units' => $units, 'credits' => $credits,
            'seller' => $seller->agent_id, 'buyer' => $buyer->agent_id, 'location' => $place, 'game_t' => $t];
        DB::table('trades')->insert([
            $trade(10, 100, 3, $at($today - 1, 2)), $trade(10, 300, 3, $at($today - 1, 9)),   // 10 and 30 a unit
            $trade(5, 50, 4, $at($today - 1, 9)),                                              // another place
            $trade(4, 48, 3, $at($today, 0)),                                                  // the next day
            $trade(1, 99, 3, $at($today - 90, 0)),                                             // older than 60 days
        ]);
        $rows = $this->getJson('/api/v1/history?resource=' . $resource)->assertOk()->json();
        $this->assertCount(3, $rows);
        $this->assertSame(['day' => $today - 1, 'resource' => $resource], array_intersect_key($rows[0], ['day' => 0, 'resource' => 0]));
        $this->assertSame([3, 2, 20, 400, 10, 20, 30], [$rows[0]['place'], $rows[0]['trades'], $rows[0]['units'], $rows[0]['credits'],
            (int) $rows[0]['low'], (int) $rows[0]['average'], (int) $rows[0]['high']]);
        $this->assertSame([4, 5], [$rows[1]['place'], $rows[1]['units']]);
        $this->assertSame([$today, 12.0], [$rows[2]['day'], (float) $rows[2]['average']]);
        $this->assertCount(1, $this->getJson('/api/v1/history?resource=' . $resource . '&place=4')->json());
        $this->assertCount(4, $this->getJson('/api/v1/history?days=365')->json());
        $this->getJson('/api/v1/history?days=0')->assertStatus(422)->assertJsonStructure(['error', 'errors' => ['days']]);
    }

    public function testNoHouseInformation()
    {
        $resources = $this->getJson('/api/v1/resources')->assertOk()->json();
        $this->assertNotEmpty($resources);
        $this->assertSame(['id', 'name', 'mass'], array_keys($resources[0]), 'no prices');
        foreach (['leaderboard', 'trades', 'history', 'places', 'protocols', 'queries'] as $path) {
            $this->getJson('/api/v1/' . $path)->assertOk();
        }
        $this->assertStringNotContainsString('station_goods', json_encode($this->getJson('/api/v1/queries')->json()));
    }
}
