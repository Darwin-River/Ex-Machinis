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

    private const SCRIPT = "\\ A miner\n: dig ( n -- ) drop ;\n: launched ( -- )  5 dig ;";

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
        $this->assertSame(': dig drop ; : launched 5 dig ;', Provenance::normalise(self::SCRIPT));
        $this->assertSame(Provenance::hash(self::SCRIPT), Provenance::hash(":   dig   drop ;\n\n: launched 5 dig ; \\ go"));
        $this->assertSame(['dig' => ': dig drop ;', 'launched' => ': launched 5 dig ;'], Provenance::words(self::SCRIPT));
        $this->assertSame(': dig ( n -- ) drop ;', Provenance::wordSource(self::SCRIPT, 'dig'));
    }

    public function testDronePageShowsItsCodeAndState()
    {
        $agent = Release::release($this->player('a@example.com'), 'Diggers', self::SCRIPT);
        $this->get('/spacecraft/' . $agent->agent_id)->assertOk()
            ->assertSee('Copy this code')->assertSee(': dig ( n -- ) drop ;', false)
            ->assertSee('release?copy=' . $agent->agent_id . '&amp;word=dig', false)
            ->assertSee('Diggers')->assertSee('Generation');
    }

    public function testCopyThisCodeRecordsWhereItCameFrom()
    {
        $original = Release::release($this->player('a@example.com'), 'Diggers', self::SCRIPT);
        $this->get('/release?copy=' . $original->agent_id)->assertOk()
            ->assertSee('name="copied_from" value="' . $original->agent_id . '"', false)
            ->assertSee(': launched ( -- )  5 dig ;', false);
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
        $pasted = Release::release($this->player('b@example.com'), 'Pasters', ": dig drop ;\n: launched 5 dig ;");
        $mixed = Release::release($this->player('c@example.com'), 'Mixers', ": dig ( a -- ) drop ;\n: launched 7 dig ;");

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
        $this->get('/spacecraft/' . $cloneId)->assertOk()->assertSee('Inherited from')->assertSee(': dig ( n -- ) drop ;', false);
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
        $this->get('/spacecraft/' . $agent->agent_id)->assertDontSee('id="activity"', false);
    }

    public function testAWreckGetsAPostMortem()
    {
        $agent = Release::release($this->player('a@example.com'), 'Diggers', self::SCRIPT);
        $id = DB::table('actions')->insertGetId(['drone' => $agent->agent_id, 'protocol' => 11100, 'multiplier' => 1, 'aborted' => 1, 'outcome' => -2]);
        DB::table('events')->insert(['event_type' => 2, 'action' => $id, 'logged' => 1, 'outcome' => 1, 'drone' => $agent->agent_id,
            'resource' => 2061, 'installed' => 0, 'locked' => 0, 'new_quantity' => 0, 'new_cargo' => 0, 'game_t' => 1000]);
        // Plundered after it died: that haul isn't its last action
        DB::table('actions')->insert(['drone' => $agent->agent_id, 'protocol' => 35, 'multiplier' => 1, 'aborted' => 0]);
        DB::table('agents')->where('agent_id', $agent->agent_id)->update(['wrecked_at' => 2000, 'wreck_cause' => 'fuel',
            'instructions' => 15700000]);
        $this->get('/spacecraft/' . $agent->agent_id)->assertOk()->assertSee('Post-mortem')
            ->assertSee('It ran out of fuel')->assertSee('Its last action was protocol 11100')
            ->assertSee('15,700,000 instructions in all, about 157 fuel of computing')
            ->assertSee('failed: not enough of a resource (or fuel) in the hold');

        DB::table('drone_crashes')->insert(['drone' => $agent->agent_id, 'game_t' => 1500, 'code' => -10, 'in_row' => 5]);
        DB::table('agents')->where('agent_id', $agent->agent_id)->update(['wreck_cause' => 'crashes']);
        $this->get('/spacecraft/' . $agent->agent_id)->assertSee('crashed 5 times in a row, the last time with division by zero');

        DB::table('company_taxes')->insert(['company_id' => $agent->company_id, 'charged_t' => 2000, 'amount' => 7, 'credits_after' => 0, 'eliminated' => 1]);
        DB::table('agents')->where('agent_id', $agent->agent_id)->update(['wreck_cause' => 'tax']);
        $this->get('/spacecraft/' . $agent->agent_id)->assertSee("couldn&#039;t pay its tax bill", false)->assertSee('it had 7 credits left');
    }
}
