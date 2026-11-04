<?php

namespace Tests\Feature;

use App\Services\Release;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Live queries (#380): /machine/query runs a game query as a drone would.
 * (The Machine page, a FORTH machine in the browser, went with FORTH.)
 */
class LiveQueryTest extends TestCase
{
    use DatabaseTransactions;

    private function liveSetup(): int
    {
        $user = User::where('email', 'a@example.com')->first() ?? User::forceCreate(['name' => 'A', 'email' => 'a@example.com', 'password' => 'x']);
        $agent = Release::release($user, 'Askers', 'function launched() end');
        DB::table('queries')->insert(['id' => 9901, 'name' => 'Test fields', 'description' => 'test', 'parameters' => 3,
            'script' => "SELECT [value_1] AS n, [dvalue_2] AS d_big, 'Moon' AS place, [drone_id] AS me UNION ALL "
                . "SELECT -2, 70000, 'Mars', 1;"]);
        DB::table('queries')->insert(['id' => 9902, 'name' => 'Nothing', 'description' => 'test', 'parameters' => 0,
            'script' => 'SELECT 1 AS n FROM queries WHERE id < 0;']);
        return $agent->agent_id;
    }

    public function testALiveQueryLeavesTheBytesADroneGets()
    {
        $drone = $this->liveSetup();
        // Parameters in the order query takes them off the stack: [value_1],
        // then a double's high and low cells ([dvalue_2]: 1 and 4464 = 70,000)
        $answer = $this->getJson('/machine/query?' . http_build_query(['id' => 9901, 'drone' => $drone, 'size' => 120,
            'v' => [5, 1, 4464]]))->assertOk()->json();
        $this->assertEquals(2, $answer['code']);
        $bytes = array_map('ord', str_split(base64_decode($answer['bytes'])));
        // n: one cell, low byte first; d_big: high cell then low (70,000 =
        // 0x00011170); place: a counted string; me: one cell
        $row1 = [5, 0, 1, 0, 0x70, 0x11, 4, ord('M'), ord('o'), ord('o'), ord('n'), $drone & 0xFF, $drone >> 8];
        $row2 = [0xFE, 0xFF, 1, 0, 0x70, 0x11, 4, ord('M'), ord('a'), ord('r'), ord('s'), 1, 0];
        $this->assertEquals(array_merge($row1, $row2), $bytes);

        // Only whole rows: room for one
        $this->assertEquals(1, $this->getJson('/machine/query?' . http_build_query(['id' => 9901, 'drone' => $drone,
            'size' => 20, 'v' => [5, 1, 4464]]))->json('code'));
        // No rows: -2; no such query: -3; too few parameters: -1
        $this->assertEquals(-2, $this->getJson("/machine/query?id=9902&drone=$drone&size=120")->json('code'));
        $this->assertEquals(-3, $this->getJson("/machine/query?id=9999&drone=$drone&size=120")->json('code'));
        $this->assertEquals(-1, $this->getJson("/machine/query?id=9901&drone=$drone&size=120&v[]=1")->json('code'));
        // The rows by name, and the whole answer when no size is given
        $this->assertEquals('Moon', $answer['rows'][0]['place']);
        $this->assertEquals(2, $this->getJson("/machine/query?id=9901&drone=$drone&v[]=5&v[]=1&v[]=4464")->json('code'));
        // Each query's parameters
        $this->getJson('/machine/queries')->assertOk()->assertJsonPath('9901.parameters', 3);
    }

    public function testPerformTakesAMultiplierProtocolsAmountToo()
    {
        // Push and pull take N after their parameters (#380 round 29: the
        // Machine left it on the stack)
        DB::table('protocols')->updateOrInsert(['id' => 991], ['name' => 'Test push', 'parameters' => 2, 'bulk_modifier' => 100,
            'description' => 'test', 'observable' => 1, 'reportable' => 1, 'multiplier' => 1]);
        $this->getJson('/machine/protocols')->assertOk()->assertJsonPath('991.parameters', 3);
    }

    public function testALiveQueryNeedsARealDroneAndNumbers()
    {
        $this->liveSetup();
        $this->getJson('/machine/query?id=9901&drone=999999&size=120&v[]=1&v[]=2&v[]=3')->assertStatus(422);
        $this->getJson('/machine/query?id=9901&drone=1&size=120&v[]=1;DROP')->assertStatus(422);
    }
}
