<?php

namespace Tests\Feature;

use App\Services\FarMap;
use App\Services\ResourceMap;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** #952: a heat map over the solar-system map of where a resource is found */
class ResourceMapTest extends TestCase
{
    use DatabaseTransactions;

    private const WOLFITE = 1130;
    private const KUNSPAR = 1100;
    private const METALS_A1 = 2011;
    private const CERES = 191;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('far-map-ok');
        foreach ([self::WOLFITE => 'Wolfite (N)', self::KUNSPAR => 'Kunspar (N)', self::METALS_A1 => 'Metals (A1)',
                  1200 => 'Kronite (N)'] as $id => $name) {
            DB::table('resources')->insertOrIgnore(['id' => $id, 'name' => $name, 'description' => 'x', 'mass' => 1]);
        }
        // Refining wolfite makes Metals (A1)
        DB::table('protocols')->insertOrIgnore(['id' => 21130, 'name' => 'Refine wolfite', 'parameters' => 0, 'double_params' => 0]);
        DB::table('resource_effects')->where('protocol', 21130)->delete();
        DB::table('resource_effects')->insert([
            ['id' => 9921130, 'protocol' => 21130, 'resource' => self::WOLFITE, 'event_type' => 2, 'local' => 1, 'installed' => 0, 'locked' => 0,
             'deplete' => 1, 'abundancies' => 0, 'quantity' => 18, 'time' => 60],
            ['id' => 9921131, 'protocol' => 21130, 'resource' => self::METALS_A1, 'event_type' => 1, 'local' => 1, 'installed' => 0, 'locked' => 0,
             'deplete' => 0, 'abundancies' => 0, 'quantity' => 3, 'time' => 3600],
        ]);
        // Wolfite at the Moon and Phobos; kronite on Ceres; kunspar far away, this season at Io
        DB::table('objects')->insertOrIgnore(['object_id' => self::CERES, 'object_name' => 'Ceres', 'object_type' => 'Asteroid',
            'central_body_object_id' => 0, 'semimajor_axis' => 413953000, 'ascending_node_longitude' => 80.3,
            'periapsis_argument' => 73.5, 'mean_anomaly' => 0, 'mean_angular_motion' => 0.214]);
        DB::table('abundancies')->insert([
            ['location' => 10, 'resource' => self::WOLFITE, 'multiplier' => 5],
            ['location' => 11, 'resource' => self::WOLFITE, 'multiplier' => 5],
            ['location' => self::CERES, 'resource' => 1200, 'multiplier' => 5],
        ]);
        DB::table('far_seasons')->delete();
        DB::table('far_map')->delete();
        DB::table('far_minerals')->delete();
        DB::table('far_homes')->delete();
        DB::table('far_minerals')->insert(['resource' => self::KUNSPAR, 'homes' => 1, 'multiplier' => 10]);
        foreach ([13, 14] as $moon) {
            DB::table('far_homes')->insert(['resource' => self::KUNSPAR, 'object_id' => $moon]);
        }
        FarMap::apply();
    }

    private function body(array $map, string $name): array
    {
        return collect($map['bodies'])->firstWhere('name', $name);
    }

    public function testAMineralGlowsWhereItIsFound()
    {
        $map = ResourceMap::build(self::WOLFITE);
        // The Moon counts for Earth and Phobos for Mars, each 5
        $this->assertSame(5, $this->body($map, 'Earth')['heat']);
        $this->assertSame(5, $this->body($map, 'Mars')['heat']);
        $this->assertSame(0, $this->body($map, 'Jupiter')['heat']);
        $this->assertSame('Moon', $this->body($map, 'Earth')['places'][0]['name']);
        $this->assertSame(5, $map['max_heat']);
        // The asteroids are on the map too, placed like the planets
        $ceres = $this->body($map, 'Ceres');
        $this->assertSame('Asteroid', $ceres['type']);
        $this->assertSame(0, $ceres['heat']);
        $this->assertSame(5, $this->body(ResourceMap::build(1200), 'Ceres')['heat']);
    }

    public function testARefinedGoodShowsTheMineralsItComesFrom()
    {
        $this->assertSame([self::WOLFITE], ResourceMap::sources(self::METALS_A1));
        $map = ResourceMap::build(self::METALS_A1);
        $this->assertSame('refined', $map['resource']['kind']);
        $this->assertSame([self::WOLFITE], array_column($map['minerals'], 'resource'));
        // #981: what a run's ore refines to, 5 wolfite x 3/18 Metals (A1)
        $this->assertEquals(0.83, $this->body($map, 'Earth')['heat']);
        $this->assertSame(5, $this->body($map, 'Earth')['places'][0]['yield']);
        $options = collect(ResourceMap::options());
        $this->assertSame('refined', $options->firstWhere('resource', self::METALS_A1)['kind']);
        $this->assertSame('mineral', $options->firstWhere('resource', self::KUNSPAR)['kind'], 'a far mineral is offered too');
    }

    /** #981: a body glows by its best site's run now, crowding counted as the engine does (#974) */
    public function testCrowdedSitesDim()
    {
        DB::table('house_rules')->updateOrInsert(['id' => 1], ['tax_period' => 0, 'tax_grace' => 0, 'tax_flat' => 0,
            'tax_permille' => 0, 'plunder_share' => 50, 'crowd_capacity' => 10]);
        DB::table('mining_runs')->delete();
        $now = \App\Services\World::gameNow();
        // 19 drones mined wolfite at the Moon in the last game hour, and one two hours ago
        $rows = [];
        for ($i = 0; $i < 19; $i++) {
            $rows[] = ['object_id' => 10, 'resource' => self::WOLFITE, 'drone' => 900000 + $i, 'game_t' => $now - 60 * $i, 'weight' => 1000];
        }
        $rows[] = ['object_id' => 10, 'resource' => self::WOLFITE, 'drone' => 800000, 'game_t' => $now - 7200, 'weight' => 1000];
        // Two startups' drones (half a drone each) at Phobos
        $rows[] = ['object_id' => 11, 'resource' => self::WOLFITE, 'drone' => 800001, 'game_t' => $now, 'weight' => 500];
        $rows[] = ['object_id' => 11, 'resource' => self::WOLFITE, 'drone' => 800002, 'game_t' => $now, 'weight' => 500];
        DB::table('mining_runs')->insert($rows);

        $map = ResourceMap::build(self::WOLFITE);
        // A newcomer at the Moon makes 20: 5 x 10 / 20 = 2 a run; Phobos isn't crowded
        $moon = $this->body($map, 'Earth')['places'][0];
        $this->assertSame(['Moon', 19, 2, 2, 5], [$moon['name'], $moon['miners'], $moon['yield'], $moon['rate'], $moon['multiplier']]);
        $this->assertSame(2, $this->body($map, 'Earth')['heat']);
        $this->assertSame(5, $this->body($map, 'Mars')['heat']);
        $this->assertSame(2, $this->body($map, 'Mars')['places'][0]['miners']);
        $this->assertSame(5, $map['max_heat']);
        $this->assertSame(10, $map['crowd_capacity']);
        // A refined good dims the same way: 2 wolfite x 3/18
        $this->assertEquals(0.33, $this->body(ResourceMap::build(self::METALS_A1), 'Earth')['heat']);
        // The engine's formula: never below 1, and crowding off at capacity 0
        $this->assertSame(1, ResourceMap::runYield(5, 99000, 10));
        $this->assertSame(5, ResourceMap::runYield(5, 99000, 0));
        $this->assertSame(5, ResourceMap::runYield(5, 9000, 10));
        $this->assertSame(0, ResourceMap::runYield(0, 0, 10));

        $this->get('/map?resource=' . self::WOLFITE)->assertOk()->assertSee('Show where to mine')
            ->assertSee('</a> (Wolfite (N): 2; 5; 19)', false)->assertSee('Moon (Wolfite (N), 2 a run, 19 mining)', false);
        $api = $this->getJson('/api/v1/resource-map?resource=' . self::WOLFITE)->assertOk()->assertJsonPath('crowd_capacity', 10)->json();
        $earth = collect($api['bodies'])->firstWhere('name', 'Earth');
        $this->assertSame(['object_id' => 10, 'name' => 'Moon', 'mineral' => self::WOLFITE, 'mineral_name' => 'Wolfite (N)',
            'multiplier' => 5, 'miners' => 19, 'yield' => 2, 'rate' => 2], $earth['places'][0]);
    }

    public function testAFarMineralThisSeasonAndNext()
    {
        $now = ResourceMap::build(self::KUNSPAR);
        $this->assertTrue($now['minerals'][0]['moves']);
        $this->assertSame(10, $this->body($now, 'Jupiter')['heat']);
        $home = $this->body($now, 'Jupiter')['places'][0]['object_id'];
        // Next season's homes only once they are published
        $this->assertFalse(ResourceMap::build(self::KUNSPAR, true)['next']);
        DB::table('far_seasons')->where('season', 1)->update(['starts_at' => now()->subDays(25), 'ends_at' => now()->addDays(3)]);
        DB::table('far_seasons')->where('season', 2)->update(['starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(31)]);
        $next = ResourceMap::build(self::KUNSPAR, true);
        $this->assertTrue($next['next']);
        $this->assertSame(2, $next['season']);
        $this->assertSame((int) collect(FarMap::pick(2))->value('object_id'), $this->body($next, 'Jupiter')['places'][0]['object_id']);
        $this->assertIsInt($home);
    }

    public function testTheMapPageAndTheApi()
    {
        $this->get('/map')->assertOk()->assertSee('id="resource-map-form"', false)->assertDontSee('id="resource-places"', false);
        $this->get('/map?resource=' . self::WOLFITE)->assertOk()->assertSee('url(#map-heat)', false)
            ->assertSee('id="resource-places"', false)->assertSee('Where Wolfite (N) is found')->assertSee('Phobos');
        $this->get('/map?resource=' . self::METALS_A1)->assertOk()->assertSee('Where Metals (A1) comes from')->assertSee('Refined from Wolfite (N)');
        $this->get('/map?resource=999999')->assertOk()->assertDontSee('id="resource-places"', false);

        $this->getJson('/api/v1/resource-map')->assertOk()->assertJsonStructure(['options' => [['resource', 'name', 'kind']]]);
        $this->getJson('/api/v1/resource-map?resource=' . self::WOLFITE)->assertOk()
            ->assertJsonPath('resource.name', 'Wolfite (N)')->assertJsonPath('max_heat', 5)
            ->assertJsonStructure(['bodies' => [['id', 'name', 'type', 'x', 'y', 'heat', 'places']]]);
        $this->getJson('/api/v1/resource-map?resource=999999')->assertNotFound();
    }
}
