<?php

namespace Tests\Feature;

use App\Services\CreditCheck;
use App\Services\Players;
use App\Services\PlayerShares;
use App\Services\Startups;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Share pricing (#928): players set prices, with no floor or band; the bank
 * keeps only its backstop, buying and selling at close-out value, and sells
 * the shares it holds into players' bids above that; no starting credits.
 */
class SharePricingTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['forge.new_rules' => true, 'forge.startup_hull_value' => 2000, 'forge.startup_fee' => 500,
            'forge.starting_credits' => 3000, 'forge.bank_pay_days' => 30, 'forge.hull_life_days' => 365,
            'forge.startup_max' => 5, 'forge.startup_mature_margin' => 0]);
        Mail::fake();
    }

    private function player(string $name, int $credits = 0): User
    {
        $email = strtolower(str_replace(' ', '.', $name)) . '@example.com';
        $user = Players::create($name, 'long-enough', $email);
        $this->canInvest((int) $user->user_id);   // buyers here are investors (#1040)
        Players::verify($user->user_id, sha1($email));
        if ($credits) {
            DB::table('users')->where('user_id', $user->user_id)->update(['credits' => $credits]);
        }
        return User::where('user_id', $user->user_id)->first();
    }

    public function testNoStartingCredits()
    {
        $u = $this->player('Fresh Player');
        $this->assertSame([0, 0], [(int) $u->credits, (int) $u->share_credits], 'a player\'s first credits come from maturing');
    }

    public function testPlayersSetPricesAndTheBankSellsIntoBidsAboveCloseOut()
    {
        $owner = $this->player('Pricing Owner');
        $buyer = $this->player('Pricing Buyer', 1000000);
        $id = Startups::start($owner, 'Priced Co', 'function launched() wait(60) end')['company'];
        DB::table('companies')->where('company_id', $id)->update(['credits' => 10000 + Startups::grant()]);
        $this->assertTrue(Startups::mature($owner, $id)['ok']);
        $p = PlayerShares::prices($id);
        $this->assertSame($p['buys_at'], $p['sells_at'], 'the bank buys and sells at close-out value');

        // Any price stands: far under close-out, and far over
        $this->assertTrue(PlayerShares::order($owner, $id, 'sell', 1, $p['buys_at'] * 20)['ok']);
        $this->assertTrue(PlayerShares::order($buyer, $id, 'buy', 1, 1)['ok']);

        // The bank buys 10, then sells them into the best bid above close-out, at the bid
        $this->assertTrue(PlayerShares::sellToBank($owner, $id, 10)['ok']);
        CreditCheck::restart();
        CreditCheck::check();
        $bid = $p['buys_at'] + 50;
        $this->assertSame(0, PlayerShares::order($buyer, $id, 'buy', 4, $bid)['traded'], 'it stands: the bank sells on the tick');
        $before = (int) DB::table('users')->where('user_id', $buyer->user_id)->value('credits');
        $this->assertSame(4, PlayerShares::bankSells());
        $this->assertSame(4, PlayerShares::held((int) $buyer->user_id, $id));
        $this->assertSame($before - 4 * $bid, (int) DB::table('users')->where('user_id', $buyer->user_id)->value('credits'));
        $this->assertSame(6, PlayerShares::prices($id)['bank_holds']);
        $this->assertSame(0, PlayerShares::bankSells(), 'the bid at 1 is under close-out: the bank never sells below it');
        $this->assertSame(0, CreditCheck::check()['difference'], 'what the bank got left the game, and the ledger says so');
    }
}
