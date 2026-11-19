<?php

namespace Tests\Feature;

use App\Agent;
use App\Services\Players;
use App\Services\PlayerShares;
use App\Services\Release;
use App\Services\Startups;
use App\Services\World;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Round 69's small bugs (#692): whole-number JSON floats, the /shares
 * address, phone tables, the companies list, Rising in a young world, the
 * wreck page's post-mortem and an empty events page.
 */
class RoundSixtyNineFixesTest extends TestCase
{
    use DatabaseTransactions;

    private function drone(string $name): Agent
    {
        $user = User::create(['name' => $name, 'email' => uniqid() . '@example.com', 'password' => bcrypt('secret-password')]);
        return Release::release($user, $name . ' Co', 'function launched() end');
    }

    public function testWholeNumberFloatsAreWholeNumbers()
    {
        config(['forge.new_rules' => true, 'forge.startup_hull_value' => 2000, 'forge.startup_fee' => 500,
            'forge.starting_credits' => 3000, 'forge.player_share_credits' => 5000, 'forge.bank_pay_days' => 30,
            'forge.share_range_low' => 0.8, 'forge.share_range_high' => 1.25, 'forge.hull_life_days' => 365,
            'forge.startup_max' => 5, 'forge.startup_mature_margin' => 0]);
        Mail::fake();
        $owner = Players::create('Float Founder', 'long-enough', 'float.founder@example.com');
        Players::verify($owner->user_id, sha1('float.founder@example.com'));
        $owner = User::where('user_id', $owner->user_id)->first();
        $id = Startups::start($owner, 'Float Co', 'function launched() wait(60) end')['company'];
        DB::table('companies')->where('company_id', $id)->update(['credits' => 100000 + Startups::grant()]);
        $this->assertTrue(Startups::mature($owner, $id)['ok']);
        $h = ['Authorization' => 'Bearer ' . Players::issueToken($owner), 'Content-Type' => 'application/json'];
        $sells = PlayerShares::prices($id)['sells_at'];
        $this->call('POST', "/api/v1/player-shares/$id/orders", [], [], [], $this->transformHeadersToServerVars($h),
            '{"side": "sell", "units": 2.0, "price": ' . $sells . '.0}')->assertOk()->assertJsonPath('standing', 2);
        $this->call('POST', "/api/v1/player-shares/$id/bank/sell", [], [], [], $this->transformHeadersToServerVars($h),
            '{"units": 1.0}')->assertOk()->assertJsonPath('units', 1);
        $this->call('POST', "/api/v1/player-shares/$id/bank/sell", [], [], [], $this->transformHeadersToServerVars($h),
            '{"units": 1.5}')->assertStatus(422);
    }

    public function testSharesRedirectsToTheStockExchange()
    {
        $this->get('/shares')->assertRedirect('/market/shares');
    }

    public function testTheCompaniesListReadsWell()
    {
        $working = $this->drone('Lister');
        DB::table('companies')->where('company_id', $working->company_id)->update(['credits' => 1234567,
            'founded_t' => World::gameNow() - 3 * 86400]);
        $ended = $this->drone('Gone');
        DB::table('companies')->where('company_id', $ended->company_id)->update(['eliminated_at' => World::gameNow()]);
        $rows = collect($this->getJson('/companies/search?name=' . urlencode(' Co'))->assertOk()->json('data'));
        $row = $rows->firstWhere('user_id', $working->company_id);
        $this->assertSame('1,234,567', $row['credits']);
        $this->assertEquals(3.0, $row['age'], 'game days');
        // Its status in its own column (#744), not "(ended)" on its name
        $this->assertSame('Gone Co', $rows->firstWhere('user_id', $ended->company_id)['name']);
        $this->assertSame('ended', $rows->firstWhere('user_id', $ended->company_id)['status']);
        $flags = $rows->pluck('ended')->all();
        $this->assertSame($flags, collect($flags)->sort()->values()->all(), 'working companies first, ended ones last');
    }

    public function testRisingSaysWhenTheWorldIsYoung()
    {
        DB::table('company_days')->delete();   // no daily history 7 game days old (#717)
        $this->get('/leaderboard')->assertOk()->assertSee('id="rising-young"', false)->assertDontSee('id="rising"', false);
    }

    public function testAWreckNamesItsCauseAndItsFailure()
    {
        $drone = $this->drone('Crasher');
        $now = World::gameNow();
        $a = DB::table('actions')->insertGetId(['drone' => $drone->agent_id, 'protocol' => 11100, 'multiplier' => 1,
            'aborted' => 0, 'outcome' => -2]);
        DB::table('events')->insert(['event_type' => 2, 'action' => $a, 'logged' => 1, 'outcome' => -2, 'drone' => $drone->agent_id,
            'resource' => 2061, 'installed' => 0, 'locked' => 0, 'new_quantity' => 0, 'new_cargo' => 0, 'game_t' => $now - 7200]);
        DB::table('agents')->where('agent_id', $drone->agent_id)->update(['wrecked_at' => $now, 'wreck_cause' => 'fuel']);
        $this->get('/spacecraft/' . $drone->agent_id)->assertOk()->assertSee('Post-mortem: It ran out of fuel')
            ->assertSee('id="post-mortem-failure"', false)->assertSee('class="failed-action"', false);
        $this->getJson('/api/v1/drones/' . $drone->agent_id)->assertJsonPath('post_mortem.headline', 'It ran out of fuel')
            ->assertJsonPath('post_mortem.failure.result', 'failed: ' . \App\Services\DronePage::OUTCOMES[-2]);
    }

    public function testAnEmptyEventsPageSaysWhy()
    {
        DB::table('observations')->where('drone', 0)->delete();
        $this->get('/events')->assertOk()->assertSee('id="events-empty"', false);
    }

    public function testTheCompanyPageTablesScrollOnAPhone()
    {
        $drone = $this->drone('Phone');
        $this->get('/companies/' . $drone->company_id)->assertOk()->assertSee('.table-scroll', false);
    }
}
