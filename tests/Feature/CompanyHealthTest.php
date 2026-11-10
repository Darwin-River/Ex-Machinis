<?php

namespace Tests\Feature;

use App\Services\CompanyHealth;
use App\Services\Release;
use App\Services\World;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A company's health (#537): its drones, their fuel and how fast it falls,
 * and its credits, for investors deciding whether it will keep flying.
 */
class CompanyHealthTest extends TestCase
{
    use DatabaseTransactions;

    private function fuelEvent(int $drone, int $type, int $quantity, int $ago): void
    {
        DB::table('events')->insert(['event_type' => $type, 'action' => 0, 'outcome' => 1, 'drone' => $drone,
            'resource' => CompanyHealth::FUEL, 'installed' => 0, 'locked' => 0, 'new_quantity' => $quantity,
            'game_t' => World::gameNow() - $ago]);
    }

    public function testAFallingTankOnLowCreditsIsAtRisk()
    {
        $user = User::create(['name' => 'Health', 'email' => uniqid() . '@example.com', 'password' => bcrypt('secret-password')]);
        $agent = Release::release($user, 'Falling Fuel', 'function launched() end');
        $company = (int) $agent->company_id;
        $drone = (int) $agent->agent_id;
        // 40 fuel a day and a half ago; it burned 10 over the last game day and bought none
        $this->fuelEvent($drone, 1, 40, 36 * 3600);
        $this->fuelEvent($drone, 2, 36, 20 * 3600);
        $this->fuelEvent($drone, 2, 30, 2 * 3600);
        DB::table('companies')->where('company_id', $company)->update(['credits' => 200]);

        $h = CompanyHealth::of($company);
        $this->assertSame([1, 0, 30, 30, 10, 3, 200], [$h['working'], $h['wrecked'], $h['fuel'], $h['lowest'], $h['used'],
            $h['days'], $h['credits']]);
        $this->assertTrue($h['at_risk'], '3 days of fuel and 200 credits');

        // Buying fuel back up to where it was: no fall, so no deadline
        $this->fuelEvent($drone, 1, 40, 3600);
        $h = CompanyHealth::of($company);
        $this->assertSame(-1, $h['days']);
        $this->assertFalse($h['at_risk']);

        // The API and the company page show it
        $this->getJson('/api/v1/companies/' . $company)->assertOk()->assertJsonPath('health.working', 1)
            ->assertJsonPath('health.at_risk', false);
        $this->get('/companies/' . $company)->assertOk()->assertSee('Health: 1 working drone');
    }

    public function testACompanyWithNoWorkingDroneIsAtRisk()
    {
        $user = User::create(['name' => 'Health', 'email' => uniqid() . '@example.com', 'password' => bcrypt('secret-password')]);
        $agent = Release::release($user, 'All Wrecked', 'function launched() end');
        DB::table('agents')->where('agent_id', $agent->agent_id)->update(['wrecked_at' => World::gameNow()]);
        $h = CompanyHealth::of((int) $agent->company_id);
        $this->assertSame([0, 1], [$h['working'], $h['wrecked']]);
        $this->assertTrue($h['at_risk']);
    }
}
