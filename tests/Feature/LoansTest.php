<?php

namespace Tests\Feature;

use App\Services\CreditCheck;
use App\Services\Loans;
use App\Services\Players;
use App\Services\PlayerShares;
use App\Services\Startups;
use App\Services\WindUp;
use App\Services\World;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Loans (#928): a public company's code asks to borrow; players with enough
 * credits back it; it repays on the due date, or early; a default sends
 * every credit to its lenders and stops its dividends; a takeover makes its
 * loans due; lenders are paid first when it ends.
 */
class LoansTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['forge.new_rules' => true, 'forge.startup_hull_value' => 2000, 'forge.startup_fee' => 500,
            'forge.starting_credits' => 3000, 'forge.player_share_credits' => 5000, 'forge.bank_pay_days' => 30,
            'forge.share_range_low' => 0.8, 'forge.share_range_high' => 1.25, 'forge.hull_life_days' => 365,
            'forge.dividend_permille' => 500, 'forge.startup_max' => 5, 'forge.startup_mature_margin' => 0,
            'forge.lend_min_credits' => 5000]);
        Mail::fake();
    }

    private function player(string $name, int $credits = 0): User
    {
        $email = strtolower(str_replace(' ', '.', $name)) . '@example.com';
        $user = Players::create($name, 'long-enough', $email);
        Players::verify($user->user_id, sha1($email));
        DB::table('users')->where('user_id', $user->user_id)->update(['credits' => $credits, 'share_credits' => 0]);
        return User::where('user_id', $user->user_id)->first();
    }

    private function credits(User $u): int
    {
        return (int) DB::table('users')->where('user_id', $u->user_id)->value('credits');
    }

    private function companyCredits(int $id): int
    {
        return (int) DB::table('companies')->where('company_id', $id)->value('credits');
    }

    /** A public company: matured, with 10 of its shares sold to the bank */
    private function publicCompany(User $owner, string $name): int
    {
        $id = Startups::start($owner, $name, 'function launched() wait(60) end')['company'];
        DB::table('companies')->where('company_id', $id)->update(['credits' => 1000 + Startups::grant()]);
        $this->assertTrue(Startups::mature($owner, $id)['ok']);
        $this->assertTrue(PlayerShares::sellToBank($owner, $id, 10)['ok']);
        return $id;
    }

    /** What request_loan(amount, rate, due_in) records, as the game engine does */
    private function request(int $company, int $amount, int $rateBp, int $days): int
    {
        $drone = (int) DB::table('agents')->where('company_id', $company)->value('agent_id');
        return (int) DB::table('loan_requests')->insertGetId(['company_id' => $company, 'drone' => $drone, 'amount' => $amount,
            'rate_bp' => $rateBp, 'requested_t' => World::gameNow(), 'due_t' => World::gameNow() + $days * 86400]);
    }

    public function testPlayersBackALoanAndItIsRepaidOnTheDueDate()
    {
        $owner = $this->player('Borrow Owner');
        $ruth = $this->player('Ruth Lender', 20000);
        $marcus = $this->player('Marcus Lender', 20000);
        $poor = $this->player('Poor Lender', 4999);
        $id = $this->publicCompany($owner, 'Rocket Co');
        $req = $this->request($id, 3000, 600, 30);
        DB::table('companies')->where('company_id', $id)->update(['credits' => DB::raw('credits + 200')]);   // what it earns meanwhile
        CreditCheck::restart();
        CreditCheck::check();

        $this->assertSame(403, Loans::back($poor, $req, 100)['status'], 'under 5,000 credits: no new loans');
        $this->assertSame(422, Loans::back($ruth, $req, 3001)['status'], 'no more than it asks');
        $before = $this->companyCredits($id);
        $r = Loans::back($ruth, $req, 1000);
        $this->assertTrue($r['ok']);
        $this->assertSame(1060, $r['owed'], '6% for the whole loan');
        $this->assertTrue(Loans::back($marcus, $req, 2000)['ok']);
        $this->assertSame($before + 3000, $this->companyCredits($id), 'lent at once');
        $this->assertSame([19000, 18000], [$this->credits($ruth), $this->credits($marcus)]);
        $this->assertSame([], Loans::requests($id), 'fully backed');
        $this->assertSame(409, Loans::back($ruth, $req, 1)['status']);

        // The due date: each lender gets what they lent plus the rate
        DB::table('loans')->where('company_id', $id)->update(['due_t' => World::gameNow() - 1]);
        Loans::settle();
        $this->assertSame([20060, 20120], [$this->credits($ruth), $this->credits($marcus)]);
        $this->assertFalse(Loans::owes($id));
        $this->assertSame(0, CreditCheck::check()['difference'], 'loans move credits, they don\'t make them');
        $this->assertSame(1, Loans::ofUser((int) $ruth->user_id)['loans'][0]['closed'] ? 1 : 0);
        $this->assertSame(60, Loans::ofUser((int) $ruth->user_id)['interest']);
    }

    public function testADefaultTakesEveryCreditAndStopsDividends()
    {
        $owner = $this->player('Default Owner');
        $ruth = $this->player('Ruth Again', 20000);
        $marcus = $this->player('Marcus Again', 20000);
        $id = $this->publicCompany($owner, 'Shaky Co');
        $req = $this->request($id, 3000, 600, 30);
        Loans::back($ruth, $req, 1000);
        Loans::back($marcus, $req, 2000);
        // It spent it: 2,000 left when 3,180 is due
        DB::table('companies')->where('company_id', $id)->update(['credits' => 2000]);
        DB::table('loans')->where('company_id', $id)->update(['due_t' => World::gameNow() - 1]);
        Loans::settle();
        $this->assertSame([19000 + 667, 18000 + 1333], [$this->credits($ruth), $this->credits($marcus)], 'a third and two thirds');
        $this->assertSame(0, $this->companyCredits($id));
        $this->assertTrue(Loans::inDefault($id));
        $this->get('/companies/' . $id)->assertOk()->assertSee('id="loan-default"', false);

        // Its dividends stop meanwhile; every credit it earns goes to its lenders
        $epoch = (int) DB::table('game_clock')->where('id', 1)->value('epoch');
        $day = intdiv(World::gameNow() - $epoch, 86400);
        DB::table('companies')->where('company_id', $id)->update(['player_dividend_day' => $day - 1, 'credits' => 5000]);
        DB::table('company_profits')->insert(['company' => $id, 'day' => $day, 'sales' => 5000, 'purchases' => 0, 'profit' => 5000]);
        $o = $this->credits($owner);
        PlayerShares::payDividends();
        $this->assertSame($o, $this->credits($owner), 'no dividends in default');
        Loans::settle();
        $this->assertSame([20060, 20120], [$this->credits($ruth), $this->credits($marcus)], 'paid in full at last');
        $this->assertSame(5000 - 1180, $this->companyCredits($id));
        $this->assertFalse(Loans::inDefault($id));
    }

    public function testEarlyRepaymentATakeoverAndAnEnding()
    {
        $owner = $this->player('Early Owner');
        $ruth = $this->player('Ruth Early', 20000);
        $id = $this->publicCompany($owner, 'Early Co');
        $req = $this->request($id, 1000, 1000, 30);
        Loans::back($ruth, $req, 1000);
        // Half its term on, its code repays: the principal and half the interest
        DB::table('loans')->where('company_id', $id)->update(['lent_t' => World::gameNow() - 15 * 86400,
            'due_t' => World::gameNow() + 15 * 86400]);
        $drone = (int) DB::table('agents')->where('company_id', $id)->value('agent_id');
        DB::table('loan_actions')->insert(['company_id' => $id, 'drone' => $drone, 'kind' => 'repay', 'request_id' => $req,
            'requested_t' => World::gameNow()]);
        Loans::settle();
        $this->assertSame(19000 + 1050, $this->credits($ruth));
        $this->assertFalse(Loans::owes($id));

        // A takeover makes its loans due at once
        $req2 = $this->request($id, 1000, 500, 30);
        Loans::back($ruth, $req2, 500);
        DB::table('users')->where('user_id', $owner->user_id)->update(['credits' => 1000000]);
        DB::table('share_sale_bans')->where('user_id', $owner->user_id)->delete();
        $this->assertTrue(PlayerShares::buyFromBank($owner, $id, 10)['ok']);
        $this->assertSame(World::gameNow(), (int) DB::table('loans')->where('request_id', $req2)->value('due_t'), 'due now', 2);
        $this->assertNotNull(DB::table('loan_requests')->where('id', $req2)->value('cancelled_t'), 'and its request withdrawn');

        // It ends: its lender is paid first
        $r = $this->credits($ruth);
        DB::table('companies')->where('company_id', $id)->update(['credits' => 600]);
        WindUp::windUp($id, 'closed', true);
        $this->assertSame($r + 525, $this->credits($ruth));
        $this->assertFalse(Loans::owes($id));
    }

    public function testTheBoardAndTheApi()
    {
        $owner = $this->player('Board Owner');
        $lender = $this->player('Board Lender', 20000);
        $id = $this->publicCompany($owner, 'Board Co');
        $req = $this->request($id, 3000, 600, 30);
        $this->actingAs($lender)->get('/market/loans')->assertOk()->assertSee('Board Co')->assertSee('73%')
            ->assertSee('id="my-loans"', false);
        $this->actingAs($lender)->post('/loans/' . $req . '/back', ['credits' => 500])->assertSessionHas('success');
        $this->get('/companies/' . $id)->assertOk()->assertSee('id="company-loans"', false)->assertSee('Board Lender');
        $h = ['Authorization' => 'Bearer ' . Players::issueToken($lender)];
        $this->getJson('/api/v1/loans')->assertOk()->assertJsonPath('requests.0.left', 2500)->assertJsonPath('min_credits', 5000);
        $this->postJson('/api/v1/loans/' . $req . '/back', ['credits' => 500], $h)->assertOk()->assertJsonPath('lent', 500);
        $this->getJson('/api/v1/account/loans', $h)->assertOk()->assertJsonPath('lent', 1000);
        $this->postJson('/api/v1/loans/' . $req . '/back', ['credits' => 500])->assertStatus(401);
    }
}
