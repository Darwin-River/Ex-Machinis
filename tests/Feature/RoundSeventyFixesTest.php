<?php

namespace Tests\Feature;

use App\Services\CompanyHealth;
use App\Services\Dashboard;
use App\Services\Leaderboard;
use App\Services\PlayerBoards;
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
 * Round 70's site fixes: figures that disagreed (#716), old-rules words
 * (#717), and health and status messages (#715)
 */
class RoundSeventyFixesTest extends TestCase
{
    use DatabaseTransactions;

    private int $epoch;
    private int $today;

    protected function setUp(): void
    {
        parent::setUp();
        config(['forge.new_rules' => true, 'forge.seedling_hull_value' => 1000, 'forge.seedling_fee' => 200,
            'forge.starting_credits' => 3000, 'forge.seedling_mature_margin' => 500, 'forge.seedling_max' => 5,
            'forge.player_share_credits' => 5000, 'forge.hull_life_days' => 365, 'forge.share_range_low' => 0.8,
            'forge.share_range_high' => 1.25, 'forge.bank_pay_days' => 30]);
        Mail::fake();
        $now = time();
        DB::table('game_clock')->delete();
        DB::table('game_clock')->insert(['id' => 1, 'real_anchor' => $now, 'game_anchor' => $now, 'scale' => 1,
            'target_scale' => 1, 'epoch' => $now - 50 * 86400 - 3600, 'load_checked_at' => $now]);
        $this->epoch = $now - 50 * 86400 - 3600;
        $this->today = 50;
    }

    private function player(string $name, ?int $group = null): User
    {
        $email = strtolower(str_replace(' ', '', $name)) . uniqid() . '@example.com';
        $u = Players::create($name . substr(uniqid(), -4), 'long-enough', $email);
        Players::verify($u->user_id, sha1($email));
        if ($group !== null) {
            DB::table('users')->where('user_id', $u->user_id)->update(['class_group_id' => $group]);
        }
        return User::where('user_id', $u->user_id)->first();
    }

    private function matured(?User $owner = null): array
    {
        $owner ??= $this->player('Owner');
        $id = Seedlings::start($owner, 'R70 Co ' . uniqid(), 'function launched() wait(60) end')['company'];
        DB::table('companies')->where('company_id', $id)->update(['credits' => 9700]);
        $this->assertTrue(Seedlings::mature($owner, $id)['ok']);
        return [$owner, $id];
    }

    private function drone(int $company): int
    {
        return (int) DB::table('agents')->where('company_id', $company)->value('agent_id');
    }

    /** #716: the last price between players, its age, and a premium only for a recent one; best yield first */
    public function testTheStockExchangeCountsPlayersTradesOnly()
    {
        [$owner, $id] = $this->matured();
        [, $other] = $this->matured();
        $buyer = $this->player('Buyer');
        $buys = PlayerShares::prices($id)['buys_at'];
        // An old trade between players, then a newer one with the bank at a silly price
        DB::table('player_share_trades')->insert([
            ['company_id' => $id, 'seller_id' => $owner->user_id, 'buyer_id' => $buyer->user_id, 'units' => 1,
             'price' => $buys * 2, 'game_t' => World::gameNow() - 20 * 86400],
            ['company_id' => $id, 'seller_id' => $owner->user_id, 'buyer_id' => null, 'units' => 1,
             'price' => 20, 'game_t' => World::gameNow() - 3600],
        ]);
        // The other pays dividends: it has the better yield
        DB::table('player_dividends')->insert(['company_id' => $other, 'user_id' => null, 'day' => $this->today - 2, 'units' => 100, 'amount' => 900]);
        $this->get('/market/shares')->assertOk()
            ->assertSee(number_format($buys * 2))->assertSee('20 game days ago')->assertDontSee('% premium')->assertDontSee('% discount')
            ->assertSee('Yield, 30 days')->assertSee('id="health-meaning"', false)
            ->assertSeeInOrder([DB::table('companies')->where('company_id', $other)->value('name'),
                DB::table('companies')->where('company_id', $id)->value('name')])
            ->assertSee('id="before-new-rules"', false);
    }

    /** #716: the dashboard counts the players /players lists, and its profit chart and table agree */
    public function testTheDashboardAgreesWithItself()
    {
        [$owner, $id] = $this->matured();
        $group = DB::table('class_groups')->insertGetId(['name' => 'R70 Class', 'teacher_id' => $owner->user_id, 'created_at' => now()]);
        $this->player('Pupil', $group);
        $buyer = Seedlings::start($owner, 'R70 Buyer', 'function launched() wait(60) end')['company'];
        DB::table('companies')->where('company_id', $id)->update(['founded_t' => $this->epoch + ($this->today - 40) * 86400,
            'matured_t' => $this->epoch + ($this->today - 10) * 86400]);
        DB::table('trades')->insert([
            ['resource' => 2011, 'units' => 10, 'credits' => 1000, 'tax' => 10, 'seller' => $this->drone($id), 'buyer' => $this->drone($buyer),
             'location' => 3, 'game_t' => $this->epoch + ($this->today - 3) * 86400 + 60],
            ['resource' => 2011, 'units' => 5, 'credits' => 300, 'tax' => 0, 'seller' => $this->drone($buyer), 'buyer' => $this->drone($id),
             'location' => 3, 'game_t' => $this->epoch + ($this->today - 2) * 86400 + 60],
            // Before it matured: not a matured company's profit
            ['resource' => 2011, 'units' => 5, 'credits' => 777, 'tax' => 0, 'seller' => $this->drone($id), 'buyer' => $this->drone($buyer),
             'location' => 3, 'game_t' => $this->epoch + ($this->today - 20) * 86400 + 60],
        ]);
        // An ended matured company's trades count in neither
        [, $gone] = $this->matured();
        DB::table('trades')->insert(['resource' => 2011, 'units' => 1, 'credits' => 5000, 'tax' => 0, 'seller' => $this->drone($gone),
            'buyer' => $this->drone($buyer), 'location' => 3, 'game_t' => $this->epoch + ($this->today - 1) * 86400]);
        DB::table('companies')->where('company_id', $gone)->update(['eliminated_at' => World::gameNow() - 60, 'end_cause' => 'dissolved']);
        $public = (int) DB::table('users')->whereNotNull('login')->whereNull('class_group_id')->count();
        $d = $this->getJson('/api/v1/dashboard')->assertOk()->json();
        $this->assertSame($public, $d['tiles']['players']['count']);
        $this->assertNotContains('Pupil', array_map(fn ($p) => substr($p['name'], 0, 5), $d['players']));
        $chart = array_sum(array_column(array_filter($d['matured_profit'], fn ($r) => $r['day'] > $d['today'] - 30), 'net'));
        $table = array_sum(array_column($d['companies'], 'profit30'));
        $this->assertSame($table, $chart, 'the chart\'s last 30 days add up to the table');
        $this->assertSame(990 - 300, $chart);
        // The page draws today as a day not over, and says tap
        $this->get('/dashboard')->assertOk()->assertSee('partial: D.today', false)->assertSee('or tap it');
    }

    /** #716: a buy order of a drone flying away makes no crossed book */
    public function testTheSpreadsLeaveOutOrdersOfDronesThatLeft()
    {
        [, $id] = $this->matured();
        $d = $this->drone($id);
        $now = World::gameNow();
        DB::table('agents')->where('agent_id', $d)->update(['object_id' => 4]);
        $a = DB::table('actions')->insertGetId(['drone' => $d, 'protocol' => 5, 'multiplier' => 1, 'aborted' => 0, 'outcome' => 1]);
        DB::table('events')->insert([
            ['event_type' => 5, 'action' => $a, 'logged' => 1, 'outcome' => 1, 'drone' => $d, 'resource' => 3011, 'installed' => 0,
             'locked' => 0, 'new_quantity' => 50, 'new_credits' => 999, 'game_t' => $now - 600, 'place' => 4, 'new_location' => null],
            ['event_type' => 3, 'action' => $a, 'logged' => 0, 'outcome' => 0, 'drone' => $d, 'resource' => 0, 'installed' => 0,
             'locked' => 0, 'new_quantity' => 0, 'new_credits' => 0, 'game_t' => $now + 86400, 'place' => 4, 'new_location' => 11],
        ]);
        $this->assertContains($d, array_map(fn ($o) => (int) $o['drone'], World::orders(5, 2000)), 'the Market still lists it');
        $this->assertNotContains($d, array_map(fn ($o) => (int) $o['drone'], World::orders(5, 2000, true)), 'flying: not here');
    }

    /** #716: one count of players' drones for the map, the places API and the dashboard */
    public function testDroneCountsAgree()
    {
        [$owner, $id] = $this->matured();
        $closed = Seedlings::start($owner, 'R70 Closed', 'function launched() wait(60) end')['company'];
        DB::table('agents')->where('company_id', $closed)->update(['wrecked_at' => World::gameNow(), 'wreck_cause' => 'closed']);
        DB::table('agents')->insert(['user_id' => $owner->user_id, 'company_id' => $id, 'name' => 'Station R70', 'object_id' => 3,
            'hull_type' => 1, 'station_id' => 1]);
        $places = $this->getJson('/api/v1/places')->assertOk()->json();
        $map = World::map();
        $d = $this->getJson('/api/v1/dashboard')->json();
        $working = array_sum(array_column($places, 'working'));
        $wrecks = array_sum(array_column($places, 'wrecks'));
        $this->assertSame($working, $d['tiles']['drones']['working']);
        $this->assertSame($wrecks, $d['tiles']['drones']['wrecks']);
        $this->assertSame($working + $wrecks, array_sum(array_column($map, 'working')) + array_sum(array_column($map, 'wrecks')));
        $this->assertSame(World::playerDrones()->whereNull('a.wrecked_at')->count(), $working);
    }

    /** #716: a company page quotes one cash-out, its charts leave the bank's trades out, and the House isn't a company */
    public function testTheCompanyPagesAgree()
    {
        [$owner, $id] = $this->matured();
        $buyer = $this->player('Dotter');
        DB::table('player_share_trades')->insert([
            ['company_id' => $id, 'seller_id' => $owner->user_id, 'buyer_id' => null, 'units' => 1, 'price' => 11, 'game_t' => World::gameNow() - 60],
            ['company_id' => $id, 'seller_id' => $owner->user_id, 'buyer_id' => $buyer->user_id, 'units' => 1, 'price' => 97, 'game_t' => World::gameNow() - 60],
        ]);
        DB::table('company_days')->insert(['company_id' => $id, 'day' => $this->today - 5, 'credits' => 1, 'net_wealth' => 1,
            'drones' => 1, 'cash_out' => 1000]);
        $cash = PlayerShares::cashOut($id);
        $page = $this->get('/companies/' . $id)->assertOk()
            ->assertSee('recorded cash-out since game day ' . ($this->today - 5))
            ->assertDontSee('Cash-out is recorded from the day a company matures')
            ->assertSee('Trades between players');
        $html = $page->getContent();
        preg_match('/Credits<\/div>\s*<div class="stat-value">([\d,]+)/', $html, $m);
        $this->assertSame(number_format($cash['credits']), $m[1] ?? null);
        preg_match('/"trades":(\[[^\]]*\])/', $html, $t);
        $trades = json_decode($t[1], true);
        $this->assertSame([97], array_column($trades, 'price'), 'the bank\'s trade is no dot');

        // The House isn't listed; an ended company's age stops when it ended
        $house = DB::table('companies')->insertGetId(['user_id' => $owner->user_id, 'name' => 'The House R70', 'credits' => 0,
            'founded_t' => World::gameNow() - 86400, 'house' => 1]);
        DB::table('companies')->where('company_id', $id)->update(['founded_t' => World::gameNow() - 30 * 86400,
            'eliminated_at' => World::gameNow() - 20 * 86400, 'end_cause' => 'dissolved']);
        $rows = collect($this->getJson('/companies/search?per_page=500')->json('data'));
        $this->assertNull($rows->firstWhere('user_id', $house));
        $this->assertEquals(10.0, (float) $rows->firstWhere('user_id', $id)['age']);
        $this->getJson('/api/v1/companies/' . $id)->assertJsonPath('age_days', 10);
    }

    /** #715: a self-cross quotes the standing order's price, and a fraction of a share says why */
    public function testShareOrderMessages()
    {
        [$owner, $id] = $this->matured();
        $p = PlayerShares::prices($id);
        $this->assertTrue(PlayerShares::order($owner, $id, 'sell', 2, $p['low'])['ok']);
        $r = PlayerShares::order($owner, $id, 'buy', 1, $p['high']);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('at ' . $p['low'] . ': a buy at ' . $p['low'] . ' or more would cross it', $r['error']);
        $this->assertStringNotContainsString((string) $p['high'], $r['error']);
        $h = ['Authorization' => 'Bearer ' . Players::issueToken($owner)];
        $this->postJson("/api/v1/player-shares/$id/bank/buy", ['units' => 1.5], $h)->assertStatus(422)
            ->assertJsonPath('error', "Shares are whole: 1.5 isn't a whole number.");
        $this->postJson("/api/v1/player-shares/$id/orders", ['side' => 'buy', 'units' => 1, 'price' => 70.5], $h)->assertStatus(422)
            ->assertJsonPath('error', "Prices are whole credits: 70.5 isn't a whole number.");
        $this->postJson("/api/v1/player-shares/$id/bank/sell", ['units' => '3'], $h)->assertOk();
    }

    /** #715: the account page lists the player's share trades and flags an order that can't fill */
    public function testTheAccountPageSaysWhenOrdersFill()
    {
        [$owner, $id] = $this->matured();
        $buyer = $this->player('Filler');
        DB::table('player_share_trades')->insert(['company_id' => $id, 'seller_id' => $owner->user_id, 'buyer_id' => $buyer->user_id,
            'units' => 3, 'price' => 77, 'game_t' => World::gameNow() - 3600]);
        DB::table('player_share_orders')->insert(['user_id' => $buyer->user_id, 'company_id' => $id, 'side' => 'buy',
            'units' => 2, 'price' => 1, 'created_at' => now()]);
        $mine = PlayerShares::of($buyer);
        $this->assertSame('bought', $mine['fills'][0]['side']);
        $this->assertTrue($mine['orders'][0]['out_of_range']);
        $this->actingAs($buyer)->get('/account')->assertOk()->assertSee('id="share-fills"', false)
            ->assertSee('Bought 3 of')->assertSee("Can't fill now:", false);
        // The digest says so too, and looks back at most DIGEST_MAX_DAYS
        DB::table('users')->where('user_id', $buyer->user_id)->update(['last_seen_t' => World::gameNow() - 40 * 86400]);
        $digest = PlayerBoards::digest($buyer, false);
        $this->assertTrue($digest['capped']);
        $this->assertSame(77, $digest['share_fills'][0]['price']);
    }

    /** #715: health counts failing actions, and half its drones idle isn't stalled while it sells */
    public function testHealthSeesFailingLoops()
    {
        [$owner, $id] = $this->matured();
        $d = $this->drone($id);
        $now = World::gameNow();
        foreach ([1, 5, 9] as $h) {
            $a = DB::table('actions')->insertGetId(['drone' => $d, 'protocol' => 3, 'multiplier' => 1, 'aborted' => 0, 'outcome' => -2]);
            DB::table('events')->insert(['event_type' => 1, 'action' => $a, 'logged' => 1, 'outcome' => -2, 'drone' => $d,
                'resource' => 2011, 'installed' => 0, 'locked' => 0, 'new_quantity' => 0, 'game_t' => $now - $h * 3600]);
        }
        $health = CompanyHealth::of($id);
        $this->assertSame(3, $health['failing'][0]['times']);
        $this->assertStringContainsString('failed 3 times over the last game day', $health['failing_reason']);
        $this->get('/companies/' . $id)->assertOk()->assertSee('id="failing"', false)->assertSee('failed 3 times');

        // Two drones, one idle: half idle, but it sold within the game day
        $more = Seedlings::start($owner, 'R70 More', 'function launched() wait(60) end')['company'];
        DB::table('agents')->where('company_id', $more)->update(['company_id' => $id]);
        DB::table('events')->insert(['event_type' => 2, 'action' => 0, 'logged' => 1, 'outcome' => 1, 'drone' => $d,
            'resource' => 2011, 'installed' => 0, 'locked' => 0, 'new_quantity' => 5, 'game_t' => $now - 600]);
        DB::table('trades')->insert(['resource' => 2011, 'units' => 1, 'credits' => 10, 'seller' => $d, 'buyer' => $d,
            'location' => 3, 'game_t' => $now - 3600]);
        $this->assertFalse(CompanyHealth::of($id)['stalled']);
    }

    /** #717: the leaderboard speaks the new rules; Rising counts game days; the events page is off the menu */
    public function testTheLeaderboardSpeaksTheNewRules()
    {
        $this->get('/leaderboard')->assertOk()->assertSee('id="leaderboard-intro"', false)
            ->assertDontSee('shares it holds in other companies')->assertDontSee('launching a copy')
            ->assertSee('Rising</b> is the gain over the last ' . Leaderboard::RISING_DAYS . ' game days', false)
            ->assertDontSee('href="' . url('events') . '"', false);
    }

    /** #715: the drone page's script line has a space after its time */
    public function testTheScriptLineReadsWell()
    {
        [, $id] = $this->matured();
        $d = $this->drone($id);
        DB::table('agents')->where('agent_id', $d)->update(['script_status' => 'buying', 'script_status_t' => World::gameNow() - 60]);
        $this->get('/spacecraft/' . $d)->assertOk()->assertSee('): <span v-pre>buying', false);
    }
}
