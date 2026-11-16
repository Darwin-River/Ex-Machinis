<?php

namespace Tests\Feature;

use App\Services\Leaderboard;
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
 * Performance charts on the company, stock exchange, drone and market
 * history pages, and a matured company's cash-out by game day (#699)
 */
class PerformanceChartsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['forge.new_rules' => true, 'forge.seedling_hull_value' => 1000, 'forge.seedling_fee' => 200,
            'forge.starting_credits' => 3000, 'forge.seedling_mature_margin' => 500, 'forge.seedling_max' => 5,
            'forge.player_share_credits' => 5000, 'forge.hull_life_days' => 365, 'forge.share_range_low' => 0.8,
            'forge.share_range_high' => 1.25]);
        DB::table('house_rules')->updateOrInsert(['id' => 1], ['tax_period' => 0, 'tax_grace' => 0, 'tax_flat' => 0,
            'tax_permille' => 0, 'plunder_share' => 50, 'compute_tax_per_million' => 15]);
        Mail::fake();
        $now = time();
        DB::table('game_clock')->delete();
        DB::table('game_clock')->insert(['id' => 1, 'real_anchor' => $now, 'game_anchor' => $now, 'scale' => 1, 'epoch' => $now - 50 * 86400 - 3600]);
    }

    private function matured(): array
    {
        $email = 'perf' . uniqid() . '@example.com';
        $owner = Players::create('Perf ' . substr(uniqid(), -5), 'long-enough', $email);
        Players::verify($owner->user_id, sha1($email));
        $owner = User::where('user_id', $owner->user_id)->first();
        $id = Seedlings::start($owner, 'Perf Co ' . uniqid(), 'function launched() wait(60) end')['company'];
        DB::table('companies')->where('company_id', $id)->update(['credits' => 9700]);
        $this->assertTrue(Seedlings::mature($owner, $id)['ok']);
        return [$owner, $id];
    }

    public function testTheDailySnapshotStoresCashOutAndTheHistoryGivesIt()
    {
        [, $id] = $this->matured();
        $epoch = (int) DB::table('game_clock')->where('id', 1)->value('epoch');
        $today = intdiv(World::gameNow() - $epoch, 86400);
        DB::table('company_days')->where('day', '>=', $today)->delete();
        $this->assertTrue(Leaderboard::dailyIfDue());
        $stored = (int) DB::table('company_days')->where('company_id', $id)->where('day', $today)->value('cash_out');
        $this->assertSame(PlayerShares::cashOut($id)['total'], $stored);

        // The history: cash-out, a share's, and the bank's selling price (cash-out + 30 days of dividends a share)
        DB::table('player_dividends')->insert(['company_id' => $id, 'user_id' => null, 'day' => $today - 1, 'units' => 100, 'amount' => 700]);
        $row = collect($this->getJson("/api/v1/companies/$id/history")->assertOk()->json())->firstWhere('day', $today);
        $this->assertSame($stored, $row['cash_out']);
        $this->assertSame(intdiv($stored, 100), $row['cash_out_share']);
        $this->assertSame(intdiv($stored, 100) + 7, $row['bank_sells_at']);
        // A day before it was recorded: null
        DB::table('company_days')->insert(['company_id' => $id, 'day' => $today - 3, 'credits' => 1, 'net_wealth' => 1, 'drones' => 1]);
        $old = collect($this->getJson("/api/v1/companies/$id/history")->json())->firstWhere('day', $today - 3);
        $this->assertNull($old['cash_out']);
        $this->assertNull($old['bank_sells_at']);
    }

    public function testTheCompanyPageChartsItsPerformance()
    {
        [, $id] = $this->matured();
        $page = $this->get("/companies/$id")->assertOk();
        $page->assertSee('id="stat-cash-out"', false)->assertSee('Cash-out a share')->assertSee('Dividends a share, 30 days')
            ->assertSee('id="company-badge"', false)->assertSee('Matured')
            ->assertSee('Is it growing?')->assertSee('Is it earning?')->assertSee('How many drones does it run?')
            ->assertSee('id="ce-chart"', false)->assertSee('id="perf-drones"', false)
            ->assertSee("GET /api/v1/companies/$id/history", false);
        // A seedling has no cash-out tiles
        [$owner] = $this->matured();
        $seed = Seedlings::start($owner, 'Perf Seed ' . uniqid(), 'function launched() wait(60) end')['company'];
        $this->get("/companies/$seed")->assertOk()->assertDontSee('id="stat-cash-out"', false);
    }

    public function testTheStockExchangeHasARowForEachMaturedCompany()
    {
        [$owner, $id] = $this->matured();
        $epoch = (int) DB::table('game_clock')->where('id', 1)->value('epoch');
        $today = intdiv(World::gameNow() - $epoch, 86400);
        DB::table('company_days')->updateOrInsert(['company_id' => $id, 'day' => $today - 1],
            ['credits' => 1, 'net_wealth' => 1, 'drones' => 1, 'cash_out' => 5000]);
        // A trade with the bank sets no players' price (#716)
        DB::table('player_share_trades')->insert(['company_id' => $id, 'seller_id' => $owner->user_id, 'buyer_id' => null,
            'units' => 1, 'price' => (int) round(PlayerShares::prices($id)['buys_at'] * 1.1), 'game_t' => World::gameNow()]);
        $this->get('/market/shares')->assertOk()->assertDontSee('% premium')->assertSee('Latest share trades');
        $buyer = User::create(['name' => 'Perf Buyer', 'login' => 'PerfBuyer' . uniqid(), 'email' => uniqid() . '@example.com',
            'password' => bcrypt('secret-password')]);
        DB::table('player_share_trades')->insert(['company_id' => $id, 'seller_id' => $owner->user_id, 'buyer_id' => $buyer->user_id,
            'units' => 1, 'price' => (int) round(PlayerShares::prices($id)['buys_at'] * 1.1), 'game_t' => World::gameNow()]);
        // The page is kept a minute (#726): forget it to see the new trade
        \Illuminate\Support\Facades\Cache::forget('market:shares:1');
        $this->get('/market/shares')->assertOk()->assertSee('id="player-share-market"', false)
            ->assertSee('Cash-out a share')->assertSee('Dividends a share, 30 days')->assertSee('Yield, 30 days')
            ->assertSee('id="health-meaning"', false)->assertSee('today,')
            ->assertSee('% premium')->assertSee('data-spark=', false)->assertSee('<th>Health</th>', false);
    }

    public function testTheDronePageChartsItsActionsAndFuel()
    {
        [, $id] = $this->matured();
        $drone = (int) DB::table('agents')->where('company_id', $id)->value('agent_id');
        $this->get("/spacecraft/$drone")->assertOk()->assertSee('id="drone-activity"', false)
            ->assertSee('What has it been doing?')->assertSee("/api/v1/drones/$drone/timeline", false);
    }

    public function testMarketHistoryShowsThirtyDaysFirst()
    {
        DB::table('trades')->insert(['resource' => 1, 'units' => 1, 'credits' => 5, 'tax' => 0, 'seller' => 1, 'buyer' => 1,
            'location' => 3, 'game_t' => World::gameNow() - 10]);
        $this->get('/market/history')->assertOk()->assertSee('<option value="30" selected', false)
            ->assertSee('a day with no trades');
    }
}
