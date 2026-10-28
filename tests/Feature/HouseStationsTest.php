<?php

namespace Tests\Feature;

use App\Services\HouseStations;
use App\Services\Leaderboard;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * House station drones (#380): the House trades as drones, one at each
 * exchange, made by forge:stations where missing.
 */
class HouseStationsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([5911 => 'Import Station', 5921 => 'Export Station', 2061 => 'Fuel (A1)'] as $id => $name) {
            DB::table('resources')->insertOrIgnore(['id' => $id, 'name' => $name, 'mass' => $id === 2061 ? 1 : 0,
                'capacity' => 0, 'slot_size' => 0]);
        }
        DB::table('hulls')->insertOrIgnore(['id' => HouseStations::HULL, 'name' => 'Drop Station',
            'cargo_capacity' => 1000000, 'a_slots' => 0, 'b_slots' => 0, 'c_slots' => 0, 'd_slots' => 0, 'e_slots' => 0]);
        DB::table('stations')->delete();
        DB::table('stations')->insert([
            ['id' => 1, 'name' => 'Earth Exchange', 'object_id' => 3, 'budget_cap' => 1000, 'refill_per_day' => 1000],
            ['id' => 2, 'name' => 'Moon Depot', 'object_id' => 10, 'budget_cap' => 1000, 'refill_per_day' => 1000],
        ]);
    }

    public function testEachExchangeGetsAStationDroneOnce()
    {
        $this->assertSame(0, Artisan::call('forge:stations'));
        $house = DB::table('companies')->where('house', 1)->first();
        $this->assertNotNull($house);
        $this->assertEquals(HouseStations::CREDITS, $house->credits);

        $drones = DB::table('agents')->where('company_id', $house->company_id)->orderBy('station_id')->get();
        $this->assertSame([1, 2], $drones->pluck('station_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame([3, 10], $drones->pluck('object_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame('Earth Exchange', $drones[0]->name);
        $this->assertEquals(HouseStations::HULL, $drones[0]->hull_type);

        // Its modules and fuel aboard, and the station program, then launched
        $aboard = DB::table('events')->where('drone', $drones[0]->agent_id)->pluck('resource')->map(fn ($r) => (int) $r)->all();
        $this->assertEqualsCanonicalizing([5911, 5921, 2061], $aboard);
        $codes = DB::table('commands')->where('agent_id', $drones[0]->agent_id)->orderBy('id')->pluck('code')->all();
        $this->assertSame('<launched>', end($codes));
        $this->assertStringContainsString('5911 perform', implode("\n", $codes));

        // Again: nothing new
        Artisan::call('forge:stations');
        $this->assertSame(1, DB::table('companies')->where('house', 1)->count());
        $this->assertSame(2, DB::table('agents')->where('company_id', $house->company_id)->count());
    }

    public function testTheHouseIsNotOnTheLeaderboard()
    {
        HouseStations::ensure();
        $house = DB::table('companies')->where('house', 1)->value('company_id');
        $this->assertArrayNotHasKey($house, Leaderboard::wealth());
    }
}
