<?php

namespace Tests\Feature;

use App\Http\Controllers\HomeController;
use App\Http\Controllers\WorldController;
use App\Services\Kept;
use App\Services\Players;
use App\Services\PlayerShares;
use App\Services\Seedlings;
use App\Services\World;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Round 70's leftovers (#726): a slow stock exchange page, a Spacecraft
 * column that counted wrecks, an equip message for a tool that was aboard,
 * a restart message from the place the drone had left, and an events page
 * that never answered.
 */
class RoundSeventyLeftoversTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['forge.new_rules' => true, 'forge.seedling_hull_value' => 1000, 'forge.seedling_fee' => 200,
            'forge.starting_credits' => 3000, 'forge.seedling_mature_margin' => 500, 'forge.seedling_max' => 5,
            'forge.player_share_credits' => 5000, 'forge.hull_life_days' => 365, 'forge.share_range_low' => 0.8,
            'forge.share_range_high' => 1.25, 'forge.bank_pay_days' => 30]);
        Mail::fake();
        Cache::flush();
        $now = time();
        DB::table('game_clock')->delete();
        DB::table('game_clock')->insert(['id' => 1, 'real_anchor' => $now, 'game_anchor' => $now, 'scale' => 1,
            'target_scale' => 1, 'epoch' => $now - 50 * 86400 - 3600, 'load_checked_at' => $now]);
    }

    private function matured(): array
    {
        $email = 'leftover' . uniqid() . '@example.com';
        $u = Players::create('Left' . substr(uniqid(), -6), 'long-enough', $email);
        Players::verify($u->user_id, sha1($email));
        $owner = User::where('user_id', $u->user_id)->first();
        $id = Seedlings::start($owner, 'Leftover Co ' . uniqid(), 'function launched() wait(60) end')['company'];
        DB::table('companies')->where('company_id', $id)->update(['credits' => 9700]);
        $this->assertTrue(Seedlings::mature($owner, $id)['ok']);
        return [$owner, $id];
    }

    private function queries(callable $work): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $work();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();
        return $n;
    }

    /** 1: one cash-out a company, worked out for them all at once, and the same figures */
    public function testTheStockExchangeWorksEveryCompanyOutAtOnce()
    {
        [, $a] = $this->matured();
        [, $b] = $this->matured();
        // Fuel aboard one: its hull counts
        DB::table('holdings')->updateOrInsert(['drone' => (int) DB::table('agents')->where('company_id', $a)->value('agent_id'),
            'resource' => \App\Services\Release::FUEL, 'installed' => 0, 'locked' => 0], ['units' => 20, 'event_id' => 0]);
        $all = PlayerShares::cashOuts([$a, $b]);
        $this->assertSame(PlayerShares::cashOut($a), $all[$a]);
        $this->assertSame(PlayerShares::cashOut($b), $all[$b]);
        $this->assertGreaterThan(0, $all[$a]['hulls'], 'its working drone counts');
        $this->assertSame(['total' => 0, 'per_share' => 0, 'credits' => 0, 'goods' => 0, 'hulls' => 0, 'debts' => 0],
            PlayerShares::cashOuts([999999999])[999999999]);

        $row = collect(PlayerShares::market())->firstWhere('company', $a);
        // Its 30-day dividends beside the bank's prices (#781: the selling price adds 10 days of them, the boards show 30)
        $this->assertSame(PlayerShares::prices($a) + ['dividends30' => PlayerShares::dividends30($a), 'count' => 100, 'offered' => 0, 'holders' => PlayerShares::holders($a)],
            array_diff_key($row, ['company' => 1, 'name' => 1]));

        // The table's queries don't grow with its companies, but for health
        $two = $this->queries(fn () => PlayerShares::board());
        $this->matured();
        $this->matured();
        $four = $this->queries(fn () => PlayerShares::board());
        $health = $this->queries(fn () => \App\Services\CompanyHealth::of($a));
        $this->assertLessThanOrEqual($two + 2 * $health, $four);
    }

    /** 1: the page is kept a minute: a second view runs none of its queries */
    public function testTheStockExchangePageIsKept()
    {
        [, $id] = $this->matured();
        $this->get('/market/shares')->assertOk()->assertSee(DB::table('companies')->where('company_id', $id)->value('name'));
        $this->assertSame(0, $this->queries(fn () => WorldController::sharesPage()));
        $this->assertNotNull(Cache::get('market:shares:1'));
    }

    /** 1: past its TTL the last one is served; a view never rebuilds it, the scheduler's keeper does (#738) */
    public function testKeptServesTheLastAndTheKeeperRebuildsIt()
    {
        Cache::forever('leftover:kept', ['data' => ['old'], 'at' => time() - 100]);
        $this->assertSame(['old'], Kept::get('leftover:kept', 60, fn () => ['new']));
        // Nothing kept: the first view waits for it
        $this->assertSame(['first'], Kept::get('leftover:none', 60, fn () => ['first']));
    }

    /** 2: the companies list counts working drones, and wrecks apart; the company page folds its wrecks away */
    public function testTheCompaniesListCountsWorkingDrones()
    {
        [, $id] = $this->matured();
        $drone = (int) DB::table('agents')->where('company_id', $id)->value('agent_id');
        $user = (int) DB::table('agents')->where('agent_id', $drone)->value('user_id');
        foreach (['Wreck One', 'Wreck Two'] as $name) {
            DB::table('agents')->insert(['name' => $name . uniqid(), 'user_id' => $user, 'company_id' => $id, 'object_id' => 3,
                'hull_type' => 1, 'wrecked_at' => World::gameNow() - 3600, 'wreck_cause' => 'fuel']);
        }
        $name = DB::table('companies')->where('company_id', $id)->value('name');
        $row = collect($this->getJson('/companies/search?name=' . urlencode($name))->assertOk()->json('data'))->firstWhere('user_id', $id);
        $this->assertSame([1, 2], [(int) $row['agents'], (int) $row['wrecks']]);
        $this->getJson('/companies/search?name=' . urlencode($name) . '&sort=wrecks|desc')->assertOk();
        $this->get('/companies/' . $id)->assertOk()->assertSee('id="drones"', false)->assertSee('id="wrecks"', false)
            ->assertSee('2 wrecks')->assertSee('ran out of fuel');
        $vue = file_get_contents(base_path('resources/assets/js/components/CompaniesTable.vue'));
        $this->assertStringContainsString("title: 'Wrecks'", $vue);
        $this->assertStringNotContainsString("title: 'Spacecraft'", $vue);
    }

    /** 3: equip says a tool is missing only when it is (test_lua_equip runs it); the engine's copy is rebuilt */
    public function testEquipTrustsOnlyAToolThatIsReallyMissing()
    {
        $lua = file_get_contents(base_path('game-engine/src/lua_library.lua'));
        $this->assertStringContainsString('if not rows or not rows[1] or rows[1][1] == nil then return false end', $lua);
        $this->assertStringContainsString('if missing_tool(kit) == lacks then', $lua);
        $this->assertStringContainsString('was missing: bought the kit here', $lua);
        $this->assertStringContainsString('if missing_tool(kit) == lacks then',
            file_get_contents(base_path('game-engine/src/lua_library.c')), 'the engine\'s copy is rebuilt');
    }

    /** 4: a reply's Location is where the drone is: a move that is due counts, even before it is applied */
    public function testARepliesLocationReadsAMoveThatIsDue()
    {
        $src = file_get_contents(base_path('game-engine/src/db_common.c'));
        $this->assertSame(1, preg_match('/int db_drone_place\(.*?snprintf\(query_text, sizeof\(query_text\),\s*(.*?), game_t, drone_id\);/s', $src, $m));
        $sql = implode('', array_map(fn ($s) => stripcslashes($s), (preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $m[1], $parts) ? $parts[1] : [])));
        $place = fn (int $drone, int $t) => (int) DB::selectOne(str_replace(['%lld', '%d'], [$t, $drone], 'SELECT (' . $sql . ') AS p'))->p;

        [, $id] = $this->matured();
        $drone = (int) DB::table('agents')->where('company_id', $id)->value('agent_id');
        DB::table('agents')->where('agent_id', $drone)->update(['object_id' => 4]);   // Mars, where it left from
        $now = World::gameNow();
        $this->assertSame(4, $place($drone, $now));
        // It reached Earth a game second ago; the events engine hasn't applied it yet
        DB::table('events')->insert(['event_type' => 3, 'action' => 0, 'logged' => 0, 'outcome' => 0, 'drone' => $drone,
            'new_location' => 3, 'game_t' => $now - 1]);
        $this->assertSame(3, $place($drone, $now));
        // A move still on its way doesn't count
        $this->assertSame(4, $place($drone, $now - 10));
        $this->assertStringContainsString('db_drone_place(agent_id, game_time_now_seconds())',
            file_get_contents(base_path('game-engine/src/engine.c')));
    }

    /** 5: the events page searches the latest events only, and is back on the World menu */
    public function testTheEventsPageAnswersFromTheLatestEvents()
    {
        [, $id] = $this->matured();
        $drone = (int) DB::table('agents')->where('company_id', $id)->value('agent_id');
        $now = World::gameNow();
        $event = fn (int $t) => DB::table('events')->insertGetId(['event_type' => 1, 'action' => 0, 'logged' => 1, 'outcome' => 1,
            'drone' => $drone, 'resource' => 2011, 'installed' => 0, 'locked' => 0, 'new_quantity' => 3, 'game_t' => $t,
            'timestamp' => now()]);
        $old = $event($now - 7200);
        $new = $event($now - 60);
        $later = $event($now - 30);
        DB::table('observations')->insert([
            ['event' => $old, 'drone' => 0, 'time' => now(), 'game_t' => $now - 7000],
            ['event' => $new, 'drone' => 0, 'time' => now(), 'game_t' => $now - 50],
            ['event' => $later, 'drone' => 0, 'time' => now(), 'game_t' => $now + 600],   // its light isn't here yet
        ]);
        $ids = fn ($json) => array_map(fn ($r) => (int) $r['id'], $json['data']);
        $json = $this->getJson('/events/search')->assertOk()->assertJsonStructure(['current_page', 'data', 'total', 'last_page'])->json();
        $this->assertSame(HomeController::EVENTS_WINDOW, $json['window']);
        $this->assertSame($new, $ids($json)[0], 'newest first');
        $this->assertContains($old, $ids($json));
        $this->assertNotContains($later, $ids($json));
        $asc = $ids($this->getJson('/events/search?sort=timestamp|asc')->json());
        $this->assertLessThan(array_search($new, $asc), array_search($old, $asc), 'Time sorts oldest first');
        $name = DB::table('agents')->where('agent_id', $drone)->value('name');
        $this->assertContains($new, $ids($this->getJson('/events/search?keyword=' . urlencode($name))->json()));
        $this->assertSame([], $ids($this->getJson('/events/search?keyword=no-such-thing-' . uniqid())->json()));
        $this->assertLessThanOrEqual(10, count($this->getJson('/events/search?limit=10')->json('data')));

        $this->get('/events')->assertOk()->assertSee('id="events-window"', false);
        $this->get('/dashboard')->assertOk()->assertSee('href="' . url('events') . '"', false);
    }
}
