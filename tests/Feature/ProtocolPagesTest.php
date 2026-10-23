<?php

namespace Tests\Feature;

use App\Protocol;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Protocol and query details pages (/protocols/{id}, /queries/{id}).
 */
class ProtocolPagesTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('resources')->insert([
            ['id' => 99001, 'name' => 'Test Worm', 'mass' => 10],
            ['id' => 99002, 'name' => 'Test Ore', 'mass' => 1],
            ['id' => 99003, 'name' => 'Test Fuel', 'mass' => 1],
        ]);

        // A mining protocol: lock the Worm, burn fuel, unlock, mine ore
        DB::table('protocols')->insert(['id' => 99100, 'name' => 'Test mining', 'parameters' => 0,
            'description' => '[99100 perform]: Mines test ore.', 'multiplier' => 1]);
        $effect = fn ($id, $resource, $type, $locked, $quantity, $time, $mined = 0) => [
            'id' => $id, 'drone' => 0, 'resource' => $resource, 'protocol' => 99100, 'event_type' => $type,
            'local' => 1, 'installed' => 0, 'locked' => $locked, 'deplete' => $type == 2 ? 1 : 0,
            'abundancies' => $mined, 'quantity' => $quantity, 'time' => $time,
        ];
        DB::table('resource_effects')->insert([
            $effect(99901, 99001, 1, 1, 1, 0),
            $effect(99902, 99001, 2, 0, 1, 0),
            $effect(99903, 99003, 2, 0, 2, 1),
            $effect(99904, 99001, 1, 0, 1, 3600),
            $effect(99905, 99001, 2, 1, 1, 3600),
            $effect(99906, 99002, 1, 0, 5, 3601, 1),
        ]);

        DB::table('queries')->insert(['id' => 99200, 'name' => 'Test query', 'parameters' => 1,
            'description' => '[N R L 99200 query]: Returns a test value.', 'script' => 'SELECT 1;']);
    }

    public function testDetailsClassifyModulesConsumablesAndProducts()
    {
        $details = Protocol::findOrFail(99100)->details();

        $this->assertSame([['id' => 99001, 'name' => 'Test Worm', 'locked' => true]], $details['modules']);
        $this->assertSame([['id' => 99003, 'name' => 'Test Fuel', 'quantity' => 2]], $details['consumed']);
        $this->assertSame([['id' => 99002, 'name' => 'Test Ore', 'quantity' => 5, 'mined' => true]], $details['produced']);
        $this->assertSame(3601, $details['duration']);
        $this->assertFalse($details['travel']);
        $this->assertCount(6, $details['steps']);
    }

    public function testProtocolPageShowsWhatItNeedsAndMakes()
    {
        $this->get('/protocols/99100')
            ->assertOk()
            ->assertSee('<title>Test mining - Ex-Machinis</title>', false)
            ->assertSee('Modules needed')
            ->assertSee('Test Worm')
            ->assertSee('Uses up')
            ->assertSee('2 × <a', false)
            ->assertSee('Produces')
            ->assertSee('times how abundant it is')
            ->assertSee('1 hour of game time');
    }

    public function testLaunchStepIsDescribed()
    {
        DB::table('protocols')->insert(['id' => 99101, 'name' => 'Test launch', 'parameters' => 2,
            'description' => '[D K 99101 perform]: Launches a drone.', 'multiplier' => 0]);
        DB::table('resource_effects')->insert([
            'id' => 99907, 'drone' => 0, 'resource' => -1, 'protocol' => 99101, 'event_type' => 8,
            'local' => 1, 'installed' => 0, 'locked' => 0, 'deplete' => 0, 'abundancies' => 0,
            'quantity' => -2, 'time' => 3600,
        ]);

        $this->get('/protocols/99101')
            ->assertOk()
            ->assertSee('Launches a new drone built from the resource given as parameter 1')
            ->assertSee('the drone given as parameter 2');
    }

    public function testUnknownProtocolIs404()
    {
        $this->get('/protocols/99199')->assertNotFound();
    }

    public function testQueryPageShowsHowToCallIt()
    {
        $this->get('/queries/99200')
            ->assertOk()
            ->assertSee('<title>Test query - Ex-Machinis</title>', false)
            ->assertSee('N1 R L 99200 query')
            ->assertDontSee('SELECT 1');
    }

    public function testHumanDuration()
    {
        $this->assertSame('45 seconds', Protocol::humanDuration(45));
        $this->assertSame('1 minute', Protocol::humanDuration(60));
        $this->assertSame('2 hours 5 minutes', Protocol::humanDuration(7500));
        $this->assertSame('3 days 4 hours', Protocol::humanDuration(3 * 86400 + 4 * 3600 + 59));
    }
}
