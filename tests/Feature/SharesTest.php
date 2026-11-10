<?php

namespace Tests\Feature;

use App\Services\Leaderboard;
use App\Services\Release;
use App\Services\Shares;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Shares (#467, step 1 of the stock market #466; migration 0038): every
 * company starts holding its own 1,000 shares, shares move between holders,
 * and a company that ends loses the shares it held to the House.
 */
class SharesTest extends TestCase
{
    use DatabaseTransactions;

    private function company(string $name, int $house = 0): int
    {
        $user = User::create(['name' => $name, 'email' => uniqid() . '@example.com', 'password' => bcrypt('secret-password')]);
        return (int) DB::table('companies')->insertGetId(['user_id' => $user->user_id, 'name' => $name, 'credits' => 0, 'house' => $house]);
    }

    /** A company a game day old: its sales a day average over that one day (#492) */
    private function dayOld(int $company): void
    {
        DB::table('companies')->where('company_id', $company)->update(['founded_t' => \App\Services\World::gameNow() - 86400 - 60]);
    }

    private function house(): int
    {
        return (int) (DB::table('companies')->where('house', 1)->min('company_id') ?: $this->company('The House', 1));
    }

    public function testANewCompanyHoldsAllItsOwnShares()
    {
        $c = $this->company('Fresh Co');
        $this->assertSame([$c => ['units' => 1000, 'dividends' => 0]], Shares::holders($c));
    }

    public function testSharesMoveBetweenHolders()
    {
        $a = $this->company('Issuer Co');
        $b = $this->company('Investor Co');
        $d = $this->company('Second Investor Co');
        Shares::transfer($a, $a, $b, 300);
        Shares::transfer($a, $b, $d, 300);
        $holders = Shares::holders($a);
        $this->assertSame(700, $holders[$a]['units']);
        $this->assertSame(300, $holders[$d]['units']);
        $this->assertArrayNotHasKey($b, $holders, 'a holder that sold its last share leaves the register');
        $this->assertSame(1000, array_sum(array_column($holders, 'units')));
    }

    public function testNobodyGivesSharesTheyDontHold()
    {
        $a = $this->company('Issuer Co');
        $b = $this->company('Investor Co');
        $this->expectException(InvalidArgumentException::class);
        Shares::transfer($a, $b, $a, 1);
    }

    public function testACompanyThatEndsLosesItsSharesToTheHouse()
    {
        $house = $this->house();
        $a = $this->company('Issuer Co');
        $b = $this->company('Doomed Investor Co');
        Shares::transfer($a, $a, $b, 250);
        Shares::transfer($house, $house, $b, 10);
        DB::table('companies')->where('company_id', $b)->update(['eliminated_at' => 5000, 'end_cause' => 'drones']);
        $this->assertSame(250, Shares::holders($a)[$house]['units']);
        $this->assertSame(1000, Shares::holders($house)[$house]['units'], 'the House gets back its own shares');
        $this->assertSame(0, DB::table('shares')->where('holder', $b)->count());
        // Its own shares, which it still held, went too
        $this->assertSame(1000, Shares::holders($b)[$house]['units']);
    }

    private function trade(int $company, int $seller, int $buyer, int $units, int $price): void
    {
        Shares::transfer($company, $seller, $buyer, $units);
        DB::table('share_trades')->insert(['share_company' => $company, 'seller' => $seller, 'buyer' => $buyer,
            'units' => $units, 'price' => $price, 'game_t' => 1000]);
    }

    public function testTheLastTradeSetsTheSharePrice()
    {
        $a = $this->company('Issuer Co');
        $b = $this->company('Investor Co');
        $this->assertArrayNotHasKey($a, Shares::lastPrices());
        $this->trade($a, $a, $b, 10, 7);
        $this->trade($a, $a, $b, 10, 9);
        $this->assertSame(9, Shares::lastPrices()[$a]);
    }

    public function testNetWealthCountsSharesHeldInOthersAtTheLastPrice()
    {
        $user = User::create(['name' => 'Investor', 'email' => uniqid() . '@example.com', 'password' => bcrypt('secret-password')]);
        $investor = Release::release($user, 'Investor Co', 'function launched() end');
        $issuer = Release::release($user, 'Issuer Co', 'function launched() end');
        $before = Leaderboard::wealth()[$investor->company_id]['wealth'];
        $this->trade($issuer->company_id, $issuer->company_id, $investor->company_id, 100, 12);
        $this->assertSame($before + 1200, Leaderboard::wealth()[$investor->company_id]['wealth']);
        // Its own shares count nothing; a company's shares never traded count 0
        $this->assertSame(0, Shares::holdingsValue()[$issuer->company_id] ?? 0);
    }

    public function testNetWealthValuesSharesAtTheLastThreeDaysAverage()
    {
        // #513: one odd trade doesn't set every holder's value
        $user = User::create(['name' => 'Investor', 'email' => uniqid() . '@example.com', 'password' => bcrypt('secret-password')]);
        $holder = Release::release($user, 'Holder Co', 'function launched() end');
        $issuer = Release::release($user, 'Listed Co', 'function launched() end');
        $other = Release::release($user, 'Dumper Co', 'function launched() end');
        $now = \App\Services\World::gameNow();
        $trade = function ($seller, $buyer, $units, $price, $ago) use ($issuer, $now) {
            Shares::transfer($issuer->company_id, $seller, $buyer, $units);
            DB::table('share_trades')->insert(['share_company' => $issuer->company_id, 'seller' => $seller, 'buyer' => $buyer,
                'units' => $units, 'price' => $price, 'game_t' => $now - $ago]);
        };
        // 200 traded at 10 two days ago; then a panic sale of 10 at 1
        $trade($issuer->company_id, $holder->company_id, 100, 10, 2 * 86400);
        $trade($issuer->company_id, $other->company_id, 100, 10, 2 * 86400);
        $trade($other->company_id, $holder->company_id, 10, 1, 3600);
        $this->assertSame(1, Shares::lastPrices()[$issuer->company_id]);
        // (200 × 10 + 10 × 1) ÷ 210 = 9.57, so 10
        $this->assertSame(10, Shares::valuePrices()[$issuer->company_id]);
        $this->assertSame(110 * 10, Shares::holdingsValue()[$holder->company_id]);
        $summary = Shares::summary($holder->company_id);
        $this->assertSame([1, 10, 1100], [$summary['holdings'][0]['price'], $summary['holdings'][0]['value_price'],
            $summary['holdings'][0]['value']]);
        // Trades older than 3 days don't count: then the last price is all there is
        DB::table('share_trades')->where('share_company', $issuer->company_id)->update(['game_t' => $now - 5 * 86400]);
        $this->assertSame(1, Shares::valuePrices()[$issuer->company_id]);
    }

    public function testTheCompanyPageAndApiShowTheShares()
    {
        $user = User::create(['name' => 'Investor', 'email' => uniqid() . '@example.com', 'password' => bcrypt('secret-password')]);
        $issuer = Release::release($user, 'Issuer Co', 'function launched() end');
        $investor = Release::release($user, 'Investor Co', 'function launched() end');
        $this->trade($issuer->company_id, $issuer->company_id, $investor->company_id, 40, 15);
        $this->get('/companies/' . $issuer->company_id)->assertOk()
            ->assertSee('Shares')->assertSee('Last trade price')->assertSee('Investor Co');
        $json = $this->getJson('/api/v1/companies/' . $issuer->company_id)->assertOk()->json('shares');
        $this->assertSame(15, $json['price']);
        $this->assertSame([960, 40], array_column($json['holders'], 'units'));
        $this->assertSame(600, $this->getJson('/api/v1/companies/' . $investor->company_id)->json('shares.holdings.0.value'));
    }

    /** #551: the stock exchange and a share's price history through the API */
    public function testTheApiShowsTheExchangeAndPriceHistory()
    {
        $user = User::create(['name' => 'Investor', 'email' => uniqid() . '@example.com', 'password' => bcrypt('secret-password')]);
        $issuer = Release::release($user, 'History Co', 'function launched() end');
        $investor = Release::release($user, 'Backer Co', 'function launched() end');
        $epoch = (float) DB::table('game_clock')->where('id', 1)->value('epoch');
        $day = intdiv(\App\Services\World::gameNow() - (int) $epoch, 86400);
        foreach ([[12, 30, 2], [9, 10, 2], [15, 5, 1]] as [$price, $units, $ago]) {
            DB::table('share_trades')->insert(['share_company' => $issuer->company_id, 'seller' => $issuer->company_id,
                'buyer' => $investor->company_id, 'units' => $units, 'price' => $price,
                'game_t' => (int) $epoch + ($day - $ago) * 86400 + 3600 * ($price % 5 + 1)]);
        }
        $history = $this->getJson('/api/v1/shares/' . $issuer->company_id . '/history')->assertOk()->json();
        $this->assertSame([['day' => $day - 2, 'low' => 9, 'high' => 12, 'last' => 9, 'units' => 40],
            ['day' => $day - 1, 'low' => 15, 'high' => 15, 'last' => 15, 'units' => 5]], $history);
        $this->getJson('/api/v1/shares/999999/history')->assertNotFound();
        $names = array_column($this->getJson('/api/v1/shares')->assertOk()->json('companies'), 'name');
        $this->assertContains('History Co', $names);
    }

    public function testTheStockExchangePageShowsEveryCompanysShares()
    {
        $user = User::create(['name' => 'Investor', 'email' => uniqid() . '@example.com', 'password' => bcrypt('secret-password')]);
        $issuer = Release::release($user, 'Issuer Co', 'function launched() end');
        $investor = Release::release($user, 'Investor Co', 'function launched() end');
        $this->dayOld($issuer->company_id);
        $this->trade($issuer->company_id, $issuer->company_id, $investor->company_id, 40, 15);
        // The issuer sold 3,000 credits' worth today (300 tax): a share earns 2.7 a day
        DB::table('trades')->insert(['resource' => 2011, 'units' => 10, 'credits' => 3000, 'tax' => 300,
            'seller' => $issuer->agent_id, 'buyer' => $investor->agent_id, 'game_t' => \App\Services\World::gameNow() - 60]);
        // It offers 260 more (keeps 700); the investor wants 100 in all
        DB::table('share_orders')->insert(['company' => $issuer->company_id, 'share_company' => $issuer->company_id,
            'side' => 1, 'price' => 81, 'limit_units' => 700, 'placed_t' => 0]);
        DB::table('share_orders')->insert(['company' => $investor->company_id, 'share_company' => $issuer->company_id,
            'side' => 2, 'price' => 20, 'limit_units' => 100, 'placed_t' => 0]);

        $x = Shares::exchange();
        $row = collect($x['companies'])->firstWhere('company', $issuer->company_id);
        $this->assertSame([15, 2700, 2.7, 6, 40], [$row['price'], $row['sales'], $row['a_day'], $row['payback'], $row['held_by_others']]);
        $sale = collect($x['for_sale'])->firstWhere('company', $issuer->company_id);
        $this->assertSame([260, 81, 30], [$sale['units'], $sale['price'], $sale['days']]);
        $bid = collect($x['buying'])->firstWhere('by', $investor->company_id);
        $this->assertSame([60, 20], [$bid['units'], $bid['price']]);

        $this->get('/market/shares')->assertOk()->assertSee('Stock exchange')
            ->assertSee('Issuer Co')->assertSee('Investor Co')->assertSee('6 days');
    }

    public function testNewSharesChangeWhatAShareEarnsAndCommittedCreditsCountAsWealth()
    {
        $user = User::create(['name' => 'Investor', 'email' => uniqid() . '@example.com', 'password' => bcrypt('secret-password')]);
        $issuer = Release::release($user, 'Issuer Co', 'function launched() end');
        $investor = Release::release($user, 'Investor Co', 'function launched() end');
        $this->dayOld($issuer->company_id);
        // A company starts with 1,000 shares (#485)
        $this->assertSame(1000, (int) DB::table('companies')->where('company_id', $issuer->company_id)->value('share_count'));

        // Issued to 2,000: a share earns half as much of the same sales
        DB::table('companies')->where('company_id', $issuer->company_id)->update(['share_count' => 2000]);
        $this->trade($issuer->company_id, $issuer->company_id, $investor->company_id, 40, 15);
        DB::table('trades')->insert(['resource' => 2011, 'units' => 10, 'credits' => 3000, 'tax' => 300,
            'seller' => $issuer->agent_id, 'buyer' => $investor->agent_id, 'game_t' => \App\Services\World::gameNow() - 60]);
        $row = collect(Shares::exchange()['companies'])->firstWhere('company', $issuer->company_id);
        $this->assertSame([2000, 1.35, 12], [$row['count'], $row['a_day'], $row['payback']]);
        $this->assertSame(2000, Shares::summary($issuer->company_id)['count']);

        // Credits committed to an offering are still the investor's wealth
        $before = Leaderboard::wealth()[$investor->company_id]['wealth'];
        DB::table('share_offerings')->insert(['company' => $issuer->company_id, 'units' => 500, 'price' => 6,
            'deadline_t' => \App\Services\World::gameNow() + 86400, 'placed_t' => 0]);
        DB::table('companies')->where('company_id', $investor->company_id)->decrement('credits', 600);
        DB::table('share_commitments')->insert(['company' => $issuer->company_id, 'investor' => $investor->company_id,
            'units' => 100, 'credits' => 600, 'placed_t' => 0]);
        $this->assertSame($before, Leaderboard::wealth()[$investor->company_id]['wealth']);
    }

    public function testTheWebsiteShowsOpenOfferings()
    {
        $user = User::create(['name' => 'Investor', 'email' => uniqid() . '@example.com', 'password' => bcrypt('secret-password')]);
        $issuer = Release::release($user, 'Raising Co', 'function launched() end');
        $investor = Release::release($user, 'Backer Co', 'function launched() end');
        $this->dayOld($issuer->company_id);
        DB::table('share_offerings')->insert(['company' => $issuer->company_id, 'units' => 500, 'price' => 10,
            'deadline_t' => \App\Services\World::gameNow() + 5 * 3600 - 60, 'placed_t' => 0]);
        DB::table('share_commitments')->insert(['company' => $issuer->company_id, 'investor' => $investor->company_id,
            'units' => 120, 'credits' => 1200, 'placed_t' => 0]);
        DB::table('trades')->insert(['resource' => 2011, 'units' => 10, 'credits' => 3300, 'tax' => 300,
            'seller' => $issuer->agent_id, 'buyer' => $investor->agent_id, 'game_t' => \App\Services\World::gameNow() - 60]);

        $o = collect(Shares::exchange()['offerings'])->firstWhere('company', $issuer->company_id);
        $this->assertSame([5000, 120, 380, 5, 2.0], [$o['raise'], $o['committed'], $o['left'], $o['hours'], $o['a_day_after']]);
        $this->assertSame('Backer Co', $o['investors'][0]['name']);
        $this->get('/market/shares')->assertOk()->assertSee('Offerings of new shares')->assertSee('Raising Co')
            ->assertSee('120 of 500');
        $this->get('/companies/' . $issuer->company_id)->assertOk()->assertSee('Offering new shares')->assertSee('Backer Co');
        $this->assertSame(380, $this->getJson('/api/v1/companies/' . $issuer->company_id)->json('shares.offering.left'));
        // With no minimum (posted before #516) it is all of them; with one, the page says so
        $this->assertSame(500, $o['min']);
        DB::table('share_offerings')->where('company', $issuer->company_id)->update(['min_units' => 200]);
        $this->assertSame(200, $this->getJson('/api/v1/companies/' . $issuer->company_id)->json('shares.offering.min'));
        $this->get('/companies/' . $issuer->company_id)->assertSee('at least 200 of them');
        // A lapsed offering isn't open
        DB::table('share_offerings')->where('company', $issuer->company_id)->update(['deadline_t' => 0]);
        $this->assertNull(Shares::summary($issuer->company_id)['offering']);
    }

    public function testSalesAverageOverAWeekAndEndedCompaniesSharesAreWorthNothing()
    {
        $user = User::create(['name' => 'Investor', 'email' => uniqid() . '@example.com', 'password' => bcrypt('secret-password')]);
        $issuer = Release::release($user, 'Lumpy Co', 'function launched() end');
        $investor = Release::release($user, 'Holder Co', 'function launched() end');
        $now = \App\Services\World::gameNow();
        DB::table('companies')->where('company_id', $issuer->company_id)->update(['founded_t' => $now - 30 * 86400]);
        // One lumpy sale four days ago, none since: 7,000 a week is 1,000 a day (#492)
        DB::table('trades')->insert(['resource' => 2011, 'units' => 10, 'credits' => 7700, 'tax' => 700,
            'seller' => $issuer->agent_id, 'buyer' => $investor->agent_id, 'game_t' => $now - 4 * 86400]);
        $this->assertSame(1000, Shares::daySales([$issuer->company_id])[$issuer->company_id]);
        $this->trade($issuer->company_id, $issuer->company_id, $investor->company_id, 100, 20);
        DB::table('share_orders')->insert(['company' => $investor->company_id, 'share_company' => $issuer->company_id,
            'side' => 1, 'price' => 25, 'limit_units' => 0, 'placed_t' => 0]);
        $this->assertSame(2000, Shares::holdingsValue()[$investor->company_id]);
        $this->assertSame(1, collect(Shares::exchange()['for_sale'])->where('company', $issuer->company_id)->count());

        // The issuer ends: its shares are worth nothing and off the exchange (#493)
        DB::table('companies')->where('company_id', $issuer->company_id)->update(['eliminated_at' => $now]);
        $this->assertSame(0, Shares::holdingsValue()[$investor->company_id] ?? 0);
        $x = Shares::exchange();
        $this->assertSame(0, collect($x['for_sale'])->where('company', $issuer->company_id)->count());
        $this->assertSame(0, collect($x['companies'])->where('company', $issuer->company_id)->count());
        $holding = Shares::summary($investor->company_id)['holdings'][0];
        $this->assertSame([true, 0], [$holding['ended'], $holding['value']]);
        $this->get('/companies/' . $investor->company_id)->assertOk()->assertSee('ended: worth nothing');
    }
}
