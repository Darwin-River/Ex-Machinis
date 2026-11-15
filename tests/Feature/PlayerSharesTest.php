<?php

namespace Tests\Feature;

use App\Services\Players;
use App\Services\PlayerShares;
use App\Services\Seedlings;
use App\Services\World;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Players' shares and the bank (#638): cash-out value, the bank buying (paid
 * later, at the lower value) and selling, players' orders within the range,
 * starting credits only at or below the bank's price, class groups apart.
 */
class PlayerSharesTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['forge.new_rules' => true, 'forge.seedling_hull_value' => 2000, 'forge.seedling_fee' => 500,
            'forge.starting_credits' => 3000, 'forge.player_share_credits' => 5000, 'forge.bank_pay_days' => 30,
            'forge.share_range_low' => 0.8, 'forge.share_range_high' => 1.25, 'forge.hull_life_days' => 365,
            'forge.dividend_permille' => 500, 'forge.seedling_max' => 5, 'forge.seedling_mature_margin' => 0]);
        Mail::fake();
    }

    private function player(string $name): User
    {
        $email = strtolower(str_replace(' ', '.', $name)) . '@example.com';
        $user = Players::create($name, 'long-enough', $email);
        Players::verify($user->user_id, sha1($email));
        return User::where('user_id', $user->user_id)->first();
    }

    /** A matured company of the player's with this many credits once matured */
    private function matured(User $owner, string $name, int $credits): int
    {
        $id = Seedlings::start($owner, $name, "function launched() wait(60) end")['company'];
        DB::table('companies')->where('company_id', $id)->update(['credits' => $credits + Seedlings::grant()]);
        // Fuel aboard its drone (the test world's game data may not have fuel)
        $drone = (int) DB::table('agents')->where('company_id', $id)->value('agent_id');
        DB::table('holdings')->updateOrInsert(['drone' => $drone, 'resource' => \App\Services\Release::FUEL, 'installed' => 0, 'locked' => 0],
            ['units' => 100, 'event_id' => 0]);
        $this->assertTrue(Seedlings::mature($owner, $id)['ok']);
        return $id;
    }

    private function credits(User $u): array
    {
        $r = DB::table('users')->where('user_id', $u->user_id)->first();
        return [(int) $r->credits, (int) $r->share_credits];
    }

    public function testCashOutValue()
    {
        $owner = $this->player('Valuer');
        $id = $this->matured($owner, 'Valued Co', 100000);
        $v = PlayerShares::cashOut($id);
        $this->assertSame(100000, $v['credits']);
        $this->assertGreaterThan(1900, $v['hulls'], 'its drone, fuelled and new');
        $this->assertLessThanOrEqual(2000, $v['hulls']);
        $this->assertSame(intdiv($v['total'], PlayerShares::COUNT), $v['per_share']);
        $p = PlayerShares::prices($id);
        $this->assertSame($v['per_share'], $p['buys_at']);
        $this->assertSame($p['buys_at'], $p['sells_at'], 'no profit yet, so no dividends');
        $this->assertSame((int) floor($p['sells_at'] * 0.8), $p['low']);

        // Goods count at the House's listed bid for the game day where the
        // drone is, not its live bid (#670): it doesn't flicker
        $drone = (int) DB::table('agents')->where('company_id', $id)->value('agent_id');
        $place = (int) DB::table('agents')->where('agent_id', $drone)->value('object_id');
        $station = (int) DB::table('stations')->max('id') + 1;
        DB::table('stations')->insert(['id' => $station, 'name' => 'Test Exchange', 'object_id' => $place]);
        DB::table('station_goods')->insert(['station_id' => $station, 'resource' => 2011, 'side' => 1, 'price' => 20,
            'daily_units' => 100, 'from_day' => 0, 'steady' => 1]);
        DB::table('holdings')->updateOrInsert(['drone' => $drone, 'resource' => 2011, 'installed' => 0, 'locked' => 0],
            ['units' => 10, 'event_id' => 0]);
        $listed = (int) DB::table('house_market')->where('station_id', $station)->where('resource', 2011)->where('side', 1)->value('price');
        $this->assertGreaterThan(0, $listed);
        $this->assertSame(10 * $listed, PlayerShares::cashOut($id)['goods']);
        DB::table('holdings')->where('drone', $drone)->where('resource', 2011)->delete();

        // A stranded drone's hull counts nothing
        $drone = (int) DB::table('agents')->where('company_id', $id)->value('agent_id');
        DB::table('holdings')->where('drone', $drone)->where('resource', \App\Services\Release::FUEL)->update(['units' => 0]);
        $this->assertSame(0, PlayerShares::cashOut($id)['hulls']);

        // Profit alone doesn't raise the bank's selling price: only dividends
        // actually paid since maturing do (#673)
        $epoch = (int) DB::table('game_clock')->where('id', 1)->value('epoch');
        $today = intdiv(World::gameNow() - $epoch, 86400);
        DB::table('company_profits')->insert(['company' => $id, 'day' => $today - 1, 'sales' => 40000, 'purchases' => 0,
            'profit' => 40000, 'carry_before' => 0, 'paid' => 0, 'holders' => 0, 'logged_only' => 0]);
        $this->assertSame(0, PlayerShares::dividends30($id), 'nothing paid yet');
        $this->assertSame(PlayerShares::prices($id)['buys_at'], PlayerShares::prices($id)['sells_at']);
        DB::table('player_dividends')->insert(['company_id' => $id, 'user_id' => $owner->user_id, 'day' => $today - 1,
            'units' => 100, 'amount' => 20000]);
        $this->assertSame(200, PlayerShares::dividends30($id), '20,000 paid over 100 shares');
        $this->assertSame(PlayerShares::prices($id)['buys_at'] + 200, PlayerShares::prices($id)['sells_at']);
    }

    public function testTheBankBuysLaterAtTheLowerValueAndSellsWhatItHolds()
    {
        $owner = $this->player('Seller');
        $id = $this->matured($owner, 'Bank Co', 100000);
        $price = PlayerShares::cashOut($id)['per_share'];
        $r = PlayerShares::sellToBank($owner, $id, 40);
        $this->assertTrue($r['ok']);
        $this->assertSame(60, PlayerShares::held((int) $owner->user_id, $id));
        $this->assertSame(40, PlayerShares::prices($id)['bank_holds']);
        $this->assertSame(422, PlayerShares::sellToBank($owner, $id, 61)['status']);

        // Not paid before it is due
        PlayerShares::settleBankPayments();
        $this->assertSame(0, $this->credits($owner)[0]);
        // Then the company runs down: it is paid at the lower value
        DB::table('companies')->where('company_id', $id)->update(['credits' => 50000]);
        DB::table('bank_payments')->where('user_id', $owner->user_id)->update(['due_t' => World::gameNow() - 1]);
        $this->assertSame(1, PlayerShares::settleBankPayments());
        $lower = PlayerShares::cashOut($id)['per_share'];
        $this->assertLessThan($price, $lower);
        $this->assertSame(40 * $lower, $this->credits($owner)[0]);
        PlayerShares::settleBankPayments();
        $this->assertSame(40 * $lower, $this->credits($owner)[0], 'paid once');

        // Another player buys from the bank at its selling price, starting credits first
        $buyer = $this->player('Bank Buyer');
        $sells = PlayerShares::prices($id)['sells_at'];
        $r = PlayerShares::buyFromBank($buyer, $id, 2);
        $this->assertTrue($r['ok']);
        $this->assertSame([0, 5000 - 2 * $sells], $this->credits($buyer));
        $this->assertSame(38, PlayerShares::prices($id)['bank_holds']);
        $this->assertSame(409, PlayerShares::buyFromBank($buyer, $id, 39)['status']);
    }

    public function testPlayersTradeWithinTheRange()
    {
        $owner = $this->player('Founder');
        $id = $this->matured($owner, 'Traded Co', 100000);
        $p = PlayerShares::prices($id);
        $buyer = $this->player('Investor');

        $this->assertSame(422, PlayerShares::order($owner, $id, 'sell', 10, $p['high'] + 1)['status'], 'too dear');
        $this->assertSame(422, PlayerShares::order($owner, $id, 'sell', 10, $p['low'] - 1)['status'], 'too cheap');
        $this->assertSame(422, PlayerShares::order($buyer, $id, 'sell', 10, $p['sells_at'])['status'], 'holds none');

        // A sell order stands; a buy order at or above it trades at its price
        $this->assertSame(10, PlayerShares::order($owner, $id, 'sell', 10, $p['sells_at'])['standing']);
        $r = PlayerShares::order($buyer, $id, 'buy', 4, $p['high']);
        $this->assertSame(4, $r['traded']);
        $this->assertSame(0, $r['standing']);
        $this->assertSame(4 * $p['sells_at'], $r['credits'], 'at the standing order\'s price');
        $this->assertSame(4, PlayerShares::held((int) $buyer->user_id, $id));
        $this->assertSame(96, PlayerShares::held((int) $owner->user_id, $id));
        $this->assertSame(4 * $p['sells_at'], $this->credits($owner)[0], 'the seller is paid in credits');
        $this->assertSame([0, 5000 - 4 * $p['sells_at']], $this->credits($buyer), 'starting credits at the bank\'s price');
        $this->assertSame(6, PlayerShares::book($id)['sell'][0]['units']);

        // Above the bank's selling price, starting credits can't pay
        PlayerShares::cancel($owner, PlayerShares::book($id)['sell'][0]['id']);
        PlayerShares::order($owner, $id, 'sell', 5, $p['high']);
        $r = PlayerShares::order($buyer, $id, 'buy', 5, $p['high']);
        $this->assertFalse($r['ok'], 'it has no ordinary credits, so the order can\'t stand (#665)');
        $this->assertSame(422, $r['status']);
        $this->assertSame([], PlayerShares::of($buyer)['orders']);
        // Nor can buy orders commit more starting credits than it has
        $left = $this->credits($buyer)[1];
        $this->assertSame(422, PlayerShares::order($buyer, $id, 'buy', intdiv($left, $p['low']) + 1, $p['low'])['status']);
        // The book shows only orders within today's range
        $stale = DB::table('player_share_orders')->insertGetId(['user_id' => $owner->user_id, 'company_id' => $id,
            'side' => 'sell', 'units' => 1, 'price' => $p['low'] - 1, 'created_at' => now()]);
        $this->assertNotContains($stale, array_column(PlayerShares::book($id)['sell'], 'id'));
        // and a bank purchase of no shares says so
        $this->assertSame(422, PlayerShares::buyFromBank($buyer, $id, 0)['status']);

        // The account page and the company page show it
        $this->actingAs($buyer)->get('/account')->assertOk()->assertSee('Traded Co')->assertSee('Your shares');
        $this->actingAs($buyer)->get('/companies/' . $id)->assertOk()->assertSee('You hold 4 of its shares')
            ->assertSee('id="player-shares"', false);
    }

    public function testAClassGroupTradesOnlyWithinItself()
    {
        $owner = $this->player('Public Founder');
        $id = $this->matured($owner, 'Public Co', 100000);
        $p = PlayerShares::prices($id);
        $teacher = $this->player('Class Teacher');
        $group = Players::createClassGroup($teacher, 'Nebula', 1);
        $pupil = User::where('login', 'Nebula 1')->first();
        PlayerShares::order($owner, $id, 'sell', 10, $p['sells_at']);
        $r = PlayerShares::order($pupil, $id, 'buy', 2, $p['sells_at']);
        $this->assertSame(0, $r['traded'], 'the public sells to the public');
        $this->assertSame(0, PlayerShares::held((int) $pupil->user_id, $id));
        $this->assertNotNull($group['id']);
    }

    public function testSeedlingsHaveNoSharesToTrade()
    {
        $owner = $this->player('Grower');
        $seed = Seedlings::start($owner, 'Young Co', "function launched() wait(60) end")['company'];
        $this->assertSame(404, PlayerShares::sellToBank($owner, $seed, 1)['status']);
        $this->assertSame(404, PlayerShares::order($owner, $seed, 'buy', 1, 1)['status']);
        $this->get('/companies/' . $seed)->assertDontSee('id="player-shares"', false);
    }

    public function testDividendsArePaidToShareholders()
    {
        $owner = $this->player('Earner');
        $id = $this->matured($owner, 'Dividend Co', 100000);
        $partner = $this->player('Partner');
        DB::table('player_shares')->where('user_id', $owner->user_id)->where('company_id', $id)->update(['units' => 70]);
        DB::table('player_shares')->insert(['user_id' => $partner->user_id, 'company_id' => $id, 'units' => 20]);
        DB::table('bank_shares')->insert(['company_id' => $id, 'units' => 10]);
        $epoch = (int) DB::table('game_clock')->where('id', 1)->value('epoch');
        $matured = intdiv((int) DB::table('companies')->where('company_id', $id)->value('matured_t') - $epoch, 86400);
        $day = fn ($d, $profit, $carry) => DB::table('company_profits')->insert(['company' => $id, 'day' => $d,
            'sales' => max(0, $profit), 'purchases' => 0, 'profit' => $profit, 'carry_before' => $carry, 'paid' => 0,
            'holders' => 0, 'logged_only' => 0]);
        $day($matured, 50000, 0);          // the day it matured: not paid
        $day($matured + 1, 10000, 0);      // half of 10,000
        $day($matured + 2, 4000, -3000);   // losses carried first: half of 1,000
        $day($matured + 3, -2000, 0);      // a loss pays nothing

        $this->assertSame(3, PlayerShares::payDividends());
        $this->assertSame(0, PlayerShares::payDividends(), 'each day once');
        // 5,000 + 500 = 5,500 shared 70% / 20% / 10% (the bank's stays with the bank)
        $this->assertSame(3500 + 350, $this->credits($owner)[0]);
        $this->assertSame(1000 + 100, $this->credits($partner)[0]);
        $this->assertSame(500 + 50, (int) DB::table('player_dividends')->whereNull('user_id')->where('company_id', $id)->sum('amount'));
        $this->assertSame(100000 - 5500, (int) DB::table('companies')->where('company_id', $id)->value('credits'));
        $this->actingAs($partner)->get('/account')->assertSee('paid you 1,100 credits in dividends');

        // Never more than its credits
        DB::table('companies')->where('company_id', $id)->update(['credits' => 1000]);
        $day($matured + 4, 100000, 0);
        PlayerShares::payDividends();
        $this->assertSame(0, (int) DB::table('companies')->where('company_id', $id)->value('credits'));
    }

    public function testTheApi()
    {
        $owner = $this->player('Api Founder');
        $id = $this->matured($owner, 'Api Co', 100000);
        $h = ['Authorization' => 'Bearer ' . Players::issueToken($owner)];
        // The company's API shows players' shares and its locked code (#666)
        $this->getJson("/api/v1/companies/$id")->assertOk()->assertJsonPath('shares.count', PlayerShares::COUNT)
            ->assertJsonPath('shares.holders.0.name', 'Api Founder')->assertJsonPath('pushes.locked', true);
        $this->getJson('/api/v1/shares')->assertOk()->assertJsonPath('player_shares.0.company', $id);
        $this->get('/market/shares')->assertOk()->assertSee('Api Co');
        $this->getJson("/api/v1/player-shares/$id")->assertOk()->assertJsonPath('company', $id)
            ->assertJsonStructure(['buys_at', 'sells_at', 'low', 'high', 'bank_holds', 'book' => ['sell', 'buy']]);
        $sells = PlayerShares::prices($id)['sells_at'];
        $order = $this->postJson("/api/v1/player-shares/$id/orders", ['side' => 'sell', 'units' => 3, 'price' => $sells], $h)
            ->assertOk()->assertJsonPath('standing', 3)->json('order');
        $this->getJson('/api/v1/account/shares', $h)->assertOk()->assertJsonPath('holdings.0.units', 100)
            ->assertJsonPath('orders.0.id', $order);
        $this->postJson("/api/v1/player-shares/orders/$order/cancel", [], $h)->assertOk();
        $this->postJson("/api/v1/player-shares/$id/bank/sell", ['units' => 2], $h)->assertOk()->assertJsonPath('units', 2);
        $this->postJson("/api/v1/player-shares/$id/bank/buy", ['units' => 1], $h)->assertOk();
        $this->postJson("/api/v1/player-shares/$id/bank/sell", ['units' => 2])->assertStatus(401);
        // Whole numbers only, and clear messages (#674)
        $this->postJson("/api/v1/player-shares/$id/orders", ['side' => 'buy', 'units' => 1, 'price' => 70.5], $h)->assertStatus(422);
        $this->postJson("/api/v1/player-shares/$id/orders", ['side' => 'buy', 'units' => 0.5, 'price' => 70], $h)->assertStatus(422);
        $this->postJson("/api/v1/player-shares/$id/bank/sell", ['units' => 0], $h)->assertStatus(422)
            ->assertJsonPath('error', 'Sell at least 1 whole share.');
        $this->postJson("/api/v1/player-shares/$id/bank/sell", ['units' => 0.5], $h)->assertStatus(422)
            ->assertJsonPath('error', 'Sell at least 1 whole share.');
        // Only a matured company's shares trade, and only it has prices (#652)
        $seedling = Seedlings::start($owner, 'Api Seedling', "function launched() wait(60) end")['company'];
        $this->getJson("/api/v1/player-shares/$seedling")->assertNotFound();
    }
}
