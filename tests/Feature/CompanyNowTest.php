<?php

namespace Tests\Feature;

use App\Services\CompanyNow;
use App\Services\MarketHistory;
use App\Services\Players;
use App\Services\Startups;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** "Your company now" (#770): each startup's progress, a forecast and a Mature button on every page */
class CompanyNowTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['forge.new_rules' => true, 'forge.startup_max' => 5, 'forge.startup_mature_margin' => 500]);
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

    public function testTheStripShowsProgressAForecastAndMature()
    {
        $p = $this->player('Now Player');
        $id = Startups::start($p, 'Now Co', 'function launched() wait(60) end')['company'];
        $c = DB::table('companies')->where('company_id', $id)->first();
        $need = (int) $c->debt + 500;
        // Two game days of history are too few for a forecast (#878)
        $today = MarketHistory::today();
        DB::table('companies')->where('company_id', $id)->update(['credits' => $need - 1000]);
        DB::table('company_days')->insert(['company_id' => $id, 'day' => $today - 2, 'credits' => $need - 1200, 'net_wealth' => 0, 'drones' => 1]);
        $young = CompanyNow::forecast(DB::table('companies')->where('company_id', $id)->first(), $need, $need - 1000, false);
        $this->assertNull($young['pace']);
        $this->assertNull($young['game_t'], 'no "matures in 11 real minutes" from two days');
        // Earning 100 a game day over the last 6 game days
        DB::table('company_days')->where('company_id', $id)->delete();
        DB::table('company_days')->insert(['company_id' => $id, 'day' => $today - 6, 'credits' => $need - 1600, 'net_wealth' => 0, 'drones' => 1]);
        DB::table('company_days')->insert(['company_id' => $id, 'day' => $today - 1, 'credits' => $need - 1100, 'net_wealth' => 0, 'drones' => 1]);
        CompanyNow::forget($p);
        $s = CompanyNow::for($p)['startups'][0];
        $this->assertSame([$need - 1000, $need, false, 100], [$s['credits'], $s['need'], $s['can_mature'], $s['pace']]);
        $this->assertGreaterThan(0, $s['minutes'], 'about 10 game days at 100 a day');
        $this->actingAs($p)->get('/market')->assertOk()->assertSee('id="company-now"', false)->assertSee('Now Co')
            ->assertSee('matures in about')->assertDontSee('>Claim my 100 shares</button>', false);
        // Its Road to maturity projects the date (#744), from the same pace
        $f = CompanyNow::forecast(DB::table('companies')->where('company_id', $id)->first(), $need, $need - 1000, false);
        $this->assertNotNull($f['game_t']);
        $this->actingAs($p)->get('/companies/' . $id)->assertOk()->assertSee('id="road-forecast"', false)
            ->assertSee(\App\Services\GameDate::at($f['game_t'], false));

        // Enough to mature: the button, on any page; it matures from there
        DB::table('companies')->where('company_id', $id)->update(['credits' => $need]);
        CompanyNow::forget($p);
        $this->actingAs($p)->get('/companies')->assertOk()->assertSee('>Claim my 100 shares</button>', false)
            ->assertSee(url('startups/' . $id . '/mature'), false);
        $this->actingAs($p)->post('/startups/' . $id . '/mature')->assertRedirect();
        $this->assertNull(CompanyNow::for($p), 'nothing left to show once matured');
    }

    public function testNoStripForVisitorsOrPlayersWithNothing()
    {
        $this->get('/market')->assertOk()->assertDontSee('id="company-now"', false);
        $this->actingAs($this->player('Empty Player'))->get('/market')->assertOk()->assertDontSee('id="company-now"', false);
    }
}
