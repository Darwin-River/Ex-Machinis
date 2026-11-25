<?php

namespace Tests\Feature;

use App\Services\Players;
use App\Services\PlayerShares;
use App\Services\ShareTicket;
use App\Services\Startups;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The share order ticket (#845): Buy now and Sell now take the best route,
 * in price order, across players' orders, an open offering and the bank, in
 * one transaction; and a standing order can be moved to a new price.
 */
class ShareTicketTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['forge.new_rules' => true, 'forge.startup_hull_value' => 2000, 'forge.startup_fee' => 500,
            'forge.starting_credits' => 3000, 'forge.player_share_credits' => 0, 'forge.bank_pay_days' => 30,
            'forge.share_range_low' => 0.8, 'forge.share_range_high' => 1.25, 'forge.hull_life_days' => 365,
            'forge.dividend_permille' => 500, 'forge.startup_max' => 5, 'forge.startup_mature_margin' => 0]);
        Mail::fake();
    }

    private function player(string $name, int $credits = 0): User
    {
        $email = strtolower(str_replace(' ', '.', $name)) . '@example.com';
        $user = Players::create($name, 'long-enough', $email);
        $this->canInvest((int) $user->user_id);   // buyers here are investors (#1040)
        Players::verify($user->user_id, sha1($email));
        DB::table('users')->where('user_id', $user->user_id)->update(['credits' => $credits, 'share_credits' => 0]);
        return User::where('user_id', $user->user_id)->first();
    }

    private function matured(User $owner, string $name): int
    {
        $id = Startups::start($owner, $name, "function launched() wait(60) end")['company'];
        DB::table('companies')->where('company_id', $id)->update(['credits' => 100000 + Startups::grant()]);
        $drone = (int) DB::table('agents')->where('company_id', $id)->value('agent_id');
        DB::table('holdings')->updateOrInsert(['drone' => $drone, 'resource' => \App\Services\Release::FUEL, 'installed' => 0, 'locked' => 0],
            ['units' => 100, 'event_id' => 0]);
        $this->assertTrue(Startups::mature($owner, $id)['ok']);
        return $id;
    }

    public function testBuyNowTakesTheCheapestRoutesInOrder()
    {
        $owner = $this->player('Ticket Owner', 100000);
        $id = $this->matured($owner, 'Ticket Co');
        $p = PlayerShares::prices($id);
        DB::table('bank_shares')->updateOrInsert(['company_id' => $id], ['units' => 10]);
        // The owner offers 3 just under the bank's price
        $cheap = $p['sells_at'] - 2;
        $this->assertTrue(PlayerShares::order($owner, $id, 'sell', 3, $cheap)['ok']);

        $buyer = $this->player('Ticket Buyer', 100000);
        $q = ShareTicket::quote($buyer, $id, 'buy', 5);
        $this->assertTrue($q['ok']);
        $this->assertSame(5, $q['filled']);
        $this->assertSame([['player', 3, $cheap], ['bank', 2, $p['sells_at']]],
            array_map(fn ($l) => [$l['route'], $l['units'], $l['price']], $q['legs']));
        $this->assertSame(3 * $cheap + 2 * $p['sells_at'], $q['total']);
        $this->assertStringContainsString('Buy 5 now: 3 from Ticket Owner at', $q['text']);
        $this->assertStringContainsString('and 2 from the bank at', $q['text']);
        $this->assertSame(5, $q['after']['held']);

        $r = ShareTicket::now($buyer, $id, 'buy', 5);
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame(5, PlayerShares::held((int) $buyer->user_id, $id));
        $this->assertSame(100000 - $q['total'], (int) DB::table('users')->where('user_id', $buyer->user_id)->value('credits'));
        $this->assertSame(0, DB::table('player_share_orders')->where('user_id', $buyer->user_id)->count(), 'nothing left standing');
        $this->assertSame(8, (int) DB::table('bank_shares')->where('company_id', $id)->value('units'));
    }

    public function testAllOrNothingAndCantPay()
    {
        $owner = $this->player('Poor Owner', 0);
        $id = $this->matured($owner, 'Poor Co');
        DB::table('bank_shares')->updateOrInsert(['company_id' => $id], ['units' => 10]);
        $poor = $this->player('Poor Buyer', 10);
        $q = ShareTicket::quote($poor, $id, 'buy', 2);
        $this->assertFalse($q['can_pay']);
        $r = ShareTicket::now($poor, $id, 'buy', 2);
        $this->assertFalse($r['ok']);
        $this->assertSame(0, PlayerShares::held((int) $poor->user_id, $id));
        // More than anyone sells: only what is there is bought
        $rich = $this->player('Rich Buyer', 10000000);
        $q = ShareTicket::quote($rich, $id, 'buy', 50);
        $this->assertSame(10, $q['filled']);
        $this->assertSame(40, $q['short']);
        $this->assertTrue(ShareTicket::now($rich, $id, 'buy', 50)['ok']);
        $this->assertSame(10, PlayerShares::held((int) $rich->user_id, $id));
    }

    public function testSellNowGoesToTheBestBidThenTheBank()
    {
        $owner = $this->player('Seller Owner', 0);
        $id = $this->matured($owner, 'Sold Co');
        $p = PlayerShares::prices($id);
        $held = PlayerShares::held((int) $owner->user_id, $id);
        $this->assertGreaterThan(5, $held);
        $bidder = $this->player('High Bidder', 1000000);
        $bid = $p['buys_at'] + 3;
        $this->assertTrue(PlayerShares::order($bidder, $id, 'buy', 2, $bid)['ok']);

        $q = ShareTicket::quote($owner, $id, 'sell', 5);
        $this->assertSame([['player', 2, $bid], ['bank', 3, $p['buys_at']]],
            array_map(fn ($l) => [$l['route'], $l['units'], $l['price']], $q['legs']));
        $this->assertStringContainsString('The bank pays in 30 game days', $q['text']);
        $r = ShareTicket::now($owner, $id, 'sell', 5);
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame($held - 5, PlayerShares::held((int) $owner->user_id, $id));
        $this->assertSame(2, PlayerShares::held((int) $bidder->user_id, $id));
        $this->assertSame(1, DB::table('bank_payments')->where('user_id', $owner->user_id)->where('units', 3)->count());
        // Not more than it holds
        $this->assertSame(422, ShareTicket::quote($bidder, $id, 'sell', 3)['status']);
    }

    public function testMovingAnOrderKeepsItsIdAndTime()
    {
        $owner = $this->player('Mover', 1000000);
        $id = $this->matured($owner, 'Moved Co');
        $p = PlayerShares::prices($id);
        $r = PlayerShares::order($owner, $id, 'buy', 4, $p['low']);
        $order = $r['order'];
        DB::table('player_share_orders')->where('id', $order)->update(['game_t' => 1234]);
        $m = PlayerShares::moveOrder($owner, $order, $p['low'] + 1);
        $this->assertTrue($m['ok'], json_encode($m));
        $row = DB::table('player_share_orders')->where('id', $order)->first();
        $this->assertSame([$p['low'] + 1, 1234, 4], [(int) $row->price, (int) $row->game_t, (int) $row->units]);
        // Out of the band: refused, and the order stays as it was
        $bad = PlayerShares::moveOrder($owner, $order, $p['high'] + 100);
        $this->assertFalse($bad['ok']);
        $this->assertSame($p['low'] + 1, (int) DB::table('player_share_orders')->where('id', $order)->value('price'));
        $this->assertSame(404, PlayerShares::moveOrder($this->player('Stranger'), $order, $p['low'])['status']);
    }

    public function testTheWebAndApiEndpoints()
    {
        $owner = $this->player('Web Owner', 0);
        $id = $this->matured($owner, 'Web Co');
        DB::table('bank_shares')->updateOrInsert(['company_id' => $id], ['units' => 10]);
        $buyer = $this->player('Web Buyer', 1000000);
        // A visitor sees a quote
        $this->getJson("/companies/$id/share-quote?side=buy&units=2")->assertOk()->assertJsonPath('filled', 2);
        $this->actingAs($buyer)->post("/companies/$id/share-now", ['side' => 'buy', 'units' => 2])->assertSessionHas('success');
        $this->assertSame(2, PlayerShares::held((int) $buyer->user_id, $id));
        $h = ['Authorization' => 'Bearer ' . Players::issueToken($buyer)];
        $this->getJson("/api/v1/player-shares/$id/quote?side=buy&units=1", $h)->assertOk()->assertJsonPath('legs.0.route', 'bank');
        $this->postJson("/api/v1/player-shares/$id/now", ['side' => 'buy', 'units' => 1], $h)->assertOk()->assertJsonPath('units', 1);
        // A limit under the bank's price: nothing to buy
        $this->postJson("/api/v1/player-shares/$id/now", ['side' => 'buy', 'units' => 1, 'limit' => 1], $h)->assertStatus(409);
    }
}
