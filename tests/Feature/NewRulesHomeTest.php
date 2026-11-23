<?php

namespace Tests\Feature;

use App\Services\Players;
use App\Services\Release;
use App\Services\Startups;
use App\Services\World;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The home page for the new rules and a startup's road to maturity (#697)
 */
class NewRulesHomeTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['forge.new_rules' => true, 'forge.startup_hull_value' => 1000, 'forge.startup_fee' => 200,
            'forge.starting_credits' => 3000, 'forge.startup_mature_margin' => 500, 'forge.startup_max' => 5,
            'forge.startup_push_fee' => 100, 'forge.player_share_credits' => 5000]);
        DB::table('house_rules')->updateOrInsert(['id' => 1], ['tax_period' => 0, 'tax_grace' => 0, 'tax_flat' => 0,
            'tax_permille' => 0, 'plunder_share' => 50, 'compute_tax_per_million' => 15]);
        Mail::fake();
        Cache::flush();
    }

    private function player(string $name): User
    {
        $email = strtolower(str_replace(' ', '.', $name)) . '@example.com';
        $user = Players::create($name, 'long-enough', $email);
        Players::verify($user->user_id, sha1($email));
        return User::where('user_id', $user->user_id)->first();
    }

    public function testTheHomePageForTheNewRules()
    {
        $this->get('/')->assertOk()
            ->assertSee('Write the code.')->assertSee('Your drones run the company.')
            ->assertSee('Found a startup (free)')->assertSee('href="' . url('release') . '"', false)
            ->assertSee('A sketch of the Miner starter')
            // The steps' numbers come from the config: 1,000 + 3,000 + 200 owed, 4,700 to mature
            ->assertSee('it owes 4,200')->assertSee('At 4,700 credits')->assertSee('100 shares are yours')
            ->assertSee('id="economy-banner"', false)->assertSee('See the whole economy')
            ->assertSee('Make an account')->assertDontSee('No account needed');
        config(['forge.startup_fee' => 300, 'forge.startup_mature_margin' => 800]);
        $this->get('/')->assertOk()->assertSee('it owes 4,300')->assertSee('At 5,100 credits');
    }

    public function testTheClockLineIsHonestWhenSlowed()
    {
        $now = time();
        DB::table('game_clock')->updateOrInsert(['id' => 1], ['real_anchor' => $now, 'game_anchor' => $now, 'scale' => 60,
            'epoch' => $now - 10 * 86400, 'target_scale' => 1800, 'floor_scale' => 60, 'lag_seconds' => 400,
            'slices_per_minute' => 900, 'is_full' => 0, 'load_checked_at' => $now]);
        $this->get('/')->assertOk()->assertSee('slowed: a game day takes about 24 real minutes now, not the usual')->assertSee('48 real seconds, because the engines are behind (400 real seconds)');
    }

    public function testTheFeedLinksEveryNameAndGroupsFuelPurchases()
    {
        $user = User::create(['name' => 'Feed', 'email' => uniqid() . '@example.com', 'password' => bcrypt('secret-password')]);
        $a = Release::release($user, 'Fuel Buyers', 'function launched() end');
        $b = Release::release($user, 'Fuel Sellers', 'function launched() end');
        foreach (range(1, 4) as $i) {
            DB::table('trades')->insert(['resource' => Release::FUEL, 'units' => 10, 'credits' => 30, 'tax' => 0,
                'seller' => $b->agent_id, 'buyer' => $a->agent_id, 'location' => 3, 'game_t' => World::gameNow() - $i]);
        }
        $page = $this->get('/')->assertOk()->assertSee('4 fuel purchases at')->assertSee('40 fuel for 120 credits');
        $page->assertSee('href="' . url('spacecraft/' . $a->agent_id) . '"', false)
            ->assertSee('href="' . url('companies/' . $a->company_id) . '"', false);
        $grouped = World::feedGrouped(8);
        $this->assertSame(1, collect($grouped)->where('kind', 'fuel')->count(), 'one line for the four');
    }

    public function testTheOldRulesHomePageStays()
    {
        config(['forge.new_rules' => false]);
        $this->get('/')->assertOk()->assertSee('Program drones. Mine the solar system.')->assertSee("What's happening", false)
            ->assertDontSee('Road to maturity');
    }

    public function testTheStartupPageShowsItsRoadToMaturity()
    {
        $owner = $this->player('Road Owner');
        $id = Startups::start($owner, 'Road Co', 'function launched() wait(60) end')['company'];
        DB::table('companies')->where('company_id', $id)->update(['credits' => 1000]);
        $page = $this->get("/companies/$id")->assertOk()
            ->assertSee('id="company-badge"', false)->assertSee('Startup')->assertSee('Owned by Road Owner')
            ->assertSee('Road to maturity')->assertSee('Owes the bank')->assertSee('mature at 4,700')->assertSee('debt 4,200')
            ->assertSee('3,700 to go');
        // Nobody else sees its buttons
        $page->assertDontSee('id="startup-actions"', false)->assertDontSee('/startups/' . $id . '/close', false);
        $other = $this->player('Road Stranger');
        $this->actingAs($other)->get("/companies/$id")->assertOk()->assertDontSee('id="startup-actions"', false);

        // Its owner can close it and push new code, the first free; not yet mature it
        $this->actingAs($owner)->get("/companies/$id")->assertOk()->assertSee('id="startup-actions"', false)
            ->assertSee('action="' . url('startups/' . $id . '/close') . '"', false)
            ->assertSee('action="' . url('startups/' . $id . '/push') . '"', false)
            ->assertSee('Push new code (free)')
            ->assertDontSee('action="' . url('startups/' . $id . '/mature') . '"', false);
        DB::table('companies')->where('company_id', $id)->update(['credits' => 5000]);
        $this->actingAs($owner)->get("/companies/$id")->assertOk()->assertSee('It can mature now.')
            ->assertSee('action="' . url('startups/' . $id . '/mature') . '"', false);
        // The button works through the existing route
        $this->actingAs($owner)->post("/startups/$id/mature")->assertRedirect('/account#startups');
        $this->get("/companies/$id")->assertOk()->assertSee('Matured')->assertDontSee('Road to maturity');
    }

    public function testTheCreditCapIsMarked()
    {
        DB::table('house_rules')->where('id', 1)->update(['startup_credit_cap_permille' => 2000]);
        $owner = $this->player('Cap Owner');
        $id = Startups::start($owner, 'Cap Co', 'function launched() wait(60) end')['company'];
        $this->get("/companies/$id")->assertOk()->assertSee('cap 8,400');
    }
}
