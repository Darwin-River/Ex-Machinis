<?php

namespace Tests\Feature;

use App\Services\HouseMarket;
use App\Services\HouseStations;
use App\Services\Players;
use App\Services\Startups;
use App\Services\World;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The planets (#927): their import price rises with how much everyone bought
 * from them recently and fades back; fuel stays at its list price; they
 * step back (a wider spread) where players trade among themselves, slowly,
 * and come back fast.
 */
class PlanetsMarketTest extends TestCase
{
    use DatabaseTransactions;

    private const WOLFITE = 1130;
    private const FUEL = 2061;

    protected function setUp(): void
    {
        parent::setUp();
        config(['forge.new_rules' => true, 'forge.startup_max' => 5]);
        Mail::fake();
        foreach ([5911 => 'Import Station', 5921 => 'Export Station', self::FUEL => 'Fuel (A1)', self::WOLFITE => 'Wolfite (N)'] as $id => $name) {
            DB::table('resources')->insertOrIgnore(['id' => $id, 'name' => $name, 'mass' => 1, 'capacity' => 0, 'slot_size' => 0]);
        }
        DB::table('hulls')->insertOrIgnore(['id' => HouseStations::HULL, 'name' => 'Drop Station',
            'cargo_capacity' => 1000000, 'a_slots' => 0, 'b_slots' => 0, 'c_slots' => 0, 'd_slots' => 0, 'e_slots' => 0]);
        DB::table('stations')->delete();
        DB::table('stations')->insert(['id' => 1, 'name' => 'Earth Exchange', 'object_id' => 3, 'budget_cap' => 1000, 'refill_per_day' => 1000]);
        Artisan::call('forge:stations');
        DB::table('planet_spreads')->delete();
    }

    private function station(): object
    {
        return DB::table('agents')->where('station_id', 1)->first();
    }

    private function goods(): array
    {
        return [(object) ['resource' => self::WOLFITE, 'side' => 2, 'daily_units' => 10],
                (object) ['resource' => self::WOLFITE, 'side' => 1, 'daily_units' => 10],
                (object) ['resource' => self::FUEL, 'side' => 2, 'daily_units' => 10]];
    }

    private function trade(int $seller, int $buyer, int $resource, int $units, int $ago = 0): void
    {
        DB::table('trades')->insert(['resource' => $resource, 'units' => $units, 'credits' => 100 * $units, 'seller' => $seller,
            'buyer' => $buyer, 'location' => 3, 'game_t' => World::gameNow() - $ago]);
    }

    public function testTheImportPriceRisesWithRecentBuyingAndFuelStaysFlat()
    {
        $this->assertSame(100, HouseMarket::importPrice(100, ['recent' => 0, 'daily' => 10, 'spread' => 0]));
        $this->assertSame(200, HouseMarket::importPrice(100, ['recent' => 10, 'daily' => 10, 'spread' => 0]), 'a day\'s worth doubles it');
        $this->assertSame(300, HouseMarket::importPrice(100, ['recent' => 1000, 'daily' => 10, 'spread' => 0]), 'at most 3 times');
        $this->assertSame(100, HouseMarket::importPrice(100, ['recent' => 1000, 'daily' => 10, 'spread' => 900, 'fuel' => true]));

        $s = $this->station();
        $buyer = (int) DB::table('agents')->where('station_id', null)->value('agent_id') ?: (int) $s->agent_id + 1;
        $this->trade((int) $s->agent_id, $buyer, self::WOLFITE, 10);
        $this->trade((int) $s->agent_id, $buyer, self::FUEL, 10);
        $p = HouseMarket::planetPrices(DB::connection(), (int) $s->agent_id, $this->goods());
        $this->assertEqualsWithDelta(10, $p[self::WOLFITE]['recent'], 0.1);
        $this->assertEqualsWithDelta(200, HouseMarket::importPrice(100, $p[self::WOLFITE]), 1);
        $this->assertSame(100, HouseMarket::importPrice(100, $p[self::FUEL]), 'fuel is flat');

        // A day later most of it has faded
        DB::table('trades')->where('seller', $s->agent_id)->update(['game_t' => World::gameNow() - 2 * 86400]);
        $p = HouseMarket::planetPrices(DB::connection(), (int) $s->agent_id, $this->goods());
        $this->assertLessThan(115, HouseMarket::importPrice(100, $p[self::WOLFITE]));
    }

    public function testThePlanetsStepBackWherePlayersTradeAndComeBackFast()
    {
        $s = $this->station();
        $drones = [];
        $users = [];
        foreach (['Seller Founder', 'Buyer Founder'] as $name) {
            $email = strtolower(str_replace(' ', '.', $name)) . '@example.com';
            $u = Players::create($name, 'long-enough', $email);
            Players::verify($u->user_id, sha1($email));
            $user = \App\User::where('user_id', $u->user_id)->first();
            $users[] = $user;
            $id = Startups::start($user, "$name Co", 'function launched() wait(60) end')['company'];
            $drones[] = (int) DB::table('agents')->where('company_id', $id)->value('agent_id');
        }
        // 90 of 100 units here between two players' companies, 10 with the planets
        $this->trade($drones[0], $drones[1], self::WOLFITE, 90);
        $this->trade((int) $s->agent_id, $drones[1], self::WOLFITE, 10);
        $p = HouseMarket::planetPrices(DB::connection(), (int) $s->agent_id, $this->goods());
        $this->assertSame(0, $p[self::WOLFITE]['spread'], 'it steps back over days, not at once');
        DB::table('planet_spreads')->update(['updated_t' => World::gameNow() - HouseMarket::SPREAD_OUT_DAYS * 86400]);
        $p = HouseMarket::planetPrices(DB::connection(), (int) $s->agent_id, $this->goods());
        $this->assertSame(900, $p[self::WOLFITE]['spread']);
        $this->assertSame(0, $p[self::FUEL]['spread'], 'fuel never steps back');
        // A wider spread: it asks more (and bids less: HouseMarket::wanted)
        $this->assertGreaterThan(110 * 1.4, HouseMarket::importPrice(100, $p[self::WOLFITE]));

        // Players stop (only wash trades between one founder's own companies,
        // which don't count): back within a game day
        DB::table('trades')->delete();
        $twin = Startups::start($users[0], 'Seller Twin Co', 'function launched() wait(60) end')['company'];
        $this->trade($drones[0], (int) DB::table('agents')->where('company_id', $twin)->value('agent_id'), self::WOLFITE, 500);
        DB::table('planet_spreads')->update(['updated_t' => World::gameNow() - 86400]);
        $p = HouseMarket::planetPrices(DB::connection(), (int) $s->agent_id, $this->goods());
        $this->assertSame(0, $p[self::WOLFITE]['spread']);
    }
}
