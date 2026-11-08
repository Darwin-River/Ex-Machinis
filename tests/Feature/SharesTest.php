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

    public function testTheStockExchangePageShowsEveryCompanysShares()
    {
        $user = User::create(['name' => 'Investor', 'email' => uniqid() . '@example.com', 'password' => bcrypt('secret-password')]);
        $issuer = Release::release($user, 'Issuer Co', 'function launched() end');
        $investor = Release::release($user, 'Investor Co', 'function launched() end');
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
}
