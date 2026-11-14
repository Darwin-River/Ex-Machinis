<?php

namespace Tests\Feature;

use App\Services\Dealings;
use App\Services\Release;
use App\Services\World;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Who a company deals with (#639): its counterparties by name, with deals
 * between two companies of the same owner flagged. The minimum order life is
 * checked by test_seedling.c; private broadcasts by the game-data queries.
 */
class DealingsTest extends TestCase
{
    use DatabaseTransactions;

    private function company(string $name, ?int $owner): array
    {
        $user = User::create(['name' => $name, 'email' => uniqid() . '@example.com', 'password' => bcrypt('secret-password')]);
        $agent = Release::release($user, $name, 'function launched() end');
        DB::table('companies')->where('company_id', $agent->company_id)->update(['owner_id' => $owner]);
        return [(int) $agent->company_id, (int) $agent->agent_id];
    }

    private function trade(int $seller, int $buyer, int $units, int $credits): void
    {
        DB::table('trades')->insert(['time' => now(), 'resource' => 2011, 'units' => $units, 'credits' => $credits,
            'seller' => $seller, 'buyer' => $buyer, 'location' => 3, 'game_t' => World::gameNow(), 'tax' => 0]);
    }

    public function testCounterpartiesAndSameOwnerFlags()
    {
        config(['forge.new_rules' => true]);
        [$a, $aDrone] = $this->company('Alpha Owned', 900001);
        [$b, $bDrone] = $this->company('Beta Owned', 900001);
        [$c, $cDrone] = $this->company('Gamma Other', 900002);
        $this->trade($aDrone, $bDrone, 10, 10);       // sold cheap to its owner's other company
        $this->trade($cDrone, $aDrone, 5, 500);
        $this->trade($aDrone, $cDrone, 2, 300);
        DB::table('agents')->where('agent_id', $cDrone)->update(['wrecked_at' => World::gameNow(), 'plundered_at' => World::gameNow(),
            'plundered_by' => $aDrone]);

        $d = collect(Dealings::of($a))->keyBy('company_id');
        $this->assertCount(2, $d);
        $this->assertTrue($d[$b]['same_owner']);
        $this->assertSame(1, $d[$b]['trades']);
        $this->assertFalse($d[$c]['same_owner']);
        $this->assertSame(2, $d[$c]['trades'], 'either way round');
        $this->assertSame(800, $d[$c]['credits']);
        $this->assertSame(1, $d[$c]['plunders']);
        $this->assertSame($b, Dealings::of($a)[0]['company_id'], 'same-owner deals first');
        $this->assertSame(1, collect(Dealings::of($c))->firstWhere('company_id', $a)['plunders'], 'its wreck was plundered');

        $this->get('/companies/' . $a)->assertOk()->assertSee('Dealt with')->assertSee('Same owner')->assertSee('Gamma Other');
        $this->getJson("/api/v1/companies/$a/dealings")->assertOk()->assertJsonPath('dealings.0.same_owner', true);

        // Off until the new rules
        config(['forge.new_rules' => false]);
        $this->get('/companies/' . $a)->assertOk()->assertDontSee('id="dealings"', false);
        $this->getJson("/api/v1/companies/$a/dealings")->assertNotFound();
    }

    public function testBroadcastsArePrivateToACompany()
    {
        // The game-data queries that find and read broadcasts are limited to the reader's company (#380)
        $built = file_get_contents(base_path('game-engine/sql/game-data/build_game_data.py'));
        $this->assertStringContainsString('b.company_id={MY_CO}', $built);
        $this->assertStringContainsString('b.company_id = {MY_CO}', $built);
    }
}
