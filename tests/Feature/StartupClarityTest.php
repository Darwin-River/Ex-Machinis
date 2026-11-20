<?php

namespace Tests\Feature;

use App\Services\Leaderboard;
use App\Services\Players;
use App\Services\Release;
use App\Services\Startups;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Round 77's startup-page fixes: old addresses, the 404 page, the times to mature (#868, #870, #862) */
class StartupClarityTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['forge.new_rules' => true]);
    }

    public function testOldAndBareStartupAddressesRedirect()
    {
        $this->get('/startups')->assertRedirect('/startups/race');
        $this->get('/seedlings')->assertRedirect('/startups/race');
        $this->get('/seedlings/race')->assertRedirect('/startups/race');
        $this->get('/first-seedling')->assertRedirect('/getting-started');
        $this->get('/api/v1/seedlings')->assertRedirect('/api/v1/startups');
        $this->get('/api/v1/seedlings/race')->assertRedirect('/api/v1/startups/race');
    }

    public function testAMissingPageKeepsTheSitesLook()
    {
        $this->get('/no-such-page-at-all')->assertNotFound()->assertSee('Page not found')
            ->assertSee(url('getting-started'), false)->assertSee('site-footer', false);
    }

    public function testTheGuideAndTheCardsGiveTheSameTimes()
    {
        $days = collect(Release::STARTER_CARDS)->pluck('days')->filter();
        $from = (int) round($days->min(fn ($d) => $d[0]) * 0.8);
        $to = (int) round($days->max(fn ($d) => $d[1]) * 0.8);
        $this->get('/getting-started')->assertOk()->assertSee("about $from to $to real minutes")
            ->assertSee('Matures in')->assertDontSee('Matured in');
        $this->get('/release')->assertOk()->assertSee('matures in about')->assertDontSee('matured in about');
    }

    /** #882: a startup's rank at its age counts only companies founded as startups */
    public function testAStartupIsRankedAmongStartupsOnly()
    {
        Mail::fake();
        config(['forge.startup_max' => 5]);
        $email = 'ranked@example.com';
        $made = Players::create('Ranked', 'long-enough', $email);
        Players::verify($made->user_id, sha1($email));
        $p = User::where('user_id', $made->user_id)->first();
        $id = Startups::start($p, 'Ranked Co', 'function launched() wait(60) end')['company'];
        $old = Release::release(User::create(['name' => 'Old', 'email' => 'old-world@example.com', 'password' => bcrypt('long-enough')]),
            'Old World Co', ': launched ;')->company_id;
        DB::table('companies')->where('company_id', $old)->update(['credits' => 900000]);

        $rank = Leaderboard::peerRank($id);
        $this->assertSame('startups', $rank['among']);
        $ids = array_column(Leaderboard::atAge($rank['age'], null, null, true)['rows'], 'company_id');
        $this->assertContains($id, $ids);
        $this->assertNotContains($old, $ids, 'an old-world company is no peer of a startup');
        $this->assertSame('all', Leaderboard::peerRank($old)['among']);
        $this->get('/leaderboard?age=' . $rank['age'] . '&startups=1')->assertOk()->assertSee('startups only');
    }
}
