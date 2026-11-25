<?php

namespace Tests\Feature;

use App\Services\FirstSteps;
use App\Services\GuestDrive;
use App\Services\Ownership;
use App\Services\Pipeline;
use App\Services\PlayerShares;
use App\Services\Players;
use App\Services\Release;
use App\Services\Replay;
use App\Services\Startups;
use App\Services\World;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The Player Ladder (#1039): investing opens with a matured company and
 * "Claim my shares" leads to it (#1040); signing up goes on to Found with the
 * drone a visitor test drove (#1041); a guest test drive and the home page's
 * fixed replay (#1042); the path in eight steps (#1043); a first share and a
 * first loan (#1044); a visitor's menus and the step counts (#1045).
 */
class PlayerLadderTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['forge.new_rules' => true, 'forge.startup_hull_value' => 2000, 'forge.startup_fee' => 500,
            'forge.starting_credits' => 3000, 'forge.player_share_credits' => 5000, 'forge.bank_pay_days' => 30,
            'forge.startup_max' => 5, 'forge.startup_mature_margin' => 0, 'forge.lend_min_credits' => 5000]);
        Mail::fake();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        @unlink(Replay::path('miner'));
        parent::tearDown();
    }

    private function player(string $name): User
    {
        $email = strtolower(str_replace(' ', '.', $name)) . '@example.com';
        $u = Players::create($name, 'long-enough', $email);
        Players::verify($u->user_id, sha1($email));
        return User::where('user_id', $u->user_id)->first();
    }

    /** A matured company of the player's, with this many credits once matured */
    private function matured(User $owner, string $name, int $credits = 50000): int
    {
        $id = Startups::start($owner, $name, 'function launched() wait(60) end')['company'];
        DB::table('companies')->where('company_id', $id)->update(['credits' => $credits + Startups::grant()]);
        $drone = (int) DB::table('agents')->where('company_id', $id)->value('agent_id');
        DB::table('holdings')->updateOrInsert(['drone' => $drone, 'resource' => Release::FUEL, 'installed' => 0, 'locked' => 0],
            ['units' => 100, 'event_id' => 0]);
        $this->assertTrue(Startups::mature($owner, $id)['ok']);
        return $id;
    }

    /** A public company with shares the bank holds, to buy */
    private function forSale(): int
    {
        $founder = $this->player('Share Founder');
        $id = $this->matured($founder, 'For Sale Co');
        $this->assertTrue(PlayerShares::sellToBank($founder, $id, 20)['ok']);
        return $id;
    }

    // #1040

    public function testOnlyAPlayerWhoHasMaturedACompanyBuysShares()
    {
        $company = $this->forSale();
        $newcomer = $this->player('New Investor');
        $this->assertFalse(Ownership::mayInvest((int) $newcomer->user_id));
        // Every way to buy says what to do first
        foreach ([PlayerShares::buyFromBank($newcomer, $company, 1), PlayerShares::order($newcomer, $company, 'buy', 1, 50)] as $r) {
            $this->assertFalse($r['ok']);
            $this->assertSame(409, $r['status']);
            $this->assertStringContainsString('once you have matured a company of your own', $r['error']);
        }
        DB::table('player_share_offerings')->insert(['company_id' => $company, 'units' => 10, 'units_left' => 10, 'raised' => 0,
            'created_t' => World::gameNow()]);
        $this->assertStringContainsString('matured a company', PlayerShares::buyOffered($newcomer, $company, 1)['error']);
        $this->assertSame(0, PlayerShares::held((int) $newcomer->user_id, $company));
        // Selling is open to all: only buying waits
        // Once they mature a company of their own, they may
        $this->matured($newcomer, 'Newcomer Co');
        $this->assertTrue(Ownership::mayInvest((int) $newcomer->user_id));
        $this->assertTrue(PlayerShares::buyFromBank($newcomer, $company, 1)['ok']);
        $this->assertSame(1, PlayerShares::held((int) $newcomer->user_id, $company));
    }

    public function testPlayersWhoBoughtSharesBeforeTheRuleKeepInvesting()
    {
        $company = $this->forSale();
        $earlier = $this->player('Early Investor');
        DB::table('player_share_trades')->insert(['company_id' => $company, 'seller_id' => null, 'buyer_id' => $earlier->user_id,
            'units' => 1, 'price' => 10, 'game_t' => World::gameNow()]);
        $this->assertTrue(Ownership::mayInvest((int) $earlier->user_id));
        $this->assertNull(Ownership::investProblem((int) $earlier->user_id));
    }

    public function testTheApiRefusesANewPlayersBuyToo()
    {
        $company = $this->forSale();
        $newcomer = $this->player('Api Newcomer');
        $h = ['Authorization' => 'Bearer ' . Players::issueToken($newcomer)];
        $this->postJson("/api/v1/player-shares/$company/bank/buy", ['units' => 1], $h)->assertStatus(409)
            ->assertJsonFragment(['error' => Ownership::investProblem((int) $newcomer->user_id)]);
    }

    public function testClaimMySharesLeadsToTheFirstShare()
    {
        $me = $this->player('Claimer');
        $id = Startups::start($me, 'Claim Co', 'function launched() wait(60) end')['company'];
        DB::table('companies')->where('company_id', $id)->update(['credits' => 9000]);
        $this->actingAs($me)->get("/companies/$id")->assertOk()->assertSee('Claim my 100 shares');
        $this->actingAs($me)->post("/startups/$id/mature")->assertRedirect('/market/shares/first');
        $this->actingAs($me)->get('/market/shares/first')->assertOk()->assertSee('id="claimed"', false)
            ->assertSee('Claim Co</b> has matured', false)->assertSee('You can invest now too');
    }

    // #1041

    public function testSigningUpGoesOnToFoundWithTheDroneTriedAsAGuest()
    {
        $draft = "-- my drone\nfunction launched()\n  wait(30)\nend\n";
        $this->post('/try/keep', ['starter' => 'refiner', 'script' => $draft])->assertRedirect('/signup');
        $this->get('/signup')->assertOk()->assertSee('id="keep-drone"', false)->assertSee('Keep my drone')
            ->assertDontSee('password_confirmation');
        $this->post('/signup', ['name' => 'Keeper One', 'email' => 'keeper@example.com', 'password' => 'long-enough'])
            ->assertRedirect('/release')->assertSessionHas('success');
        $this->get('/release')->assertOk()->assertSee('id="your-drone"', false)->assertSee('-- my drone');
        // Founding it clears the draft, and opens its page at the first-hour checklist
        $r = $this->post('/release', ['company_name' => 'Kept Drone Co', 'script' => $draft]);
        $id = (int) DB::table('companies')->where('name', 'Kept Drone Co')->value('company_id');
        $r->assertRedirect('/companies/' . $id . '#first-hour');
        $this->assertNull(GuestDrive::draft());
        $this->get('/release')->assertOk()->assertDontSee('id="your-drone"', false);
    }

    public function testThePlayersMenuButtonIsTheirNextStep()
    {
        $me = $this->player('Next Stepper');
        $this->actingAs($me)->get('/market')->assertOk()->assertSee('id="nav-cta"', false)->assertSee('Next: Found a startup');
        Startups::start($me, 'Stepper Co', 'function launched() wait(60) end');
        $this->actingAs($me)->get('/market')->assertOk()->assertSee('Next: Your first fix');
    }

    // #1042

    public function testAGuestTestDrivesAStarterAndKeepsIt()
    {
        $this->get('/try')->assertOk()->assertSee('id="try-drone"', false)->assertSee('id="ladder"', false)
            ->assertSee('Step 2 of 8');
        $this->get('/try?starter=refiner')->assertOk()->assertSee(e(strtok(Release::starter('refiner'), "\n")), false);
        $script = "function launched()\n  print('hello')\n  wait(60)\nend";
        $r = $this->post('/try', ['starter' => 'miner', 'script' => $script])->assertRedirect('/try#result');
        $this->assertSame(['starter' => 'miner', 'script' => $script], GuestDrive::draft());
        $this->assertTrue((bool) session(Pipeline::TRIED));
        $drive = session('guest_drive');
        $this->assertFalse($drive['applied']);
        if (\App\Services\DroneTestDrive::available()) {
            $this->assertContains('hello', $drive['said']);
            $this->get('/try')->assertOk()->assertSee('Your test drive')->assertSee('it would say: hello');
        }
        $this->assertSame(1, GuestDrive::total());
        // A script that can't run is sent back with what is wrong
        $this->post('/try', ['starter' => 'miner', 'script' => 'print(1)'])->assertRedirect('/try#editor')->assertSessionHasErrors('script');
    }

    public function testAGuestDriveAnswersTheDronesOwnQuestionsAsANewStartups()
    {
        if (!\App\Services\DroneTestDrive::available()) {
            $this->markTestSkipped('lua-drive is not built here');
        }
        $d = GuestDrive::run("function launched()\n  print(credits(), units(fuel), house_only() and 'startup' or 'free', #my_clones())\nend\n");
        $this->assertNull($d['error']);
        $this->assertSame([config('forge.starting_credits') . ' ' . config('forge.starting_fuel') . ' startup 0'], array_map(
            fn ($s) => preg_replace('/\s+/', ' ', $s), $d['said']));
    }

    public function testGuestTestDrivesAreLimitedAnHour()
    {
        for ($i = 0; $i < GuestDrive::PER_HOUR; $i++) {
            $this->assertTrue(GuestDrive::allow('203.0.113.9'));
        }
        $this->assertFalse(GuestDrive::allow('203.0.113.9'));
        $this->assertTrue(GuestDrive::allow('203.0.113.10'), 'each visitor their own');
    }

    public function testTheHomePagePlaysAFixedReplayAVisitorCanChangeAndKeep()
    {
        // None recorded yet: the code, and a Test drive button
        $this->get('/')->assertOk()->assertSee('id="try-drone"', false)->assertSee('What would it do?')
            ->assertSee('-- Miner: a starter script')->assertSee('Keep my drone');
        $this->assertTrue((bool) session(Pipeline::WATCHED));
        // A recorded replay plays beside the starter's code
        @mkdir(dirname(Replay::path('miner')), 0775, true);
        file_put_contents(Replay::path('miner'), json_encode(['starter' => 'miner', 'recorded' => '2026-11-25', 'place' => 'Earth',
            'lines' => [['at' => '0:00', 'text' => 'would fly to the Moon'], ['at' => '3:10', 'text' => 'would mine 20 Kunspar at the Moon']]]));
        $this->get('/')->assertOk()->assertSee('What it did')->assertSee('id="replay-lines"', false)
            ->assertSee('would mine 20 Kunspar at the Moon');
        // Beside a visitor's own changes it shows none
        GuestDrive::keep('miner', "function launched() wait(5) end");
        $this->get('/')->assertOk()->assertDontSee('id="replay-lines"', false)->assertSee('function launched() wait(5) end');
        // A player sees their next step instead
        $this->actingAs($this->player('Home Player'))->get('/')->assertOk()->assertDontSee('id="try-drone"', false)
            ->assertSee('Next: Found a startup');
    }

    public function testAReplayKeepsOnlyADriveThatShowsTheDroneAtWork()
    {
        $drive = fn (array $texts, ?string $error = null) => ['error' => $error, 'stopped' => 'day', 'place' => 'Earth',
            'actions' => array_map(fn ($t, $i) => ['at' => $i * 3600, 'text' => $t, 'result' => str_contains($t, 'refused') ? -2 : 0],
                $texts, array_keys($texts))];
        $this->assertNull(Replay::fromDrive('miner', $drive(['would wait 1 game hour', 'would wait 1 game hour'])), 'only waiting');
        $this->assertNull(Replay::fromDrive('miner', $drive(['would fly to the Moon'], 'line 3: boom')), 'a crash');
        $r = Replay::fromDrive('miner', $drive(['would fly to the Moon', 'would try to mine: refused', 'would mine 20 Kunspar']));
        $this->assertSame(['0:00', '2:00'], array_column($r['lines'], 'at'), 'what it did, not what it tried');
        $this->assertSame('Earth', $r['place']);
    }

    // #1043

    public function testTheFirstHourChecklistAndACrashThatCounts()
    {
        $me = $this->player('First Hour');
        $id = Startups::start($me, 'Hour Co', Release::starter('miner'))['company'];
        $drone = (int) DB::table('agents')->where('company_id', $id)->value('agent_id');
        $page = fn () => $this->actingAs($me)->get('/companies/' . $id)->assertOk();
        $page()->assertSee('Your first hour:')->assertSee('id="mission-trip"', false)->assertSee('id="mission-sale"', false)
            ->assertDontSee('id="mission-crash"', false);
        DB::table('drone_crashes')->insert(['drone' => $drone, 'game_t' => World::gameNow(), 'code' => -10, 'in_row' => 1]);
        $page()->assertSee('id="mission-crash"', false)->assertSee('Its first crash')->assertSee('found another');
    }

    // #1044

    public function testTheFirstSharePageWaitsForAMaturedCompanyThenOffersOneShare()
    {
        $company = $this->forSale();
        $newcomer = $this->player('Share Waiter');
        $this->actingAs($newcomer)->get('/market/shares/first')->assertOk()->assertSee('id="invest-locked"', false)
            ->assertSee('id="first-share"', false)->assertDontSee('id="buy-first-share"', false)->assertSee('id="pick-rule"', false);
        $this->matured($newcomer, 'Waiter Co');
        $this->actingAs($newcomer)->get('/market/shares/first')->assertOk()->assertDontSee('id="invest-locked"', false)
            ->assertSee('For Sale Co')->assertSee('id="buy-first-share"', false);
        $this->assertSame($company, FirstSteps::share($newcomer)['company']);
        $this->actingAs($newcomer)->from('/market/shares/first')
            ->post("/companies/$company/share-now", ['side' => 'buy', 'units' => 1])->assertRedirect('/market/shares/first');
        $this->assertSame(1, PlayerShares::held((int) $newcomer->user_id, $company));
        $this->actingAs($newcomer)->get('/market/shares/first')->assertOk()->assertSee('id="first-share-done"', false)
            ->assertSee('id="first-share-next"', false)->assertSee(url('market/loans/first'));
    }

    public function testTheFirstSharePicksByPayoutForEachCreditOfPrice()
    {
        $a = $this->forSale();
        $other = $this->player('Second Founder');
        $b = $this->matured($other, 'Better Payer Co');
        $this->assertTrue(PlayerShares::sellToBank($other, $b, 20)['ok']);
        $epoch = (int) DB::table('game_clock')->where('id', 1)->value('epoch');
        $yesterday = intdiv(World::gameNow() - $epoch, 86400) - 1;
        DB::table('player_dividends')->insert(['company_id' => $b, 'user_id' => $other->user_id, 'day' => $yesterday, 'units' => 80, 'amount' => 5000]);
        $investor = $this->player('Picky Investor');
        $this->matured($investor, 'Picky Co');
        $this->assertSame($b, FirstSteps::share($investor)['company']);
    }

    public function testTheFirstLoanPage()
    {
        $me = $this->player('First Lender');
        $this->actingAs($me)->get('/market/loans/first')->assertOk()->assertSee('id="ladder"', false)->assertSee('id="pick-rule"', false);
        $this->get('/market/loans/first')->assertOk();
    }

    // #1045

    public function testAVisitorsMenusAndTheTryButton()
    {
        $html = $this->get('/market')->assertOk()->getContent();
        foreach (['nav-watch', 'nav-explore', 'nav-learn'] as $id) {
            $this->assertStringContainsString('id="' . $id . '"', $html);
        }
        $this->assertStringNotContainsString('id="nav-play"', $html);
        $this->assertStringContainsString('href="' . url('try') . '" id="nav-cta"', $html);
    }

    public function testTheStepCountsNameNoOne()
    {
        $founder = $this->player('Count Founder');
        $this->matured($founder, 'Counted Co');
        $before = Pipeline::counts();
        $this->assertGreaterThanOrEqual(1, $before['own']);
        $this->assertSame(['accounts', 'found', 'fix', 'own', 'invest', 'lend'], array_keys($before));
        $this->getJson('/api/v1/ladder')->assertStatus(401);
    }
}
