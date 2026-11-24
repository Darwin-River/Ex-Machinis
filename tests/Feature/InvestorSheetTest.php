<?php

namespace Tests\Feature;

use App\Services\InvestorSheet;
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
 * The investor sheet (#994): a matured company's price against its value,
 * what it pays out, what it owes (insider loans marked), its dealings with
 * its insiders' companies, and its founder's record, in one place.
 */
class InvestorSheetTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['forge.new_rules' => true, 'forge.startup_hull_value' => 2000, 'forge.startup_fee' => 500,
            'forge.starting_credits' => 3000, 'forge.startup_max' => 5, 'forge.startup_mature_margin' => 0]);
        Mail::fake();
    }

    private function player(string $name): User
    {
        $email = strtolower(str_replace(' ', '.', $name)) . '@example.com';
        $u = Players::create($name, 'long-enough', $email);
        Players::verify($u->user_id, sha1($email));
        return User::where('user_id', $u->user_id)->first();
    }

    private function matured(User $owner, string $name): int
    {
        $id = Startups::start($owner, $name, 'function launched() wait(60) end')['company'];
        DB::table('companies')->where('company_id', $id)->update(['credits' => 1000 + Startups::grant()]);
        $this->assertTrue(Startups::mature($owner, $id)['ok']);
        return $id;
    }

    public function testAStartupHasNoSheet()
    {
        $me = $this->player('Sheet Starter');
        $id = Startups::start($me, 'Young Co', 'function launched() wait(60) end')['company'];
        $this->assertNull(InvestorSheet::of($id));
        $this->get('/companies/' . $id)->assertOk()->assertDontSee('id="investor-sheet"', false);
    }

    public function testTheSheetGathersWhatAnInvestorWeighs()
    {
        $founder = $this->player('Sheet Founder');
        $holder = $this->player('Sheet Holder');
        $outsider = $this->player('Sheet Outsider');
        // The founder's record: an earlier company, closed
        $old = Startups::start($founder, 'Old Try', 'function launched() wait(60) end')['company'];
        WindUp::windUp($old, 'closed', true);
        $id = $this->matured($founder, 'Weighed Co');
        // A holder buys in; an insider's other company deals with it
        $this->assertTrue(PlayerShares::sellToBank($founder, $id, 10)['ok']);
        DB::table('player_shares')->where('company_id', $id)->where('user_id', $founder->user_id)->decrement('units', 30);
        DB::table('player_shares')->insert(['user_id' => $holder->user_id, 'company_id' => $id, 'units' => 30]);
        DB::table('player_share_trades')->insert(['company_id' => $id, 'seller_id' => $founder->user_id, 'buyer_id' => $holder->user_id,
            'units' => 30, 'price' => 999, 'game_t' => World::gameNow()]);
        $side = $this->matured($holder, 'Holder Side Co');
        $mine = (int) DB::table('agents')->where('company_id', $id)->value('agent_id');
        $theirs = (int) DB::table('agents')->where('company_id', $side)->value('agent_id');
        DB::table('trades')->insert(['resource' => 2011, 'units' => 5, 'credits' => 400, 'tax' => 0, 'seller' => $mine,
            'buyer' => $theirs, 'location' => 3, 'game_t' => World::gameNow()]);
        // What it owes: an insider's loan, an outsider's, a request, a contract
        $req = (int) DB::table('loan_requests')->insertGetId(['company_id' => $id, 'drone' => $mine, 'amount' => 3000,
            'rate_bp' => 500, 'requested_t' => World::gameNow(), 'due_t' => World::gameNow() + 20 * 86400, 'backed' => 1000]);
        foreach ([[$holder, 600], [$outsider, 400]] as [$lender, $amount]) {
            DB::table('loans')->insert(['request_id' => $req, 'company_id' => $id, 'user_id' => $lender->user_id, 'principal' => $amount,
                'rate_bp' => 500, 'lent_t' => World::gameNow(), 'due_t' => World::gameNow() + 20 * 86400, 'owed' => $amount + $amount / 20]);
        }
        DB::table('contracts')->insert(['buyer_company' => $id, 'buyer_drone' => $mine, 'place' => 3, 'resource' => 2011, 'units' => 8,
            'price' => 40, 'deposit' => 50, 'escrow' => 320, 'posted_t' => World::gameNow(), 'due_t' => World::gameNow() + 5 * 86400]);
        // Its code declares a fifth, and asked for a payout
        DB::table('companies')->where('company_id', $id)->update(['dividend_permille' => 200]);
        DB::table('company_payouts')->insert(['company_id' => $id, 'drone' => $mine, 'amount' => 700, 'requested_t' => World::gameNow()]);

        $s = InvestorSheet::of($id);
        $this->assertSame(999, $s['price']['last']);
        $this->assertSame(20.0, (float) $s['payout']['declared_percent']);
        $this->assertSame([700, 0, 1], [$s['payout']['payouts_asked_30'], $s['payout']['payouts_paid_30'], $s['payout']['payouts_pending']]);
        $this->assertSame(['Sheet Holder' => true, 'Sheet Outsider' => false], collect($s['loans'])->pluck('insider', 'lender_name')->sortKeys()->all());
        $this->assertSame([2000], array_column($s['requests'], 'left'));
        $this->assertCount(1, $s['contracts']);
        $this->assertSame(['Holder Side Co'], array_column($s['related'], 'name'));
        $this->assertSame(['Sheet Founder', 'Sheet Holder'], array_column($s['insiders'], 'name'));
        $this->assertTrue($s['insiders'][0]['founder']);
        $this->assertSame(['Old Try'], array_column($s['founder']['companies'], 'name'));
        $this->assertSame([0, 1], [$s['founder']['matured'], $s['founder']['ended']]);

        $this->get('/companies/' . $id)->assertOk()->assertSee('id="investor-sheet"', false)
            ->assertSee('Last traded at <b>999</b>', false)->assertSee('<b>20%</b> of each day', false)
            ->assertSee('insider</span>', false)->assertSee('Asks to borrow 2,000')->assertSee('Contract to buy 8')
            ->assertSee('Dealt with insiders')->assertSee('Holder Side Co')
            ->assertSee('has founded 1 other company')->assertSee('its player closed it');
    }
}
