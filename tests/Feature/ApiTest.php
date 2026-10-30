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
        $this->getJson('/api/v1/clock')->assertOk()->assertJsonStructure(['game_time', 'game_day', 'when', 'world_full', 'releases_waiting']);
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

    public function testNoHouseInformation()
    {
        $resources = $this->getJson('/api/v1/resources')->assertOk()->json();
        $this->assertNotEmpty($resources);
        $this->assertSame(['id', 'name', 'mass'], array_keys($resources[0]), 'no prices');
        foreach (['leaderboard', 'trades', 'places', 'protocols', 'queries'] as $path) {
            $this->getJson('/api/v1/' . $path)->assertOk();
        }
        $this->assertStringNotContainsString('station_goods', json_encode($this->getJson('/api/v1/queries')->json()));
    }
}
