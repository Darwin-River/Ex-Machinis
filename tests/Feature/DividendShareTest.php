<?php

namespace Tests\Feature;

use App\Services\CreditCheck;
use App\Services\Players;
use App\Services\PlayerShares;
use App\Services\Startups;
use App\Services\World;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** #930: a company's code sets the share of its profit it pays, and can pay out spare credits */
class DividendShareTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['forge.new_rules' => true, 'forge.startup_max' => 5, 'forge.startup_mature_margin' => 0,
            'forge.dividend_permille' => 500]);
        Mail::fake();
    }

    private function player(string $name): User
    {
        $email = strtolower(str_replace(' ', '.', $name)) . '@example.com';
        $user = Players::create($name, 'long-enough', $email);
        Players::verify($user->user_id, sha1($email));
        return User::where('user_id', $user->user_id)->first();
    }

    /** A matured company: its owner holds 80 shares, a holder 10 and the bank 10 */
    private function company(User $owner, User $holder): int
    {
        $id = Startups::start($owner, 'Payer ' . $owner->user_id, 'function launched() wait(60) end')['company'];
        DB::table('companies')->where('company_id', $id)->update(['credits' => Startups::grant()]);
        $this->assertTrue(Startups::mature($owner, $id)['ok']);
        DB::table('player_shares')->where('user_id', $owner->user_id)->where('company_id', $id)->update(['units' => 80]);
        DB::table('player_shares')->insert(['user_id' => $holder->user_id, 'company_id' => $id, 'units' => 10]);
        DB::table('bank_shares')->insert(['company_id' => $id, 'units' => 10]);
        DB::table('companies')->where('company_id', $id)->update(['credits' => 100000]);
        return $id;
    }

    private function credits(User $u): int
    {
        return (int) DB::table('users')->where('user_id', $u->user_id)->value('credits');
    }

    private function profitDay(int $company, int $profit): void
    {
        $epoch = (int) DB::table('game_clock')->where('id', 1)->value('epoch');
        $day = intdiv(World::gameNow() - $epoch, 86400);
        DB::table('companies')->where('company_id', $company)->update(['player_dividend_day' => $day - 1]);
        DB::table('company_profits')->insert(['company' => $company, 'day' => $day, 'sales' => $profit, 'purchases' => 0,
            'profit' => $profit]);
    }

    public function testACompanyPaysTheShareItsCodeSet()
    {
        $owner = $this->player('Policy Owner');
        $holder = $this->player('Policy Holder');
        $id = $this->company($owner, $holder);
        $this->assertSame(500, PlayerShares::dividendPermille($id), 'half until its code sets one');

        // Its code sets a fifth (set_dividend_share(0.2): the engine writes 200)
        DB::table('companies')->where('company_id', $id)->update(['dividend_permille' => 200]);
        $this->assertSame(200, PlayerShares::dividendPermille($id));
        $this->profitDay($id, 10000);
        [$o, $h] = [$this->credits($owner), $this->credits($holder)];
        $this->assertSame(1, PlayerShares::payDividends());
        // 2,000 shared out: 80 shares get 1,600, 10 get 200, and the bank's 200 leave the game
        $this->assertSame(1600, $this->credits($owner) - $o);
        $this->assertSame(200, $this->credits($holder) - $h);
        $this->assertSame(98000, (int) DB::table('companies')->where('company_id', $id)->value('credits'));

        // A company that pays nothing out keeps it all
        DB::table('companies')->where('company_id', $id)->update(['dividend_permille' => 0]);
        DB::table('company_profits')->where('company', $id)->delete();
        $this->profitDay($id, 10000);
        PlayerShares::payDividends();
        $this->assertSame(98000, (int) DB::table('companies')->where('company_id', $id)->value('credits'));

        // The company page and the stock exchange say so
        DB::table('companies')->where('company_id', $id)->update(['dividend_permille' => 200]);
        Cache::flush();
        $this->get('/companies/' . $id)->assertOk()->assertSee('id="stat-dividend-share"', false)->assertSee('20%');
        $row = collect(PlayerShares::market())->firstWhere('company', $id);
        $this->assertEquals(20, $row['dividend_percent']);
    }

    public function testAPayoutGoesToTheShareholdersAndCreditsBalance()
    {
        $owner = $this->player('Payout Owner');
        $holder = $this->player('Payout Holder');
        $id = $this->company($owner, $holder);
        CreditCheck::restart();
        CreditCheck::check();

        // Its code calls pay_out(5000): the engine records the request
        $drone = (int) DB::table('agents')->where('company_id', $id)->value('agent_id');
        DB::table('company_payouts')->insert(['company_id' => $id, 'drone' => $drone, 'amount' => 5000,
            'requested_t' => World::gameNow()]);
        [$o, $h] = [$this->credits($owner), $this->credits($holder)];
        $this->assertSame(1, PlayerShares::settlePayouts());
        $this->assertSame(4000, $this->credits($owner) - $o);
        $this->assertSame(500, $this->credits($holder) - $h);
        $this->assertSame(95000, (int) DB::table('companies')->where('company_id', $id)->value('credits'));
        $p = DB::table('company_payouts')->where('company_id', $id)->first();
        $this->assertNotNull($p->paid_t);
        $this->assertSame(5000, (int) $p->paid);
        $this->assertSame(3, DB::table('player_dividends')->where('company_id', $id)->where('payout', 1)->count());
        $this->assertSame(0, PlayerShares::settlePayouts(), 'paid once');
        // From the next game day it counts with its dividends over 30 days (whole
        // days, as dividends): 5,000 over 100 shares is 50 a share
        DB::table('player_dividends')->where('company_id', $id)->where('payout', 1)->update(['day' => DB::raw('day - 1')]);
        $this->assertSame(50, PlayerShares::dividends30($id));

        // The bank's 500 left the game, and the ledger says so
        $c = CreditCheck::check();
        $this->assertSame(0, $c['difference'], json_encode($c));
        $this->assertSame(-500, $c['kinds']['payout_to_bank']);
    }

    public function testAPayoutIsNoMoreThanTheCompanyHolds()
    {
        $owner = $this->player('Short Owner');
        $holder = $this->player('Short Holder');
        $id = $this->company($owner, $holder);
        $drone = (int) DB::table('agents')->where('company_id', $id)->value('agent_id');
        // It spent most of it before the tick came round
        DB::table('company_payouts')->insert(['company_id' => $id, 'drone' => $drone, 'amount' => 50000,
            'requested_t' => World::gameNow()]);
        DB::table('companies')->where('company_id', $id)->update(['credits' => 1000]);
        PlayerShares::settlePayouts();
        $this->assertSame(1000, (int) DB::table('company_payouts')->where('company_id', $id)->value('paid'));
        $this->assertSame(0, (int) DB::table('companies')->where('company_id', $id)->value('credits'));

        // One that has ended pays nothing
        DB::table('company_payouts')->insert(['company_id' => $id, 'drone' => $drone, 'amount' => 10,
            'requested_t' => World::gameNow()]);
        DB::table('companies')->where('company_id', $id)->update(['credits' => 500, 'eliminated_at' => World::gameNow()]);
        PlayerShares::settlePayouts();
        $this->assertSame(0, (int) DB::table('company_payouts')->where('company_id', $id)->orderByDesc('id')->value('paid'));
    }
}
