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

    private function release(string $company, string $script = 'function launched() end')
    {
        $user = User::create(['name' => 'Forge Player', 'email' => uniqid() . '@example.com', 'password' => bcrypt('secret-password')]);
        return Release::release($user, $company, $script);
    }

    public function testClockSaysTheGameDay()
    {
        $this->getJson('/api/v1/clock')->assertOk()->assertJsonStructure(['game_time', 'game_day', 'when', 'world_full', 'releases_waiting',
            'real_seconds_per_game_day', 'target_scale', 'target_real_seconds_per_game_day', 'floor_scale', 'slowed', 'engine' => ['running', 'last_seen_seconds_ago', 'turns_per_minute', 'behind_seconds']]);
    }

    public function testClockSaysWhenItIsSlowerThanUsual()
    {
        // #418: the pace now, beside the pace it runs at when the engines keep up
        $now = time();
        DB::table('game_clock')->updateOrInsert(['id' => 1], ['epoch' => $now, 'scale' => 450, 'target_scale' => 1800, 'floor_scale' => 60,
            'real_anchor' => $now, 'game_anchor' => $now, 'load_checked_at' => $now]);
        $this->getJson('/api/v1/clock')->assertOk()->assertJson(['scale' => 450, 'real_seconds_per_game_day' => 192,
            'target_scale' => 1800, 'target_real_seconds_per_game_day' => 48, 'floor_scale' => 60, 'slowed' => true]);
        DB::table('game_clock')->where('id', 1)->update(['scale' => 1800]);
        $this->getJson('/api/v1/clock')->assertOk()->assertJson(['real_seconds_per_game_day' => 48, 'slowed' => false]);
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
        $agent = $this->release('Drone Watchers', "function launched() end\n-- my script");
        DB::table('drone_messages')->insert(['agent_id' => $agent->agent_id, 'subject' => 'Report', 'body' => 'all well',
            'game_t' => World::gameNow() - 10]);
        $this->getJson('/api/v1/drones/' . $agent->agent_id)->assertOk()
            ->assertJsonPath('id', (int) $agent->agent_id)
            ->assertJsonPath('company.name', 'Drone Watchers')
            ->assertJsonPath('messages.0.body', 'all well')
            ->assertJsonStructure(['place', 'status', 'fuel', 'cargo', 'timeline', 'trades', 'script']);
        $this->getJson('/api/v1/drones/999999999')->assertNotFound();
    }

    public function testADroneSaysWhatItsScriptIsDoing()
    {
        $agent = $this->release('Status Watchers', "function launched() end");
        $this->getJson('/api/v1/drones/' . $agent->agent_id)->assertOk()->assertJsonPath('script_status', null);

        // set_status(text) in the drone's script writes these (#380)
        DB::table('agents')->where('agent_id', $agent->agent_id)
            ->update(['script_status' => 'waiting for metals: none on sale here', 'script_status_t' => World::gameNow()]);
        $this->getJson('/api/v1/drones/' . $agent->agent_id)->assertOk()
            ->assertJsonPath('script_status.text', 'waiting for metals: none on sale here')
            ->assertJsonStructure(['script_status' => ['text', 'set', 'set_game_time']]);
        $this->get('/spacecraft/' . $agent->agent_id)->assertOk()
            ->assertSee('Its script says')
            ->assertSee('waiting for metals: none on sale here');
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

    public function testSellOrdersListOnlyWhatAPullCanTake()
    {
        // #502: locked units (a factory's inputs in use) and units a pull on
        // its way will take aren't for sale
        $seller = $this->release('Careful Sellers');
        $resource = (int) DB::table('resources')->value('id');
        $now = World::gameNow();
        $event = fn ($type, $q, $extra = []) => DB::table('events')->insert(array_merge(['event_type' => $type,
            'action' => 0, 'outcome' => 1, 'drone' => $seller->agent_id, 'resource' => $resource, 'installed' => 0,
            'locked' => 0, 'new_quantity' => $q, 'game_t' => $now], $extra));
        $event(1, 10);
        $event(1, 5, ['locked' => 1]);
        $event(4, 2, ['new_credits' => 7]);
        $units = fn () => collect($this->getJson('/api/v1/orders?side=sell&resource=' . $resource)->json())
            ->firstWhere('drone', (int) $seller->agent_id)['units'] ?? 0;
        $this->assertSame(8, $units(), '10 free less the 2 it keeps; the 5 locked are not for sale');
        // A pull on its way (pending: outcome 0) takes 4 of them
        $event(2, -4, ['outcome' => 0, 'game_t' => $now + 60]);
        $this->assertSame(4, $units());
        // ...and when it takes the rest the order is no longer listed
        $event(2, -4, ['outcome' => 0, 'game_t' => $now + 60]);
        $this->assertSame(0, $units());
        // #612: an aborted action's events never happen, though they stay
        // pending for ever: they hid the House's fuel at Earth and the Moon
        DB::table('events')->where('drone', $seller->agent_id)->where('outcome', 0)->delete();
        $action = DB::table('actions')->insertGetId(['drone' => $seller->agent_id, 'aborted' => 1]);
        $event(2, -8, ['outcome' => 0, 'game_t' => $now - 86400, 'action' => $action]);
        $this->assertSame(8, $units(), 'the aborted export takes nothing');
    }

    public function testOrdersOfOneCompany()
    {
        // #380 round 36: a company's own open orders
        $a = $this->release('Order Keepers');
        $b = $this->release('Other Traders');
        $resource = (int) DB::table('resources')->value('id');
        foreach ([$a, $b] as $drone) {
            DB::table('events')->insert(['event_type' => 5, 'action' => 0, 'outcome' => 1, 'drone' => $drone->agent_id,
                'resource' => $resource, 'new_quantity' => 50, 'new_credits' => 9, 'game_t' => World::gameNow()]);
        }
        $mine = $this->getJson('/api/v1/orders?company=' . urlencode('Order Keepers'))->assertOk()->json();
        $this->assertSame([(int) $a->agent_id], array_column($mine, 'drone'));
        $this->assertSame('Order Keepers', $mine[0]['company']);
        $byId = $this->getJson('/api/v1/orders?company=' . $mine[0]['company_id'])->json();
        $this->assertSame([(int) $a->agent_id], array_column($byId, 'drone'));
    }

    public function testThereIsNoTestWorld()
    {
        // Every release is live (#380, after round 40): no test runs
        $this->postJson('/api/v1/test', ['script' => "function launched() end"])->assertNotFound();
        $this->get('/release')->assertOk()->assertDontSee('release/test')->assertSee('Break, learn, repeat')->assertSee('Release early, release often');
    }

    public function testADroneSaysTheFuelItUsedToday()
    {
        $agent = $this->release('Fuel Watchers');
        $now = World::gameNow();
        foreach ([[100, $now - 7200], [97, $now - 3600]] as [$q, $t]) {
            DB::table('events')->insert(['event_type' => 2, 'action' => 0, 'outcome' => 1, 'drone' => $agent->agent_id,
                'resource' => Release::FUEL, 'installed' => 0, 'locked' => 0, 'new_quantity' => $q, 'game_t' => $t]);
        }
        $this->getJson('/api/v1/drones/' . $agent->agent_id)->assertOk()
            ->assertJsonPath('fuel_last_day.actions', 3)->assertJsonPath('fuel_last_day.thinking', 0);
    }

    public function testRecipesAndWhatAThingTakes()
    {
        // A made-up chain: 2 metals (and 2 fuel) make an alloy, an alloy and
        // 3 fuel make a rod, on a Smelter
        foreach ([2011 => 'Metals (A1)', 2021 => 'Alloys (A1)', 3011 => 'Rods (A1)', 5201 => 'Smelter (A1)', 2061 => 'Fuel (A1)'] as $id => $name) {
            DB::table('resources')->insertOrIgnore(['id' => $id, 'name' => $name, 'mass' => 1, 'capacity' => 0, 'slot_size' => 0]);
        }
        // (resource, event type, locked, deplete, quantity, time): the tool
        // is locked while it works and given back; the rest is used or made
        $chain = function (int $input, int $inputs, int $fuel, int $seconds, int $product) {
            return [[5201, 2, 0, 1, 1, 0], [5201, 1, 1, 0, 1, 0], [2061, 2, 0, 1, $fuel, 0], [$input, 2, 0, 1, $inputs, 0],
                    [5201, 2, 1, 1, 1, $seconds], [5201, 1, 0, 0, 1, $seconds], [$product, 1, 0, 0, 1, $seconds]];
        };
        $id = 900000;
        foreach ([[12021, 'Make Alloys (A1)', $chain(2011, 2, 2, 7200, 2021)],
                  [13011, 'Make Rods (A1)', $chain(2021, 1, 3, 14400, 3011)]] as [$p, $name, $effects]) {
            DB::table('protocols')->insertOrIgnore(['id' => $p, 'name' => $name, 'parameters' => 0, 'multiplier' => 0, 'double_params' => 0]);
            DB::table('resource_effects')->where('protocol', $p)->delete();
            foreach ($effects as [$res, $type, $locked, $deplete, $q, $time]) {
                DB::table('resource_effects')->insert(['id' => $id++, 'drone' => 0, 'resource' => $res, 'protocol' => $p, 'event_type' => $type,
                    'local' => 1, 'installed' => 0, 'locked' => $locked, 'deplete' => $deplete, 'quantity' => $q, 'time' => $time]);
            }
        }
        $rods = $this->getJson('/api/v1/recipes?resource=3011')->assertOk()->json();
        $this->assertSame(13011, $rods[0]['protocol']);
        $this->assertSame([['resource' => 2021, 'name' => 'Alloys (A1)', 'units' => 1]], $rods[0]['uses']);
        $this->assertSame(3, $rods[0]['fuel']);
        $this->assertSame(5201, $rods[0]['tool']);
        $this->assertEquals(4, $rods[0]['hours']);

        $tree = $this->getJson('/api/v1/recipes/3011/tree')->assertOk()->json();
        $this->assertSame([['resource' => 2011, 'name' => 'Metals (A1)', 'units' => 2]], $tree['goods']);
        $this->assertEquals(5, $tree['fuel']);
        $this->assertEquals(6, $tree['tool_hours'][0]['hours']);
        $this->getJson('/api/v1/recipes/99999999/tree')->assertNotFound();
    }

    public function testNewsAndNetWealthByDay()
    {
        $this->getJson('/api/v1/news')->assertOk();
        $agent = $this->release('Day Keepers');
        DB::table('company_days')->insert([
            ['company_id' => $agent->company_id, 'day' => 3, 'credits' => 1200, 'net_wealth' => 150, 'drones' => 1],
            ['company_id' => $agent->company_id, 'day' => 4, 'credits' => 1500, 'net_wealth' => 420, 'drones' => 2],
        ]);
        $this->getJson('/api/v1/companies/' . $agent->company_id . '/history')->assertOk()
            ->assertJsonPath('1.day', 4)->assertJsonPath('1.net_wealth', 420)->assertJsonPath('0.credits', 1200);
        $this->getJson('/api/v1/companies/999999999/history')->assertNotFound();
    }

    public function testReleaseChecksAndReleasesAScript()
    {
        $this->postJson('/api/v1/check', ['script' => "ask = 1\nfunction launched() end"])->assertOk()
            ->assertJsonPath('ok', true)->assertJsonPath('language', 'lua')->assertJson(['warning' => true]);
        $this->postJson('/api/v1/check', ['script' => 'function go_on() end'])->assertOk()->assertJsonPath('ok', false);
        // FORTH was retired (#380): a FORTH script is refused, and says why
        $this->postJson('/api/v1/check', ['script' => ': launched ;'])->assertOk()->assertJsonPath('ok', false)
            ->assertJsonPath('error', fn ($e) => str_contains($e, 'FORTH has been retired'));

        $response = $this->postJson('/api/v1/release', ['company_name' => 'Api Released', 'script' => 'function launched() end', 'kit' => 'factory'])
            ->assertCreated()->assertJsonPath('queued', false)->assertJsonPath('company.name', 'Api Released')->json();
        $this->assertTrue(DB::table('agents')->where('agent_id', $response['drone']['id'])->exists());

        // The same rules as the Release page
        $this->postJson('/api/v1/release', ['company_name' => 'Api Released', 'script' => 'function launched() end'])
            ->assertStatus(422)->assertJsonStructure(['error', 'errors' => ['company_name']]);
        $this->postJson('/api/v1/release', ['company_name' => 'Api Other', 'script' => ': launched ;'])->assertStatus(422);
        $this->postJson('/api/v1/release', ['company_name' => 'Api Kit', 'script' => 'function launched() end', 'kit' => 'gold'])->assertStatus(422);
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
