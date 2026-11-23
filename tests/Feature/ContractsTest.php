<?php

namespace Tests\Feature;

use App\Services\Contracts;
use App\Services\CreditCheck;
use App\Services\Players;
use App\Services\Startups;
use App\Services\WindUp;
use App\Services\World;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Contracts (#928): the buyer's credits held in escrow, the seller's deposit
 * at stake; settled on the due date or once all is delivered; what wasn't
 * delivered refunded with that share of the deposit.
 */
class ContractsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['forge.new_rules' => true, 'forge.startup_hull_value' => 2000, 'forge.startup_fee' => 500,
            'forge.starting_credits' => 3000, 'forge.startup_max' => 5, 'forge.startup_mature_margin' => 0]);
        Mail::fake();
    }

    private function company(string $player, string $name, int $credits = 10000): int
    {
        $email = strtolower(str_replace(' ', '.', $player)) . '@example.com';
        $u = Players::create($player, 'long-enough', $email);
        Players::verify($u->user_id, sha1($email));
        $user = User::where('user_id', $u->user_id)->first();
        $id = Startups::start($user, $name, 'function launched() wait(60) end')['company'];
        DB::table('companies')->where('company_id', $id)->update(['credits' => $credits + Startups::grant()]);
        $this->assertTrue(Startups::mature($user, $id)['ok']);
        return $id;
    }

    private function credits(int $company): int
    {
        return (int) DB::table('companies')->where('company_id', $company)->value('credits');
    }

    /** post_contract and take_contract as the game engine does them */
    private function contract(int $buyer, ?int $seller, int $units, int $price, int $deposit): int
    {
        $drone = (int) DB::table('agents')->where('company_id', $buyer)->value('agent_id');
        DB::table('companies')->where('company_id', $buyer)->update(['credits' => DB::raw('credits - ' . ($units * $price))]);
        $id = (int) DB::table('contracts')->insertGetId(['buyer_company' => $buyer, 'buyer_drone' => $drone, 'place' => 3,
            'resource' => 2011, 'units' => $units, 'price' => $price, 'deposit' => $deposit, 'escrow' => $units * $price,
            'posted_t' => World::gameNow(), 'due_t' => World::gameNow() + 10 * 86400]);
        if ($seller !== null) {
            DB::table('companies')->where('company_id', $seller)->update(['credits' => DB::raw('credits - ' . $deposit)]);
            DB::table('contracts')->where('id', $id)->update(['seller_company' => $seller, 'taken_t' => World::gameNow(),
                'deposit_held' => $deposit]);
        }
        return $id;
    }

    public function testAKeptContractPaysTheSellerAndReturnsItsDeposit()
    {
        $buyer = $this->company('Contract Buyer', 'Wants Co');
        $seller = $this->company('Contract Seller', 'Delivers Co');
        [$b, $s] = [$this->credits($buyer), $this->credits($seller)];
        CreditCheck::restart();
        CreditCheck::check();
        $id = $this->contract($buyer, $seller, 10, 50, 100);
        $this->assertSame(0, CreditCheck::check()['difference'], 'escrow and deposit are credits held');
        DB::table('contracts')->where('id', $id)->update(['delivered' => 10]);   // deliver(id, n) counted them
        $this->assertSame(1, Contracts::settle());
        $this->assertSame([$b - 500, $s + 500], [$this->credits($buyer), $this->credits($seller)]);
        $this->assertSame('kept', DB::table('contracts')->where('id', $id)->value('outcome'));
        $this->assertSame([1, 0], Contracts::record($seller));
        $this->assertSame(0, CreditCheck::check()['difference']);
        $this->get('/companies/' . $seller)->assertOk()->assertSee('id="company-contracts"', false)->assertSee('kept 1');
    }

    public function testABrokenContractRefundsTheBuyerWithItsShareOfTheDeposit()
    {
        $buyer = $this->company('Broken Buyer', 'Waits Co');
        $seller = $this->company('Broken Seller', 'Late Co');
        [$b, $s] = [$this->credits($buyer), $this->credits($seller)];
        $id = $this->contract($buyer, $seller, 10, 50, 100);
        // Half delivered by the due date
        DB::table('contracts')->where('id', $id)->update(['delivered' => 5, 'due_t' => World::gameNow() - 1]);
        Contracts::settle();
        $this->assertSame($b - 250 + 50, $this->credits($buyer), 'pays for 5, gets half the deposit');
        $this->assertSame($s + 250 - 50, $this->credits($seller), 'paid for 5, loses half its deposit');
        $this->assertSame('broken', DB::table('contracts')->where('id', $id)->value('outcome'));
        $this->assertSame([0, 1], Contracts::record($seller));
    }

    public function testCancelsExpiryAndEndings()
    {
        $buyer = $this->company('Cancel Buyer', 'Changes Co');
        $seller = $this->company('Cancel Seller', 'Quits Co');
        $b = $this->credits($buyer);
        // Withdrawn before anyone took it: the escrow comes back
        $open = $this->contract($buyer, null, 4, 25, 10);
        DB::table('contract_actions')->insert(['contract_id' => $open, 'company_id' => $buyer, 'requested_t' => World::gameNow()]);
        Contracts::settle();
        $this->assertSame($b, $this->credits($buyer));
        $this->assertSame('cancelled', DB::table('contracts')->where('id', $open)->value('outcome'));
        // Never taken by its due date: expired, refunded
        $stale = $this->contract($buyer, null, 2, 30, 0);
        DB::table('contracts')->where('id', $stale)->update(['due_t' => World::gameNow() - 1]);
        Contracts::settle();
        $this->assertSame([$b, 'expired'], [$this->credits($buyer), DB::table('contracts')->where('id', $stale)->value('outcome')]);
        // The seller gives up: as if due now, nothing delivered
        $s = $this->credits($seller);
        $given = $this->contract($buyer, $seller, 2, 30, 40);
        DB::table('contract_actions')->insert(['contract_id' => $given, 'company_id' => $seller, 'requested_t' => World::gameNow()]);
        Contracts::settle();
        $this->assertSame([$b + 40, $s - 40], [$this->credits($buyer), $this->credits($seller)]);
        // A company that ends settles its contracts first
        $last = $this->contract($buyer, $seller, 3, 10, 30);
        WindUp::windUp($seller, 'closed', true);
        $this->assertSame('broken', DB::table('contracts')->where('id', $last)->value('outcome'));
        $this->assertSame($b + 40 + 30, $this->credits($buyer), 'its escrow back, and the deposit');
    }
}
