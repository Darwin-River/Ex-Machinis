<?php

namespace Tests\Feature;

use App\Services\Players;
use App\Services\PlayerShares;
use App\Services\Startups;
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
        config(['forge.new_rules' => true, 'forge.startup_hull_value' => 2000, 'forge.startup_fee' => 500,
            'forge.starting_credits' => 3000, 'forge.player_share_credits' => 5000, 'forge.bank_pay_days' => 30,
            'forge.share_range_low' => 0.8, 'forge.share_range_high' => 1.25, 'forge.hull_life_days' => 365,
            'forge.dividend_permille' => 500, 'forge.startup_max' => 5, 'forge.startup_mature_margin' => 0]);
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
        $id = Startups::start($owner, $name, "function launched() wait(60) end")['company'];
        DB::table('companies')->where('company_id', $id)->update(['credits' => $credits + Startups::grant()]);
        // Fuel aboard its drone (the test world's game data may not have fuel)
        $drone = (int) DB::table('agents')->where('company_id', $id)->value('agent_id');
        DB::table('holdings')->updateOrInsert(['drone' => $drone, 'resource' => \App\Services\Release::FUEL, 'installed' => 0, 'locked' => 0],
            ['units' => 100, 'event_id' => 0]);
        $this->assertTrue(Startups::mature($owner, $id)['ok']);
        return $id;
    }

    private function credits(User $u): array
    {
        $r = DB::table('users')->where('user_id', $u->user_id)->first();
        return [(int) $r->credits, (int) $r->share_credits];
    }

    /** #839: a company's page and the dashboard say whether its capital is working */
    public function testCapitalAtWork()
    {
        $owner = $this->player('Idle Owner');
        $id = $this->matured($owner, 'Idle Co', 100000);
        $this->get('/companies/' . $id)->assertOk()->assertSee('id="capital-working"', false)
            ->assertSee('Is its capital working?')->assertSee('credits 100,000');
        $tiles = \App\Services\Dashboard::summaryNow()['tiles'];
        $this->assertGreaterThanOrEqual(100000, $tiles['capital']['credits']);
        $this->assertGreaterThan(90, $tiles['capital']['idle_percent'], 'nearly all of it idle credits');
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

        // Goods count at each good's best listed House bid anywhere,
        // averaged over the last 7 game days recorded (#685): not the bid
        // where the drone is, which jumped as drones moved (round 70)
        $drone = (int) DB::table('agents')->where('company_id', $id)->value('agent_id');
        $station = (int) DB::table('stations')->max('id') + 1;
        DB::table('stations')->insert(['id' => $station, 'name' => 'Test Exchange', 'object_id' => 999999]);
        DB::table('station_goods')->insert(['station_id' => $station, 'resource' => 2011, 'side' => 1, 'price' => 20,
            'daily_units' => 100, 'from_day' => 0, 'steady' => 1]);
        DB::table('holdings')->updateOrInsert(['drone' => $drone, 'resource' => 2011, 'installed' => 0, 'locked' => 0],
            ['units' => 10, 'event_id' => 0]);
        $best = (int) DB::table('house_market')->where('resource', 2011)->where('side', 1)->max('price');
        $this->assertGreaterThan(0, $best);
        $today = \App\Services\HouseMarket::today(DB::connection());
        DB::table('house_best_bids')->delete();
        \Illuminate\Support\Facades\Cache::flush();
        $this->assertSame(10 * $best, PlayerShares::cashOut($id)['goods'], 'today only: today\'s best bid, at an exchange the drone is nowhere near');

        // Over 7 game days: the average, so one day's swing moves it a seventh as much
        DB::table('house_best_bids')->insert(['day' => $today - 1, 'resource' => 2011, 'price' => $best + 70]);
        DB::table('house_best_bids')->insert(['day' => $today - 9, 'resource' => 2011, 'price' => 100000]);   // too old to count
        \Illuminate\Support\Facades\Cache::flush();
        $this->assertSame(10 * intdiv(2 * $best + 70, 2), PlayerShares::cashOut($id)['goods']);
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
        // #781: the bank adds the last 10 game days of dividends, not 30, so
        // dividends paid 20 game days ago count in the yield but not the price
        DB::table('player_dividends')->insert(['company_id' => $id, 'user_id' => $owner->user_id, 'day' => $today - 20,
            'units' => 100, 'amount' => 30000]);
        $this->assertSame(500, PlayerShares::dividends30($id), 'both payments within 30 game days');
        $this->assertSame(PlayerShares::prices($id)['buys_at'] + 200, PlayerShares::prices($id)['sells_at'], 'only the last 10 game days');
        $this->assertSame(500, collect(PlayerShares::market())->firstWhere('company', $id)['dividends30']);
    }

    /** #766: a pegged order follows the bank's price, and trades when a move makes it meet another */
    public function testPeggedOrdersFollowTheBank()
    {
        $owner = $this->player('Peg Seller');
        $id = $this->matured($owner, 'Peg Co', 100000);
        $p = PlayerShares::prices($id);
        // A sell pegged 5% over what the bank sells at
        $r = PlayerShares::order($owner, $id, 'sell', 10, 0, ['ref' => 'sells_at', 'offset_bp' => 500]);
        $this->assertTrue($r['ok']);
        $this->assertSame(PlayerShares::pegPrice($p, 'sells_at', 500, 'sell'), $r['price']);
        $orderId = $r['order'];
        $book = PlayerShares::book($id)['sell'][0];
        $this->assertSame(['sells_at', 5.0], [$book['peg'], $book['offset_percent']]);
        // Out of bounds
        $this->assertSame(422, PlayerShares::order($owner, $id, 'sell', 1, 0, ['ref' => 'sells_at', 'offset_bp' => 3000])['status']);
        $this->assertSame(422, PlayerShares::order($owner, $id, 'sell', 1, 0, ['ref' => 'cash', 'offset_bp' => 0])['status']);

        // A buyer bids a fixed price under it: no trade
        $buyer = $this->player('Peg Buyer');
        DB::table('users')->where('user_id', $buyer->user_id)->update(['credits' => 100000]);
        $bid = $p['sells_at'];
        $this->assertSame(0, PlayerShares::order($buyer, $id, 'buy', 4, $bid)['traded']);
        // The company runs down: cash-out falls, the pegged sell moves down to meet the bid, and trades
        DB::table('companies')->where('company_id', $id)->update(['credits' => 90000]);
        $this->assertGreaterThanOrEqual(1, PlayerShares::repeg());
        $this->assertSame(4, PlayerShares::held((int) $buyer->user_id, $id));
        $left = DB::table('player_share_orders')->where('company_id', $id)->where('side', 'sell')->first();
        $this->assertSame($orderId, (int) $left->id, 'a repriced order keeps its id (#873)');
        $this->assertSame(6, (int) $left->units);
        $this->assertSame('sells_at', $left->peg_ref);
        $this->assertSame(PlayerShares::pegPrice(PlayerShares::prices($id), 'sells_at', 500, 'sell'), (int) $left->price);
        // Nothing moves when nothing changed
        $this->assertSame(0, PlayerShares::repeg());
        // A pegged sell never stands under what the bank pays (#831), and a price sent with a peg is set aside, with a note
        $p = PlayerShares::prices($id);
        $under = PlayerShares::order($owner, $id, 'sell', 1, 99999, ['ref' => 'sells_at', 'offset_bp' => -2000]);
        $this->assertTrue($under['ok']);
        $this->assertGreaterThanOrEqual($p['buys_at'], $under['price']);
        $this->assertStringContainsString('the price you sent was set aside', $under['note']);
        // The API takes a peg
        $this->withHeaders(['Authorization' => 'Bearer ' . Players::issueToken($owner)])->postJson('/api/v1/player-shares/' . $id . '/orders', ['side' => 'sell', 'units' => 1, 'peg' => 'buys_at', 'offset_percent' => 2.5])
            ->assertOk()->assertJsonPath('standing', 1);
    }

    /** #757: a dissolving company's payment waits, and is 0 if it ends; the bank doesn't sell its shares */
    public function testTheBankDoesntPayForACompanyThatEnds()
    {
        $owner = $this->player('Dying Seller');
        $id = $this->matured($owner, 'Dying Co', 100000);
        $this->assertTrue(PlayerShares::sellToBank($owner, $id, 40)['ok']);
        DB::table('companies')->where('company_id', $id)->update(['broke_since' => World::gameNow() - 20 * 86400]);
        DB::table('bank_payments')->where('user_id', $owner->user_id)->update(['due_t' => World::gameNow() - 1]);

        // Due, but the company is counting down to dissolving: it waits
        PlayerShares::settleBankPayments();
        $this->assertNull(DB::table('bank_payments')->where('user_id', $owner->user_id)->value('paid_t'));
        $this->assertSame(0, $this->credits($owner)[0]);
        // Nobody buys its shares from the bank meanwhile
        $r = PlayerShares::buyFromBank($this->player('Late Buyer'), $id, 1);
        $this->assertSame(409, $r['status']);
        $this->assertStringContainsString('dissolves in', $r['error']);

        // It ends: the payment is 0
        DB::table('companies')->where('company_id', $id)->update(['eliminated_at' => World::gameNow()]);
        PlayerShares::settleBankPayments();
        $this->assertSame(0, (int) DB::table('bank_payments')->where('user_id', $owner->user_id)->value('paid'));
        $this->assertNotNull(DB::table('bank_payments')->where('user_id', $owner->user_id)->value('paid_t'));
        $this->assertSame(0, $this->credits($owner)[0]);
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

        // The trade is public (#676): the stock exchange, the API and the company page
        $t = PlayerShares::trades($id)[0];
        $this->assertSame([4, $p['sells_at'], 'Founder', 'Investor'], [$t['units'], $t['price'], $t['seller'], $t['buyer']]);
        $this->getJson("/api/v1/player-shares/$id")->assertOk()->assertJsonPath('trades.0.buyer', 'Investor');
        $this->getJson('/api/v1/shares')->assertOk()->assertJsonPath('player_share_trades.0.company', $id);
        $this->get('/market/shares')->assertOk()->assertSee('Latest share trades')->assertSee('Investor');
        $this->get('/companies/' . $id)->assertOk()->assertSee('id="player-share-trades"', false);

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

    public function testARestingOrderEarnsARebate()
    {
        // #782: 0.5% of the trade from the bank to the resting order's owner,
        // once the order has rested a game hour; the two are always different players
        config(['forge.rebate_permille' => 5, 'forge.rebate_rest_game_seconds' => 3600]);
        $owner = $this->player('Rester');
        $id = $this->matured($owner, 'Rebate Co', 100000);
        $p = PlayerShares::prices($id);
        $buyer = $this->player('Taker');
        DB::table('users')->where('user_id', $buyer->user_id)->update(['credits' => 1000000]);

        // Placed just now: it trades, but earns nothing
        PlayerShares::order($owner, $id, 'sell', 20, $p['sells_at']);
        PlayerShares::order($buyer, $id, 'buy', 5, $p['sells_at']);
        $this->assertSame(5 * $p['sells_at'], $this->credits($owner)[0], 'no rebate under a game hour');
        $this->assertSame(0, PlayerShares::trades($id)[0]['rebate']);

        // A game hour later it does: 0.5% of the credits, rounded down
        DB::table('player_share_orders')->where('user_id', $owner->user_id)->update(['game_t' => World::gameNow() - 3600]);
        PlayerShares::order($buyer, $id, 'buy', 10, $p['sells_at']);
        $credits = 10 * $p['sells_at'];
        $rebate = intdiv($credits * 5, 1000);
        $this->assertGreaterThan(0, $rebate);
        $this->assertSame(15 * $p['sells_at'] + $rebate, $this->credits($owner)[0], 'the seller is paid the trade and the rebate');
        $t = PlayerShares::trades($id)[0];
        $this->assertSame([$rebate, 'seller'], [$t['rebate'], $t['rebate_to']]);
        $this->assertSame($rebate, PlayerShares::fills((int) $owner->user_id, 0)[0]['rebate']);
        $this->assertSame(0, PlayerShares::fills((int) $buyer->user_id, 0)[0]['rebate'], 'the taker gets none');
        $this->actingAs($owner)->get('/account')->assertOk()->assertSee('rebate from the bank for your resting order');

        // An order from before the rebate (no placed time) counts as rested
        DB::table('player_share_orders')->where('user_id', $owner->user_id)->update(['game_t' => null]);
        $this->assertSame(intdiv(100 * 5, 1000), PlayerShares::rebate(100, null));
        // and a pegged order keeps its rest when the bank's prices move it
        $placed = World::gameNow() - 7200;
        PlayerShares::order($buyer, $id, 'buy', 1, $p['low'], ['ref' => 'buys_at', 'offset_bp' => -1000]);
        DB::table('player_share_orders')->where('user_id', $buyer->user_id)->update(['game_t' => $placed, 'price' => $p['low'] + 1]);
        PlayerShares::repeg();
        $this->assertSame($placed, (int) DB::table('player_share_orders')->where('user_id', $buyer->user_id)->value('game_t'));
    }

    public function testNewSharesFundTheCompany()
    {
        // #780: its owner offers 10 new shares at a time at cash-out; any
        // player (its owner too) buys; the credits go to the company; at most 200
        $owner = $this->player('Grower');
        $id = $this->matured($owner, 'Growth Co', 100000);
        $other = $this->player('Backer');
        DB::table('users')->whereIn('user_id', [$owner->user_id, $other->user_id])->update(['credits' => 1000000]);
        $this->assertSame(100, PlayerShares::count($id));
        $this->assertSame(403, PlayerShares::offer($other, $id)['status'], 'only its owner offers');
        $this->assertSame(409, PlayerShares::buyOffered($other, $id, 1)['status'], 'nothing on offer yet');

        $before = PlayerShares::prices($id)['buys_at'];
        $credits = (int) DB::table('companies')->where('company_id', $id)->value('credits');
        $r = PlayerShares::offer($owner, $id);
        $this->assertTrue($r['ok']);
        $this->assertSame(10, $r['units']);
        $this->assertSame(409, PlayerShares::offer($owner, $id)['status'], 'one offering at a time');
        $this->assertSame(10, PlayerShares::offering($id)['left']);

        // A backer buys 6 at cash-out: the company gets the credits, the count grows
        $b = PlayerShares::buyOffered($other, $id, 6);
        $this->assertTrue($b['ok']);
        $this->assertSame([6, $before, 6 * $before, 4, 106], [$b['units'], $b['price'], $b['cost'], $b['left'], $b['count']]);
        $this->assertSame($credits + 6 * $before, (int) DB::table('companies')->where('company_id', $id)->value('credits'));
        $this->assertSame(6, PlayerShares::held((int) $other->user_id, $id));
        $this->assertEqualsWithDelta($before, PlayerShares::prices($id)['buys_at'], 1, 'buying at cash-out leaves a share\'s cash-out as it was');
        $this->assertSame(409, PlayerShares::buyOffered($other, $id, 5)['status'], 'only 4 left');
        // its owner buys the rest; the offering closes
        $this->assertTrue(PlayerShares::buyOffered($owner, $id, 4)['ok']);
        $this->assertNull(PlayerShares::offering($id));
        $this->assertSame(110, PlayerShares::count($id));

        // Ownership by percent, and the trade shown as new shares, not the bank's
        $holders = collect(PlayerShares::holders($id))->keyBy('name');
        $this->assertSame(round(100 * 104 / 110, 1), $holders['Grower']['percent']);
        $this->assertSame(round(100 * 6 / 110, 1), $holders['Backer']['percent']);
        $t = PlayerShares::trades($id)[0];
        $this->assertSame([true, 'New shares', false], [$t['issued'], $t['seller_name'], $t['seller_is_bank']]);
        $this->assertSame('the company (new shares)', PlayerShares::fills((int) $owner->user_id, 0)[0]['with']);

        // Shown on the company page, the stock exchange, its charts' marks and the API
        $this->actingAs($owner)->get('/companies/' . $id)->assertOk()->assertSee('id="share-count"', false)->assertSee('110')
            ->assertSee('Of the company')->assertSee('Offer new shares');
        \Illuminate\Support\Facades\Cache::flush();
        $this->get('/market/shares')->assertOk()->assertSee('110 shares');
        $this->assertContains('offered', array_column(\App\Services\WorldEvents::company($id), 'kind'));
        $this->getJson("/api/v1/player-shares/$id")->assertOk()->assertJsonPath('count', 110)->assertJsonPath('offering', null);

        // #874: with a player bidding over cash-out, new shares cost the bid, so minting can't undercut the market
        $buys = PlayerShares::prices($id)['buys_at'];
        $high = $buys + 40;
        $this->assertTrue(PlayerShares::order($other, $id, 'buy', 1, $high)['ok']);
        $this->assertSame($high, PlayerShares::offerPrice($id));
        $this->assertTrue(PlayerShares::offer($owner, $id)['ok']);
        $this->assertSame($high, PlayerShares::offering($id)['price']);
        $paid = PlayerShares::buyOffered($owner, $id, 1);
        $this->assertSame($high, $paid['price']);
        $this->assertGreaterThanOrEqual($buys, PlayerShares::prices($id)['buys_at'], 'no holder\'s cash-out falls');
        $this->assertTrue(PlayerShares::withdraw($owner, $id));
        DB::table('player_share_orders')->where('company_id', $id)->delete();

        // The web form offers again, and its owner can withdraw; the cap is 200
        $this->actingAs($owner)->post('/companies/' . $id . '/offering')->assertRedirect();
        $this->assertSame(10, PlayerShares::offering($id)['left']);
        $this->actingAs($other)->post('/companies/' . $id . '/offering/buy', ['units' => 2])->assertRedirect();
        $this->assertSame(113, PlayerShares::count($id));
        $this->assertTrue(PlayerShares::withdraw($owner, $id));
        $this->assertNull(PlayerShares::offering($id));
        DB::table('companies')->where('company_id', $id)->update(['player_share_count' => 195]);
        $this->assertSame(409, PlayerShares::offer($owner, $id)['status'], 'past 200');
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

    public function testStartupsHaveNoSharesToTrade()
    {
        $owner = $this->player('Grower');
        $seed = Startups::start($owner, 'Young Co', "function launched() wait(60) end")['company'];
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
        $this->getJson('/api/v1/shares')->assertOk()->assertJsonPath('player_shares.0.company', $id)
            ->assertJsonPath('companies.0.company', $id);   // the page's rows, not the old rules' market (#881)
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
        // A fraction says why (#715: 1.5 answered "Buy at least 1 whole share.")
        $this->postJson("/api/v1/player-shares/$id/bank/sell", ['units' => 0.5], $h)->assertStatus(422)
            ->assertJsonPath('error', "Shares are whole: 0.5 isn't a whole number.");
        // Only a matured company's shares trade, and only it has prices (#652)
        $startup = Startups::start($owner, 'Api Startup', "function launched() wait(60) end")['company'];
        $this->getJson("/api/v1/player-shares/$startup")->assertNotFound();
    }

    /** #683: selling to the bank trims sell orders; sell orders never offer more than held; own orders never cross */
    public function testSellOrdersFollowHoldingsAndNeverCrossOwnOrders()
    {
        $owner = $this->player('Trim Founder');
        $id = $this->matured($owner, 'Trim Co', 100000);
        $p = PlayerShares::prices($id);
        PlayerShares::order($owner, $id, 'sell', 60, $p['high']);
        PlayerShares::order($owner, $id, 'sell', 30, $p['sells_at']);
        $this->assertSame(422, PlayerShares::order($owner, $id, 'sell', 20, $p['high'])['status'], 'only 10 not yet offered');

        $r = PlayerShares::sellToBank($owner, $id, 50);
        $this->assertTrue($r['ok']);
        $this->assertSame(40, $r['orders_trimmed']);
        $orders = PlayerShares::of($owner)['orders'];
        $this->assertSame(50, array_sum(array_column($orders, 'units')), 'no more offered than held');
        $this->assertSame([50], array_values(array_column(array_filter($orders, fn ($o) => $o['price'] === $p['high']), 'units')),
            'the oldest order keeps the rest');
        $this->assertSame([], array_values(array_filter($orders, fn ($o) => $o['price'] === $p['sells_at'])), 'the newest order went first');

        // A buy at or above its own sell crosses: refused
        DB::table('users')->where('user_id', $owner->user_id)->update(['credits' => 1000000]);
        $buy = PlayerShares::order($owner, $id, 'buy', 1, $p['high']);
        $this->assertSame(422, $buy['status']);
        $this->assertStringContainsString('cross', $buy['error']);
        // Below its own sell it stands; then a sell at or below that buy is refused
        $this->assertTrue(PlayerShares::order($owner, $id, 'buy', 1, $p['low'])['ok']);
        $this->assertSame(422, PlayerShares::order($owner, $id, 'sell', 1, $p['low'])['status']);
    }

    /** #686: no old-rules leftovers on a matured or ended company's page; the bank named in trades */
    public function testNoOldRulesLeftovers()
    {
        $owner = $this->player('Tidy Founder');
        $id = $this->matured($owner, 'Tidy Co', 100000);
        $this->get('/companies/' . $id)->assertOk()->assertSee('its code is locked for good')
            ->assertDontSee('never traded')->assertDontSee('push password')->assertDontSee('No dividends until')
            ->assertDontSee('id="shares"', false);

        // A sale to the bank: the bank is named, on the site and in the API
        PlayerShares::sellToBank($owner, $id, 3);
        $t = PlayerShares::trades($id)[0];
        $this->assertSame([null, 'The bank', true, false], [$t['buyer'], $t['buyer_name'], $t['buyer_is_bank'], $t['seller_is_bank']]);
        $this->get('/market/shares')->assertOk()->assertSee('The bank')
            ->assertDontSee('Every company starts with 1,000 shares')->assertDontSee('No company has traded or offered shares yet');
        $this->getJson("/api/v1/player-shares/$id")->assertJsonPath('trades.0.buyer_name', 'The bank')
            ->assertJsonPath('trades.0.buyer_is_bank', true);

        // A closed startup owes nothing and isn't at risk
        $seed = Startups::start($owner, 'Closed Co', "function launched() wait(60) end")['company'];
        Startups::close($owner, $seed);
        DB::table('companies')->where('company_id', $seed)->update(['debt' => 5500]);   // as a world reset leaves it
        $this->get('/companies/' . $seed)->assertOk()->assertSee('it owes nothing')->assertDontSee('it owes the bank')
            ->assertDontSee('At risk');
        $this->assertFalse(\App\Services\CompanyHealth::of($seed)['at_risk']);
    }
}
