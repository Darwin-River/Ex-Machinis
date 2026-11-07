<?php

namespace Tests\Feature;

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
}
