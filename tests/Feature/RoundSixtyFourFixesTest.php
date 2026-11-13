<?php

namespace Tests\Feature;

use App\Agent;
use App\Services\CompanyHealth;
use App\Services\DronePage;
use App\Services\Release;
use App\Services\Shares;
use App\Services\World;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Round 64's small fixes (#380): pages that misread a drone in flight,
 * house-demand by place name, /check's undefined names, payback with no
 * price, game time in events, copies that kept their parent's site, and
 * the copy
 */
class RoundSixtyFourFixesTest extends TestCase
{
    use DatabaseTransactions;

    private function drone(string $name): Agent
    {
        $user = User::create(['name' => $name, 'email' => uniqid() . '@example.com', 'password' => bcrypt('secret-password')]);
        return Release::release($user, $name . ' Co', 'function launched() end');
    }

    /** #622: a drone in flight says where it is going, and isn't "stalled" */
    public function testADroneInFlightSaysSoAndIsntStalled()
    {
        $drone = $this->drone('Flyer');
        $now = World::gameNow();
        // It last sold goods 30 game hours ago: idle, so stalled
        DB::table('events')->insert(['event_type' => 2, 'action' => 0, 'logged' => 1, 'outcome' => 1, 'drone' => $drone->agent_id,
            'resource' => 2011, 'installed' => 0, 'locked' => 0, 'new_quantity' => 0, 'new_cargo' => 0, 'game_t' => $now - 30 * 3600]);
        $this->assertTrue(CompanyHealth::of((int) $drone->company_id)['stalled']);
        $this->assertNull(DronePage::flight($drone));

        // Now it flies to Deimos, arriving in 2 game days; its script carried on (no waiting action)
        $deimos = (int) (DB::table('objects')->where('object_name', 'Deimos')->value('object_id') ?? 12);
        $action = DB::table('actions')->insertGetId(['drone' => $drone->agent_id, 'protocol' => 1, 'aborted' => 0]);
        DB::table('events')->insert(['event_type' => 3, 'action' => $action, 'logged' => 0, 'outcome' => 0, 'drone' => $drone->agent_id,
            'resource' => 0, 'installed' => 0, 'locked' => 0, 'new_location' => $deimos, 'new_cargo' => 0, 'game_t' => $now + 2 * 86400]);
        $drone = Agent::where('agent_id', $drone->agent_id)->first();
        $flight = DronePage::flight($drone);
        $this->assertSame($deimos, $flight['to_id'], 'its arrival is found though it waits on no action');
        $this->assertStringStartsWith('flying to ', DronePage::status($drone));
        $health = CompanyHealth::of((int) $drone->company_id);
        $this->assertFalse($health['stalled'], 'flying is working');
        $this->assertSame(0, $health['idle']);
        $this->assertSame(0, CompanyHealth::drones((int) $drone->company_id)[0]['idle']);
        $this->getJson('/api/v1/companies/' . $drone->company_id)->assertOk()
            ->assertJsonPath('drones.0.flying_to.place_id', $deimos);

        // An aborted trip never arrives
        DB::table('actions')->where('id', $action)->update(['aborted' => 1]);
        $this->assertNull(DronePage::flight($drone));
        $this->assertTrue(CompanyHealth::of((int) $drone->company_id)['stalled']);
    }

    /** #623: house-demand takes a place's name, as /orders does */
    public function testHouseDemandTakesAPlaceName()
    {
        $mars = DB::table('objects')->where('object_name', 'Mars')->value('object_id');
        $this->assertNotNull($mars);
        $rows = $this->getJson('/api/v1/house-demand?place=Mars')->assertOk()->json();
        $this->assertEquals($this->getJson('/api/v1/house-demand?place=' . $mars)->json(), $rows, 'by name or by ID: the same');
        foreach ($rows as $r) {
            $this->assertSame((int) $mars, (int) $r['place']);
        }
        $this->getJson('/api/v1/house-demand?place=Atlantis')->assertNotFound();
    }

