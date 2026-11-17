<?php

namespace Tests\Feature;

use App\Services\Recipes;
use App\Services\TechMap;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #804: the tech map, every manufacturing pathway from the ores drones dig to
 * what they build, with each recipe's ratios, tool, Lua call, time and fuel.
 * #805: the fuel a recipe burns is its fuel, not less of what it makes.
 */
class TechMapTest extends TestCase
{
    use DatabaseTransactions;

    private int $effect = 990000;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([1110 => 'Yalspar (N)', 2011 => 'Metals (A1)', 2031 => 'Inorganics (A1)', 2041 => 'Organics (A1)',
                  2021 => 'Alloys (A1)', 2061 => 'Fuel (A1)', 5131 => 'Worm (A1)', 5221 => 'Refinery (A1)',
                  5201 => 'Smelter (A1)', 5211 => 'Chemical Reactor (A1)'] as $id => $name) {
            DB::table('resources')->insertOrIgnore(['id' => $id, 'name' => $name, 'mass' => 1, 'capacity' => 0, 'slot_size' => 0]);
        }
        // Dig yalspar with a Worm; refine 16 of it into 3 metals and an organic;
        // 2 metals make 2 alloys on a Smelter; an organic and 2 inorganics make 30 fuel
        $this->protocol(11110, 'Mine yalspar', 'mine(yalspar), or perform(11110): Mine yalspar.', 5131, 1, 3600, [], [[1110, 1, true]]);
        $this->protocol(21110, 'Refine yalspar', 'refine(yalspar), or perform(21110): Refines yalspar.', 5221, 2, 7200,
            [[1110, 16]], [[2011, 3], [2041, 1]]);
        $this->protocol(12021, 'Make Alloys (A1)', 'make(alloys_a1), or perform(12021): Makes alloys.', 5201, 2, 7200,
            [[2011, 2]], [[2021, 2]]);
        $this->protocol(12061, 'Make Fuel (A1)', 'make(fuel), or perform(12061): Makes fuel.', 5211, 2, 7200,
            [[2041, 1], [2031, 2]], [[2061, 30]]);
    }

    /** A protocol as the game data writes one: lock the tool, burn fuel, use the inputs, give the tool back, make */
    private function protocol(int $id, string $name, string $description, int $tool, int $fuel, int $seconds, array $uses, array $makes): void
    {
        DB::table('protocols')->insert(['id' => $id, 'name' => $name, 'parameters' => 0, 'description' => $description, 'multiplier' => 0]);
        $rows = [[$tool, 1, 1, 0, 1, 0, 0], [$tool, 2, 0, 1, 1, 0, 0], [2061, 2, 0, 1, $fuel, 0, 0]];
        foreach ($uses as [$r, $n]) {
            $rows[] = [$r, 2, 0, 1, $n, 1, 0];
        }
        $rows[] = [$tool, 1, 0, 0, 1, $seconds - 1, 0];
        $rows[] = [$tool, 2, 1, 1, 1, $seconds - 1, 0];
        foreach ($makes as $m) {
            $rows[] = [$m[0], 1, 0, 0, $m[1], $seconds, empty($m[2]) ? 0 : 1];
        }
        foreach ($rows as [$r, $type, $locked, $deplete, $n, $time, $mined]) {
            DB::table('resource_effects')->insert(['id' => $this->effect++, 'drone' => 0, 'resource' => $r, 'protocol' => $id,
                'event_type' => $type, 'local' => 1, 'installed' => 0, 'locked' => $locked, 'deplete' => $deplete,
                'abundancies' => $mined, 'quantity' => $n, 'time' => $time]);
        }
    }

    public function testMakeFuelMakesThirtyAndBurnsTwo()
    {
        $fuel = Recipes::making(2061)[0];
        $this->assertSame([['resource' => 2061, 'name' => 'Fuel (A1)', 'units' => 30]], $fuel['makes']);
        $this->assertSame(2, $fuel['fuel']);
        $this->assertArrayNotHasKey('mined', $fuel);
        $this->getJson('/api/v1/recipes?resource=2061')->assertOk()->assertJsonPath('0.makes.0.units', 30)->assertJsonPath('0.fuel', 2);
    }

    public function testMiningIsAProcessButNotARecipe()
    {
        $this->assertTrue(Recipes::processes()[11110]['mined']);
        $this->assertArrayNotHasKey(11110, Recipes::all());
    }

    public function testTheMapLaysResourcesOutInStepsWithTheirCallsAndTools()
    {
        $map = TechMap::build();
        $res = collect($map['resources'])->keyBy('id');

        $this->assertSame(0, $res[1110]['step']);
        $this->assertSame(1, $res[2011]['step']);
        $this->assertSame(2, $res[2021]['step']);
        $this->assertSame([11110], $res[1110]['made_by']);
        $this->assertSame([21110], $res[1110]['used_in']);
        $this->assertSame([11110], $res[5131]['tool_for']);
        $this->assertSame('Ore', $res[1110]['family']);
        $this->assertSame(url('resources/2021'), $res[2021]['url']);

        $mine = $map['processes'][11110];
        $this->assertSame(['mine', 'mine(yalspar)', 'perform(11110)'], [$mine['kind'], $mine['call'], $mine['perform']]);
        $refine = $map['processes'][21110];
        $this->assertSame('refine', $refine['kind']);
        $this->assertSame([[1110, 16]], $refine['uses']);
        $this->assertSame([[2011, 3], [2041, 1]], $refine['makes']);
        $alloys = $map['processes'][12021];
        $this->assertSame(['make', 5201, 2, 2.0], [$alloys['kind'], $alloys['tool'], $alloys['fuel'], (float) $alloys['hours']]);

        // One alloy takes one metal, a Smelter hour and one fuel, down to refined goods
        $this->assertEquals([[2011, 1]], $res[2021]['tree']['goods']);
        $this->assertEquals(1, $res[2021]['tree']['fuel']);
        $this->assertEquals([[5201, 1]], $res[2021]['tree']['tools']);
    }

    public function testCallsComeFromTheDescription()
    {
        $this->assertSame(['make(3201)', 'perform(13201)'], TechMap::calls(13201, 'make(3201), or perform(13201): Uses 2 Alloys.'));
        $this->assertSame(['perform(17, P, M, R)', 'perform(17, P, M, R)'], TechMap::calls(17, 'perform(17, P, M, R): Places an order.'));
        $this->assertSame(['perform(99)', 'perform(99)'], TechMap::calls(99, 'Not performed by drones.'));
    }

    public function testThePageShowsPicturesLinksAndFormulas()
    {
        $page = $this->get('/technology')->assertOk()
            ->assertSee('<title>Tech map - Ex-Machinis</title>', false)
            ->assertSee('Dug: ores')
            ->assertSee('<a class="card" href="' . url('resources/2021') . '" data-id="2021">', false)
            ->assertSee('<a href="' . url('protocols/12021') . '"><code>make(alloys_a1)</code></a>', false)
            ->assertSee('16 × Yalspar (N)')
            ->assertSee('3 × Metals (A1) + 1 × Organics (A1)')
            ->assertSee('30 × Fuel (A1)');
        // Each resource's own picture, where it has one
        if (is_file(public_path('images/resources/2021-thumb.webp'))) {
            $page->assertSee(url('images/resources/2021-thumb.webp'), false);
        }
        $this->get('/tech-map')->assertRedirect('/technology');
    }

    public function testResourceAndProtocolPagesLinkToTheMap()
    {
        $this->get('/resources/2021')->assertOk()->assertSee(url('technology') . '#r2021', false);
        $this->get('/protocols/12021')->assertOk()->assertSee(url('technology') . '#r2021', false)
            ->assertSee('<a href="' . url('/resources/2011') . '">Metals (A1)</a>', false);
    }
}
