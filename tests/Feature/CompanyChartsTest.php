<?php

namespace Tests\Feature;

use App\Services\Release;
use App\Services\World;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A company page's earnings and share price charts (#600) and how much of
 * the company others hold (#599)
 */
class CompanyChartsTest extends TestCase
{
    use DatabaseTransactions;

    private function release(string $company)
    {
        $user = User::create(['name' => 'Charts', 'email' => uniqid() . '@example.com', 'password' => bcrypt('secret-password')]);
        return Release::release($user, $company, 'function launched() end');
    }

    public function testEarningsByGameDay()
    {
        $seller = $this->release('Earners');
        $buyer = $this->release('Spenders');
        $epoch = (int) DB::table('game_clock')->where('id', 1)->value('epoch');
        $today = intdiv(World::gameNow() - $epoch, 86400);
        // Founded 5 game days ago: the chart starts no earlier than that
        DB::table('companies')->where('company_id', $seller->company_id)->update(['founded_t' => World::gameNow() - 5 * 86400]);
        $this->assertCount(6, $this->getJson('/api/v1/companies/' . $seller->company_id . '/earnings?days=30')->json(),
            'from its founding day, not 30 days back');
        // Yesterday it sold 10 for 100 (5 tax) and bought 4 for 30; today it sold 2 for 20 (1 tax)
        $at = fn ($day) => $epoch + $day * 86400 + 3600;
        DB::table('trades')->insert([
            ['resource' => 2011, 'units' => 10, 'credits' => 100, 'tax' => 5, 'seller' => $seller->agent_id,
                'buyer' => $buyer->agent_id, 'location' => 3, 'game_t' => $at($today - 1)],
            ['resource' => 2011, 'units' => 4, 'credits' => 30, 'tax' => 0, 'seller' => $buyer->agent_id,
                'buyer' => $seller->agent_id, 'location' => 3, 'game_t' => $at($today - 1)],
            ['resource' => 2011, 'units' => 2, 'credits' => 20, 'tax' => 1, 'seller' => $seller->agent_id,
                'buyer' => $buyer->agent_id, 'location' => 3, 'game_t' => min(World::gameNow(), $at($today))],
        ]);
        $rows = $this->getJson('/api/v1/companies/' . $seller->company_id . '/earnings?days=3')->assertOk()->json();
        $this->assertCount(3, $rows, 'every day listed, trades or not');
        $this->assertSame(['day' => $today - 2, 'sales' => 0, 'tax' => 0, 'purchases' => 0, 'net' => 0, 'trades' => 0, 'dividends' => 0], $rows[0]);
        $this->assertSame(['day' => $today - 1, 'sales' => 95, 'tax' => 5, 'purchases' => 30, 'net' => 65, 'trades' => 2, 'dividends' => 0], $rows[1]);
        $this->assertSame([19, 1, 0, 19], [$rows[2]['sales'], $rows[2]['tax'], $rows[2]['purchases'], $rows[2]['net']]);
        $this->getJson('/api/v1/companies/999999/earnings')->assertNotFound();
        // #603: what it paid other holders from yesterday's profit
        DB::table('company_profits')->insert(['company' => $seller->company_id, 'day' => $today - 1, 'sales' => 95,
            'purchases' => 30, 'profit' => 65, 'paid' => 19, 'holders' => 1]);
        $this->assertSame(19, $this->getJson('/api/v1/companies/' . $seller->company_id . '/earnings?days=3')->json('1.dividends'));

        // The page charts it
        $this->get('/companies/' . $seller->company_id)->assertOk()->assertSee('id="earnings"', false)
            ->assertSee('id="ce-chart"', false)->assertSee('/api/v1/companies/' . $seller->company_id . '/earnings', false);
    }

    public function testThePageSaysHowMuchOthersHoldAndChartsTheSharePrice()
    {
        $issuer = $this->release('Issuers');
        $investor = $this->release('Backers');
        $page = fn () => $this->get('/companies/' . $issuer->company_id)->assertOk();
        $page()->assertSee('Held by other companies: <b>0%</b>', false)->assertSee('id="share-price-none"', false)
            ->assertDontSee('id="mh-price"', false);

        // 350 of 1,000 sold: past the 30% warning, short of the 50% cap
        \App\Services\Shares::transfer((int) $issuer->company_id, (int) $issuer->company_id, (int) $investor->company_id, 350);
        DB::table('share_trades')->insert(['share_company' => $issuer->company_id, 'seller' => $issuer->company_id,
            'buyer' => $investor->company_id, 'units' => 350, 'price' => 12, 'game_t' => World::gameNow()]);
        $page()->assertSee('Held by other companies: <b>35%</b>', false)->assertSee('think twice before selling more')
            ->assertSee('class="health-banner"', false)->assertSee('id="mh-price"', false)
            ->assertSee('api\\/v1\\/shares\\/' . $issuer->company_id . '\\/history', false)->assertDontSee('id="mh-resource"', false);
        $this->getJson('/api/v1/companies/' . $issuer->company_id)->assertOk()
            ->assertJsonPath('shares.outside', 350)->assertJsonPath('shares.max_outside', 0.5);

        // At the cap
        \App\Services\Shares::transfer((int) $issuer->company_id, (int) $issuer->company_id, (int) $investor->company_id, 150);
        $page()->assertSee('Held by other companies: <b>50%</b>', false)->assertSee("it can't offer new shares or sell its own", false);
    }

    /** #607: a share is judged by 30 days of profit and the losses still to make up */
    public function testCarriedLossAndThirtyDayProfit()
    {
        $issuer = $this->release('Lumpy');
        $investor = $this->release('Patient');
        $c = (int) $issuer->company_id;
        $today = \App\Services\MarketHistory::today();
        // 20 days ago it earned 6,000 in a day; the last 7 days it made 100 a
        // day; and a hull bought yesterday left 1,500 of losses to make up
        DB::table('company_profits')->insert(['company' => $c, 'day' => $today - 20, 'sales' => 6000, 'purchases' => 0, 'profit' => 6000]);
        foreach (range(1, 7) as $d) {
            DB::table('company_profits')->insert(['company' => $c, 'day' => $today - $d, 'sales' => 100, 'purchases' => 0, 'profit' => 100]);
        }
        DB::table('companies')->where('company_id', $c)->update(['dividend_carry' => -1500]);

        $long = \App\Services\Shares::longProfits([$c])[$c];
        $this->assertSame(['profit30' => 837, 'carried' => 1500], $long, '(6,000 + 700) / 8 settled days');
        $this->assertSame(2, \App\Services\Shares::carryDays($long));
        // At 10 a share of 1,000: 1,500 to make up, then 10,000 to earn back
        $this->assertSame((int) ceil(11500 / 837), \App\Services\Shares::paybackDays(10, 1000, $long));
        $this->assertNull(\App\Services\Shares::paybackDays(10, 1000, ['profit30' => 0, 'carried' => 0]));

        \App\Services\Shares::transfer($c, $c, (int) $investor->company_id, 100);
        DB::table('share_trades')->insert(['share_company' => $c, 'seller' => $c, 'buyer' => $investor->company_id,
            'units' => 100, 'price' => 10, 'game_t' => World::gameNow()]);
        $this->get('/companies/' . $c)->assertOk()->assertSee('id="share-earnings"', false)
            ->assertSee('No dividends until it makes 1,500 more profit', false)->assertSee('about 2 days at that rate');
        $row = collect(\App\Services\Shares::exchange()['companies'])->firstWhere('company', $c);
        $this->assertSame([837, 1500, 2, 14], [$row['profit30'], $row['carried_loss'], $row['carry_days'], $row['payback']]);
        $this->get('/market/shares')->assertOk()->assertSee('Losses to make up');

        // Made up: dividends resume
        DB::table('companies')->where('company_id', $c)->update(['dividend_carry' => 0]);
        $this->get('/companies/' . $c)->assertSee('It has no losses to make up');
    }
}
