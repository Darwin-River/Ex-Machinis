<?php

namespace Tests\Feature;

use App\Agent;
use App\Services\Provenance;
use App\Services\Release;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The Drone page (#324 step 16): live state, code and where it comes from,
 * "Copy this code" and "Copy this word", and post-mortems for wrecks.
 */
class DronePageTest extends TestCase
{
    use DatabaseTransactions;

    private const SCRIPT = "-- A miner\nfunction dig(n) -- n units\nend\n\nfunction launched()\n  dig(5)\nend\n";

    protected function setUp(): void
    {
        parent::setUp();
    }

    private function player(string $email): User
    {
        return User::create(['name' => 'Forge Player', 'email' => $email, 'password' => bcrypt('secret-password')]);
    }

    public function testNormalisedSourceIgnoresCommentsAndSpacing()
    {
        $this->assertSame('function dig(n) end function launched() dig(5) end', Provenance::normalise(self::SCRIPT));
        $this->assertSame(Provenance::hash(self::SCRIPT), Provenance::hash("function   dig(n)\nend\nfunction launched()  dig(5) -- go\nend"));
        $this->assertSame(['dig' => 'function dig(n) end', 'launched' => 'function launched() dig(5) end'], Provenance::words(self::SCRIPT));
        $this->assertSame("function dig(n) -- n units\nend", Provenance::wordSource(self::SCRIPT, 'dig'));
    }

    public function testDronePageShowsItsCodeAndState()
    {
        $agent = Release::release($this->player('a@example.com'), 'Diggers', self::SCRIPT);
        $this->get('/spacecraft/' . $agent->agent_id)->assertOk()
            ->assertSee('Copy this code')->assertSee('function dig(n) -- n units', false)
            ->assertSee('release?copy=' . $agent->agent_id . '&amp;word=dig', false)
            ->assertSee('Diggers')->assertSee('Generation');
    }

    public function testCopyThisCodeRecordsWhereItCameFrom()
    {
        $original = Release::release($this->player('a@example.com'), 'Diggers', self::SCRIPT);
        $this->get('/release?copy=' . $original->agent_id)->assertOk()
            ->assertSee('name="copied_from" value="' . $original->agent_id . '"', false)
            ->assertSee('  dig(5)', false);
        $this->get('/release?copy=' . $original->agent_id . '&word=dig')->assertOk()
            ->assertSee('name="copied_word" value="dig"', false);

        $this->post('/release', ['company_name' => 'Copycats', 'script' => self::SCRIPT,
            'copied_from' => $original->agent_id]);
        $copy = DB::table('releases')->orderBy('id', 'desc')->first();
        $this->assertSame($original->agent_id, (int) $copy->copied_from);
        $this->get('/spacecraft/' . $copy->agent_id)->assertSee('Copied from');
    }

    public function testPastedCodeAndWordsAreTracedToTheirFirstRelease()
    {
        $original = Release::release($this->player('a@example.com'), 'Diggers', self::SCRIPT);
        // Another player pastes the same code, laid out differently, and
        // reuses the word dig in a new script
        $pasted = Release::release($this->player('b@example.com'), 'Pasters', "function dig(n)\nend\nfunction launched() dig(5)\nend");
        $mixed = Release::release($this->player('c@example.com'), 'Mixers', "function dig(n) -- a\nend\nfunction launched()\n  dig(7)\nend");

        $this->get('/spacecraft/' . $pasted->agent_id)->assertSee('The same code as');
        $this->get('/spacecraft/' . $mixed->agent_id)->assertSee('first in <a href="' . url('spacecraft/' . $original->agent_id), false);
        $this->assertNull(Provenance::wordOrigins(Provenance::releaseOf($mixed->agent_id))['launched']);
    }

    public function testACloneShowsTheCodeItInherited()
    {
        $parent = Release::release($this->player('a@example.com'), 'Diggers', self::SCRIPT);
        $cloneId = DB::table('agents')->insertGetId(['user_id' => $parent->user_id, 'company_id' => $parent->company_id,
            'name' => 'SN-CLONE-1', 'object_id' => 3, 'hull_type' => 1,
            'parent_id' => $parent->agent_id, 'code_from' => $parent->agent_id, 'generation' => 1]);
        $this->get('/spacecraft/' . $cloneId)->assertOk()->assertSee('Inherited from')->assertSee('function dig(n) -- n units', false);
        $this->get('/spacecraft/' . $parent->agent_id)->assertSee('SN-CLONE-1');
    }

    public function testAWorkingDroneShowsItsActivity()
    {
        $agent = Release::release($this->player('a@example.com'), 'Diggers', self::SCRIPT);
        $buyer = Release::release($this->player('b@example.com'), 'Buyers', self::SCRIPT);
        $now = time();
        // A mining run under way (its events end in two game hours), and a
        // finished one
        $done = DB::table('actions')->insertGetId(['drone' => $agent->agent_id, 'protocol' => 11100, 'multiplier' => 1, 'aborted' => 0]);
        DB::table('events')->insert(['event_type' => 2, 'action' => $done, 'logged' => 1, 'outcome' => 1, 'drone' => $agent->agent_id,
            'resource' => 2061, 'installed' => 0, 'locked' => 0, 'new_quantity' => 98, 'new_cargo' => 0, 'game_t' => $now - 7200]);
        $busy = DB::table('actions')->insertGetId(['drone' => $agent->agent_id, 'protocol' => 11100, 'multiplier' => 1, 'aborted' => 0]);
        DB::table('events')->insert([
            ['event_type' => 2, 'action' => $busy, 'logged' => 1, 'outcome' => 1, 'drone' => $agent->agent_id, 'resource' => 2061,
             'installed' => 0, 'locked' => 0, 'new_quantity' => 97, 'new_cargo' => 0, 'game_t' => $now - 60],
            ['event_type' => 1, 'action' => $busy, 'logged' => 1, 'outcome' => 0, 'drone' => $agent->agent_id, 'resource' => 1,
             'installed' => 0, 'locked' => 0, 'new_quantity' => 5, 'new_cargo' => 5, 'game_t' => $now + 7200],
        ]);
        // A wait: its event names fuel with 0, which isn't the tank
        $wait = DB::table('actions')->insertGetId(['drone' => $agent->agent_id, 'protocol' => 3, 'multiplier' => 1, 'aborted' => 0]);
        DB::table('events')->insert(['event_type' => 12, 'action' => $wait, 'logged' => 1, 'outcome' => 1, 'drone' => $agent->agent_id,
            'resource' => 2061, 'installed' => 0, 'locked' => 0, 'new_quantity' => 0, 'new_cargo' => 0, 'game_t' => $now - 3000]);
        DB::table('agents')->where('agent_id', $agent->agent_id)->update(['waiting_action' => $busy]);
        // Two sales to another drone, one of them taxed
        DB::table('trades')->insert(['resource' => 2, 'units' => 5, 'credits' => 100, 'tax' => 5, 'seller' => $agent->agent_id,
            'buyer' => $buyer->agent_id, 'location' => 3, 'game_t' => $now - 3600]);
        DB::table('trades')->insert(['resource' => 1, 'units' => 3, 'credits' => 45, 'seller' => $agent->agent_id,
            'buyer' => $buyer->agent_id, 'location' => 3, 'game_t' => $now - 1800]);

        $this->get('/spacecraft/' . $agent->agent_id)->assertOk()->assertSee('Activity')
            ->assertSee('game hours from now')->assertSee('under way, until')->assertSee('done')
            ->assertSee('Sold 5 Pellets, Iron')->assertSee('+95 (100 less 5 tax)')
            ->assertSee('Sold 3 Ore, Iron')->assertSee($buyer->name)->assertSee('+45');
        $wait = collect(\App\Services\DronePage::timeline($agent->agent_id))->firstWhere('game_t', $now - 3000);
        $this->assertNull($wait['fuel']);

        // A wreck has a post-mortem instead
        DB::table('agents')->where('agent_id', $agent->agent_id)->update(['wrecked_at' => $now, 'wreck_cause' => 'fuel']);
        $this->get('/spacecraft/' . $agent->agent_id)->assertDontSee('id="activity"', false)
            ->assertSee('Its last sales and purchases')->assertSee('Sold 5 Pellets, Iron');
        // and its trades are still in the API (#494: they were empty for a wreck)
        $this->assertCount(2, $this->getJson('/api/v1/drones/' . $agent->agent_id)->json('trades'));
    }

    public function testTheTimelinePagesAndFuelIsBrokenDownByAction()
    {
        $agent = Release::release($this->player('a@example.com'), 'Diggers', self::SCRIPT);
        $now = \App\Services\World::gameNow();
        // 30 mining runs in the last game day, a fuel each, and a power bill of 2
        $fuel = 100;
        DB::table('events')->insert(['event_type' => 1, 'action' => 0, 'logged' => 1, 'outcome' => 1,
            'drone' => $agent->agent_id, 'resource' => 2061, 'installed' => 0, 'locked' => 0,
            'new_quantity' => $fuel, 'new_cargo' => 0, 'game_t' => $now - 31 * 1800]);
        for ($i = 30; $i >= 1; $i--) {
            $action = DB::table('actions')->insertGetId(['drone' => $agent->agent_id, 'protocol' => 11100, 'multiplier' => 1, 'aborted' => 0]);
            DB::table('events')->insert(['event_type' => 2, 'action' => $action, 'logged' => 1, 'outcome' => 1,
                'drone' => $agent->agent_id, 'resource' => 2061, 'installed' => 0, 'locked' => 0,
                'new_quantity' => --$fuel, 'new_cargo' => 0, 'game_t' => $now - $i * 1800]);
        }
        $power = DB::table('actions')->insertGetId(['drone' => $agent->agent_id, 'protocol' => 2, 'multiplier' => 1, 'aborted' => 0]);
        DB::table('events')->insert(['event_type' => 2, 'action' => $power, 'logged' => 1, 'outcome' => 1,
            'drone' => $agent->agent_id, 'resource' => 2061, 'installed' => 0, 'locked' => 0,
            'new_quantity' => $fuel - 2, 'new_cargo' => 0, 'game_t' => $now - 60]);

        $used = \App\Services\DronePage::fuelLastDay($agent);
        $this->assertSame([30, 2], [$used['actions'], $used['thinking']]);
        $this->assertSame(['Thinking', 2], [$used['by_action'][1]['what'], $used['by_action'][1]['fuel']]);
        $this->assertSame([30, 30], [$used['by_action'][0]['fuel'], $used['by_action'][0]['times']]);

        // Pages of 20, then the rest
        $first = $this->getJson('/api/v1/drones/' . $agent->agent_id . '/timeline?limit=20')->assertOk()->json();
        $this->assertCount(20, $first['timeline']);
        $rest = $this->getJson('/api/v1/drones/' . $agent->agent_id . '/timeline?limit=20&before=' . $first['next_before'])->json();
        $this->assertCount(10, $rest['timeline']);
        $this->assertNull($rest['next_before']);
        $this->getJson('/api/v1/drones/999999/timeline')->assertNotFound();
    }

    public function testAFlyingDroneSaysWhereItIsGoing()
    {
        // Round 31: a drone flying from the Moon to Mars showed "Moon" for ten days
        $agent = Release::release($this->player('a@example.com'), 'Flyers', self::SCRIPT);
        $from = (int) DB::table('agents')->where('agent_id', $agent->agent_id)->value('object_id');
        $to = (int) DB::table('objects')->where('object_id', '!=', $from)->orderBy('object_id')->value('object_id');
        $toName = DB::table('objects')->where('object_id', $to)->value('object_name');
        $arrives = \App\Services\World::gameNow() + 5 * 86400;
        $flight = DB::table('actions')->insertGetId(['drone' => $agent->agent_id, 'protocol' => 11100, 'multiplier' => 1, 'aborted' => 0]);
        DB::table('events')->insert(['event_type' => 3, 'action' => $flight, 'logged' => 1, 'outcome' => 0, 'drone' => $agent->agent_id,
            'new_location' => $to, 'game_t' => $arrives]);
        DB::table('agents')->where('agent_id', $agent->agent_id)->update(['waiting_action' => $flight]);

        $when = \App\Services\DronePage::when($arrives);
        $this->get('/spacecraft/' . $agent->agent_id)->assertOk()
            ->assertSee('flying from')->assertSee($toName . '</a>, arriving ' . $when, false)->assertSee(': flying to ' . $toName);
        $this->getJson('/api/v1/drones/' . $agent->agent_id)->assertOk()->assertJsonPath('place_id', $from)
            ->assertJsonPath('flying_to.place_id', $to)->assertJsonPath('flying_to.arrives', $when);

        // Once there, it isn't flying any more
        DB::table('agents')->where('agent_id', $agent->agent_id)->update(['waiting_action' => null]);
        $this->getJson('/api/v1/drones/' . $agent->agent_id)->assertOk()->assertJsonPath('flying_to', null);
    }

    public function testAWreckGetsAPostMortem()
    {
        $agent = Release::release($this->player('a@example.com'), 'Diggers', self::SCRIPT);
        $id = DB::table('actions')->insertGetId(['drone' => $agent->agent_id, 'protocol' => 11100, 'multiplier' => 1, 'aborted' => 1, 'outcome' => -2]);
        DB::table('events')->insert(['event_type' => 2, 'action' => $id, 'logged' => 1, 'outcome' => 1, 'drone' => $agent->agent_id,
            'resource' => 2061, 'installed' => 0, 'locked' => 0, 'new_quantity' => 0, 'new_cargo' => 0, 'game_t' => 1000]);
        // Plundered after it died: that haul isn't its last action
        DB::table('actions')->insert(['drone' => $agent->agent_id, 'protocol' => 35, 'multiplier' => 1, 'aborted' => 0]);
        // It paid for its thinking in fuel (before #544): a power bill
        DB::table('actions')->insert(['drone' => $agent->agent_id, 'protocol' => 2, 'multiplier' => 1, 'aborted' => 0]);
        DB::table('agents')->where('agent_id', $agent->agent_id)->update(['wrecked_at' => 2000, 'wreck_cause' => 'fuel',
            'instructions' => 15700000]);
        $this->get('/spacecraft/' . $agent->agent_id)->assertOk()->assertSee('Post-mortem')
            ->assertSee('It ran out of fuel')->assertSee('Its last action was protocol 11100')
            ->assertSee('15,700,000 instructions in all, about 78 fuel of computing')
            ->assertSee('failed: not enough of a resource (or fuel) in the hold');

        DB::table('drone_crashes')->insert(['drone' => $agent->agent_id, 'game_t' => 1500, 'code' => -10, 'in_row' => 5]);
        DB::table('agents')->where('agent_id', $agent->agent_id)->update(['wreck_cause' => 'crashes']);
        $this->get('/spacecraft/' . $agent->agent_id)->assertSee('crashed 5 times in a row, the last time with division by zero');

        DB::table('company_taxes')->insert(['company_id' => $agent->company_id, 'charged_t' => 2000, 'amount' => 7, 'credits_after' => 0, 'eliminated' => 1]);
        DB::table('agents')->where('agent_id', $agent->agent_id)->update(['wreck_cause' => 'tax']);
        $this->get('/spacecraft/' . $agent->agent_id)->assertSee("couldn&#039;t pay its tax bill", false)->assertSee('it had 7 credits left');
    }
}
