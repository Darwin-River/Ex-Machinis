<?php

namespace Tests\Feature;

use App\Services\Dashboard;
use App\Services\Players;
use App\Services\Startups;
use App\Services\World;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The economy dashboard (#698): a compact strip on the home page, the whole
 * of it at /dashboard and GET /api/v1/dashboard, worked out on the server
 * and kept for a minute
 */
class DashboardTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['forge.new_rules' => true, 'forge.startup_hull_value' => 1000, 'forge.startup_fee' => 200,
            'forge.starting_credits' => 3000, 'forge.startup_mature_margin' => 500, 'forge.startup_max' => 5,
            'forge.player_share_credits' => 5000, 'forge.hull_life_days' => 365]);
        DB::table('house_rules')->updateOrInsert(['id' => 1], ['tax_period' => 0, 'tax_grace' => 0, 'tax_flat' => 0,
            'tax_permille' => 0, 'plunder_share' => 50, 'compute_tax_per_million' => 15]);
        Mail::fake();
        Cache::flush();
    }

    private function player(string $name): User
    {
        $email = strtolower(str_replace(' ', '.', $name)) . '@example.com';
        $user = Players::create($name, 'long-enough', $email);
        Players::verify($user->user_id, sha1($email));
        return User::where('user_id', $user->user_id)->first();
    }

    /** A matured company, and a trade it made yesterday */
    private function world(): array
    {
        $owner = $this->player('Dash Owner');
        $id = Startups::start($owner, 'Dash Matured', "function launched() wait(60) end")['company'];
        DB::table('companies')->where('company_id', $id)->update(['credits' => 9000]);
        $this->assertTrue(Startups::mature($owner, $id)['ok']);
        $buyer = Startups::start($owner, 'Dash Buyer', "function launched() wait(60) end")['company'];
        $epoch = (int) DB::table('game_clock')->where('id', 1)->value('epoch');
        $today = intdiv(World::gameNow() - $epoch, 86400);
        $drone = fn ($c) => (int) DB::table('agents')->where('company_id', $c)->value('agent_id');
        DB::table('trades')->insert(['resource' => 2011, 'units' => 10, 'credits' => 4321, 'tax' => 21,
            'seller' => $drone($id), 'buyer' => $drone($buyer), 'location' => 3, 'game_t' => $epoch + ($today - 1) * 86400 + 3600]);
        // It matured two days ago, so yesterday's sale was a matured company's
        // (founded five days ago: a company is founded before it matures)
        DB::table('companies')->where('company_id', $id)->update(['matured_t' => $epoch + ($today - 2) * 86400,
            'founded_t' => $epoch + ($today - 5) * 86400]);
        return [$id, $today];
    }

    public function testTheApiGivesTheWholeDashboard()
    {
        [$id, $today] = $this->world();
        $d = $this->getJson('/api/v1/dashboard')->assertOk()
            ->assertJsonStructure(['as_of', 'today', 'banner' => ['day', 'trades', 'norm_trades', 'quiet', 'slowed', 'text',
                'processor_tax_multiplier', 'engine_behind_seconds'],
                'tiles' => ['trades', 'credits', 'drones' => ['working', 'wrecks', 'wreck_share'], 'matured' => ['count', 'cash_out'],
                    'profit' => ['week', 'last_week'], 'players' => ['count']],
                'trading' => ['places', 'days'], 'matured_profit', 'goods', 'spreads', 'news', 'companies', 'drones_by_place',
                'players', 'sources'])->json();
        $this->assertSame($today - 1, $d['banner']['day']);
        $this->assertGreaterThanOrEqual(1, $d['tiles']['trades']['value']);
        $this->assertGreaterThanOrEqual(4321, $d['tiles']['credits']['value']);
        $this->assertCount(Dashboard::DAYS, $d['trading']['days']);
        $mine = collect($d['companies'])->firstWhere('company', $id);
        $this->assertNotNull($mine, 'every matured company is in the table');
        $this->assertSame('Dash Owner', $mine['owner']);
        $this->assertSame(6, count($mine['daily_profit']), 'one a day since it was founded, five days ago');
        $this->assertSame(4300, $mine['profit30'], 'the same sale as the profit chart (#716)');
        // Its sale after tax, as the matured companies' profit that day
        $this->assertSame(4300, collect($d['matured_profit'])->firstWhere('day', $today - 1)['sales']);
        $this->assertContains('Dash Owner', array_column($d['players'], 'name'));
    }

    public function testItIsKeptForAMinute()
    {
        $this->world();
        $before = $this->getJson('/api/v1/dashboard')->json('tiles.players.count');
        $late = $this->player('Late Joiner');
        \App\Services\Startups::start($late, 'Late Co', 'function launched() wait(60) end');   // played (#867)
        $this->assertSame($before, $this->getJson('/api/v1/dashboard')->json('tiles.players.count'), 'a page view runs no heavy query');
        Cache::flush();
        $this->assertSame($before + 1, $this->getJson('/api/v1/dashboard')->json('tiles.players.count'));
    }

    public function testTheDashboardPage()
    {
        [$id] = $this->world();
        $this->get('/dashboard')->assertOk()
            ->assertSee('Economy dashboard')->assertSee('id="economy-banner"', false)
            ->assertSee('Is the market busy?')->assertSee('Which companies are worth buying?')
            ->assertSee('Who is winning?')->assertSee('id="dash-news"', false)
            ->assertSee(url('companies/' . $id), false)
            ->assertSee('window.ExCharts', false);
        // In the menu, under World
        $this->get('/market')->assertOk()->assertSee('href="' . url('dashboard') . '"', false);
    }

    public function testTheBannerSaysWhenTheClockIsSlowed()
    {
        $now = time();
        DB::table('game_clock')->updateOrInsert(['id' => 1], ['real_anchor' => $now, 'game_anchor' => $now, 'scale' => 60,
            'epoch' => $now - 10 * 86400, 'target_scale' => 1800, 'floor_scale' => 60, 'lag_seconds' => 300,
            'slices_per_minute' => 900, 'is_full' => 0, 'load_checked_at' => $now]);
        $text = $this->getJson('/api/v1/dashboard')->assertOk()->json('banner.text');
        $this->assertStringContainsString('The clock is slowed: a game day takes 24 real minutes (usually 48 real seconds)', $text);
        $this->assertStringContainsString('the engines are 300 real seconds behind', $text);
    }
}