    /** #624: /check lists the names nothing defines, and clean says whether it's safe to release */
    public function testCheckSaysWhetherAScriptIsClean()
    {
        $this->postJson('/api/v1/check', ['script' => "function launched() wait(60) end"])->assertOk()
            ->assertJsonPath('ok', true)->assertJsonPath('clean', true)->assertJsonPath('undefined', []);
        $bad = $this->postJson('/api/v1/check', ['script' => "function launched() frobnicate() end"])->assertOk()
            ->assertJsonPath('ok', true);
        if (!is_executable(config('forge.lua_check'))) {
            $this->markTestSkipped('lua-check is built with the engine (make -C game-engine/src)');
        }
        $bad->assertJsonPath('clean', false)->assertJsonPath('undefined.0.name', 'frobnicate');
    }

    /** #625: a share with no price has no payback, not 0 days */
    public function testNoPriceNoPayback()
    {
        $long = ['profit30' => 900, 'carried' => 0];
        $this->assertNull(Shares::paybackDays(0, 1000, $long));
        $this->assertSame(2, Shares::paybackDays(1, 1000, $long));
        $lua = file_get_contents(base_path('game-engine/src/lua_library.lua'));
        $this->assertStringContainsString('(price or 0) <= 0 then return math.huge', $lua);
    }

    /** #626: the events table shows game time, and every figure it has */
    public function testEventsShowGameTime()
    {
        $drone = $this->drone('Watcher');
        $now = World::gameNow();
        $id = DB::table('events')->insertGetId(['event_type' => 2, 'action' => 0, 'logged' => 1, 'outcome' => 1, 'drone' => $drone->agent_id,
            'resource' => 2011, 'installed' => 0, 'locked' => 0, 'new_quantity' => 0, 'new_cargo' => 0, 'game_t' => $now - 60,
            'timestamp' => now()->subMinute()]);
        DB::table('observations')->insert(['event' => $id, 'drone' => 0, 'time' => now()->subMinute()]);
        $row = collect($this->getJson('/events/search')->assertOk()->json('data'))->firstWhere('affected_agent_id', $drone->agent_id);
        $this->assertNotNull($row);
        $this->assertSame(DronePage::when($now - 60), $row['when']);
        $this->assertSame(2, (int) $row['event_type']);
        $this->assertSame(0, (int) $row['new_quantity'], 'a 0 is a figure too');
        $vue = file_get_contents(base_path('resources/assets/js/components/InGameEventsTable.vue'));
        $this->assertStringContainsString("== 1 ? 'unit' : 'units'", $vue);
    }

    /** #627: the starter miners' copies choose their own site; a lower floor on asking prices */
    public function testCopiesChooseTheirOwnSite()
    {
        foreach (['starters/miner', 'starters/refiner', 'companies/listed_miner', 'companies/raising_miner'] as $name) {
            $src = file_get_contents(base_path("examples/$name.lua"));
            $this->assertStringContainsString('if started ~= me() then', $src, $name);
            $this->assertStringContainsString('copy, started = true, me()', $src, $name);
            $this->assertStringNotContainsString('math.max(offer - 1, 0)', $src, $name);
            $this->assertMatchesRegularExpression('/^min_ask = \d+/m', $src, $name);
        }
    }

    /** #628: the copy */
    public function testTheCopy()
    {
        $this->get('/release')->assertOk()->assertSee('Release a drone')->assertDontSee('Release a Script');
        $this->assertStringNotContainsString('Rare inorganicas', file_get_contents(base_path('game-engine/sql/game_data.sql')));
        $this->assertStringNotContainsString('Mosquitto', file_get_contents(base_path('game-engine/db-backup/exmachinis_tables.sql')));
        $this->assertStringContainsString("{{resource.mass}} kg", file_get_contents(base_path('resources/assets/js/components/ResourcesTable.vue')));
        $this->assertStringNotContainsString("Released drones can't be steered", file_get_contents(base_path('documents/players-manual.md')));
    }
}
