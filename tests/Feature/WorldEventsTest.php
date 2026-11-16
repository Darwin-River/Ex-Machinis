<?php

namespace Tests\Feature;

use App\Services\WorldEvents;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Event marks on the time charts and steadier share lines (#720): one list
 * of world events, each company's own events from small tables, the API,
 * and the pages that draw them
 */
class WorldEventsTest extends TestCase
{
    use DatabaseTransactions;

    private int $now;
    private int $epoch;

    protected function setUp(): void
    {
        parent::setUp();
        config(['forge.new_rules' => true]);
        $this->now = time();
        // Today is game day 2222; game time runs at real time
        $this->epoch = $this->now - 2222 * 86400 - 3600;
        DB::table('game_clock')->delete();
        DB::table('game_clock')->insert(['id' => 1, 'real_anchor' => $this->now, 'game_anchor' => $this->now, 'scale' => 1,
            'epoch' => $this->epoch]);
        DB::table('game_clock_history')->delete();
        Cache::flush();
    }

    private function company(array $extra = []): int
    {
        $owner = DB::table('users')->insertGetId(['name' => 'ev' . uniqid(), 'login' => 'ev' . uniqid(), 'credits' => 0]);
        return DB::table('companies')->insertGetId(array_merge(['user_id' => $owner, 'owner_id' => $owner, 'seedling' => 0,
            'name' => 'Events Co ' . uniqid(), 'credits' => 0, 'house' => 0], $extra));
    }

    private function t(float $day): int
    {
        return (int) ($this->epoch + $day * 86400);
    }

    public function testTheWorldsEventsKeepTheCapAndTheOutage()
    {
        $events = collect($this->getJson('/api/v1/world-events')->assertOk()->json())->keyBy('key');
        $cap = $events['fleet-cap'];
        $this->assertSame(2134, $cap['day']);
        $this->assertSame('rule', $cap['kind']);
        $this->assertSame(682, $cap['issue']);
        $this->assertStringContainsString('106 drones to 8', $cap['text']);
        $outage = $events['outage-full-disk'];
        $this->assertSame('outage', $outage['kind']);
        $this->assertSame([2161, 2170], [$outage['day'], $outage['to_day']]);
        $this->assertSame('day 2161, 00:00', $outage['when']);
        // Oldest first
        $days = $events->pluck('day')->values()->all();
        $sorted = $days;
        sort($sorted);
        $this->assertSame($sorted, $days);
    }

    public function testAnEventTakesItsDayFromItsMigrationOrTheReset()
    {
        DB::table('schema_version')->updateOrInsert(['version' => 9720], ['name' => '9720_events_test',
            'applied_at' => date('Y-m-d H:i:s', $this->t(2200.5))]);
        $this->company(['eliminated_at' => $this->t(2088.25), 'end_cause' => 'reset']);
        config(['world-events' => [
            ['key' => 'by-migration', 'kind' => 'repair', 'migration' => '9720_events_test', 'day' => 5, 'label' => 'M', 'text' => 'By migration.'],
            ['key' => 'fallback', 'kind' => 'rule', 'migration' => '9721_never_applied', 'day' => 2100, 'label' => 'F', 'text' => 'Its day.'],
            ['key' => 'unknown', 'kind' => 'rule', 'migration' => '9722_never_applied', 'label' => 'U', 'text' => 'No day here.'],
            ['key' => 'reset', 'kind' => 'rule', 'reset' => true, 'label' => 'R', 'text' => 'The reset.'],
        ]]);
        $e = collect(WorldEvents::build())->keyBy('key');
        // The migration's real time, turned into game time by the clock
        $this->assertSame(2200, $e['by-migration']['day']);
        $this->assertSame('day 2200, 12:00', $e['by-migration']['when']);
        // Not applied here: its day; with none, it is left out
        $this->assertSame(2100, $e['fallback']['day']);
        $this->assertFalse($e->has('unknown'));
        $this->assertSame(2088, $e['reset']['day']);
        $this->assertSame(['reset', 'fallback', 'by-migration'], $e->keys()->all());

        // The clock's history of rate changes wins over its anchors
        DB::table('game_clock_history')->insert(['real_time' => $this->t(2200), 'game_time' => $this->t(2190), 'scale' => 2]);
        $this->assertSame($this->t(2191), WorldEvents::gameTimeAt($this->t(2200.5)));
    }

    public function testTheWorldsEventsAreCached()
    {
        config(['world-events' => [['key' => 'a', 'kind' => 'rule', 'day' => 2000, 'label' => 'A', 'text' => 'A.']]]);
        $this->assertCount(1, WorldEvents::all());
        config(['world-events' => []]);
        $this->assertCount(1, WorldEvents::all());
        Cache::flush();
        $this->assertCount(0, WorldEvents::all());
    }

    public function testACompanysOwnEventsComeFromSmallTables()
    {
        $id = $this->company(['matured_t' => $this->t(2172.25), 'seedling' => 0]);
        $other = $this->company();
        foreach ([[1, 2158.2], [2, 2163.1], [3, 2163.6]] as [$n, $day]) {
            DB::table('code_pushes')->insert(['company_id' => $id, 'number' => $n, 'game_t' => $this->t($day),
                'credits_before' => 1000, 'permille' => 50, 'fee' => 50, 'drones' => 3, 'script' => '-- v' . $n]);
        }
        $user = DB::table('users')->insertGetId(['name' => 'ev' . uniqid(), 'credits' => 0]);
        $buyer = DB::table('users')->insertGetId(['name' => 'ev' . uniqid(), 'credits' => 0]);
        $trade = fn ($s, $b, $units, $day) => DB::table('player_share_trades')->insert(['company_id' => $id, 'seller_id' => $s,
            'buyer_id' => $b, 'units' => $units, 'price' => 300, 'game_t' => $this->t($day)]);
        $trade($user, null, 3, 2200.4);     // a sale to the bank, however small
        $trade($user, $buyer, 4, 2205.4);   // a small trade between players: not marked
        $trade($user, $buyer, 6, 2207.2);   // two on a day that make 12: a big trade
        $trade($user, $buyer, 6, 2207.6);
        $trade(null, $buyer, 2, 2210.5);    // a few bought from the bank: not marked
        DB::table('player_share_trades')->insert(['company_id' => $other, 'seller_id' => $user, 'buyer_id' => null,
            'units' => 50, 'price' => 1, 'game_t' => $this->t(2201)]);

        $queries = [];
        DB::listen(function ($q) use (&$queries) { $queries[] = $q->sql; });
        $res = $this->getJson("/api/v1/companies/$id/events")->assertOk()->json();
        // Never the events table (19 million rows on the live world)
        foreach ($queries as $sql) {
            $this->assertDoesNotMatchRegularExpression('/\bevents\b/', str_replace(['world-events'], '', $sql), $sql);
        }
        $this->assertSame($id, $res['company']);
        $ev = $res['events'];
        $this->assertSame(['push', 'push', 'matured', 'shares', 'shares'], array_column($ev, 'kind'));
        $this->assertSame([2158, 2163, 2172, 2200, 2207], array_column($ev, 'day'));
        $this->assertSame('Pushes 2–3', $ev[1]['label']);
        $this->assertSame('3 shares sold to the bank that game day.', $ev[3]['text']);
        $this->assertSame('Big trade', $ev[4]['label']);
        $this->assertStringContainsString('12 shares traded between players', $ev[4]['text']);

        $this->getJson('/api/v1/companies/999999999/events')->assertNotFound();

        // An ended company: its end is marked
        DB::table('companies')->where('company_id', $id)->update(['eliminated_at' => $this->t(2215), 'end_cause' => 'drones']);
        $last = collect(WorldEvents::company($id))->last();
        $this->assertSame(['ended', 2215, 'It ended (drones).'], [$last['kind'], $last['day'], $last['text']]);

        // The charts' marks: the world's and its own
        $marks = collect(WorldEvents::marks($id));
        $this->assertTrue($marks->contains(fn ($m) => $m['scope'] === 'world' && $m['label'] === '8-drone cap'));
        $this->assertTrue($marks->contains(fn ($m) => $m['scope'] === 'company' && $m['label'] === 'Matured'));
        $this->assertFalse(collect(WorldEvents::marks())->contains('scope', 'company'));
    }

    public function testTheChartsDrawTheMarksAndTheSteadierLines()
    {
        $id = $this->company(['matured_t' => $this->t(2172.25)]);
        // The company page: its marks, the key, a 7-day average with a band, and its whole life
        $page = $this->get("/companies/$id")->assertOk();
        $page->assertSee('Marks on the charts:')->assertSee("GET /api/v1/companies/$id/events")
            ->assertSee('"label":"8-drone cap"', false)->assertSee('"label":"Matured"', false)
            ->assertSee('All its life')->assertSee('const ROLL = 7', false);

        // The dashboard: the world's marks on its day charts, and one scale for the profit bars
        $this->get('/dashboard')->assertOk()->assertSee('Marks on the charts:')->assertSee('"label":"Outage"', false)
            ->assertSee('marks: M', false)->assertSee('share one scale');

        // The stock exchange: a shared percent scale, said in the caption
        $this->get('/market/shares')->assertOk()->assertSee('The 30-day lines share one scale')
            ->assertSee('Cash-out change, 30 game days');

        // The chart kit: a marks option on every day chart, and a shared scale for sparklines
        $kit = view('partials.ex-charts')->render();
        $this->assertStringContainsString('function markLayer(f, marks)', $kit);
        $this->assertStringContainsString('f.marks = opts.marks ? markLayer(f, opts.marks) : null;', $kit);
        $this->assertStringContainsString('const lo = opts.lo ??', $kit);
    }

    public function testMarketHistoryMarksTheWorldsEvents()
    {
        $resource = (int) DB::table('resources')->value('id');
        $drone = (int) DB::table('agents')->value('agent_id');
        if (!$resource || !$drone) {
            $this->markTestSkipped('No resources or drones in this database.');
        }
        DB::table('trades')->insert(['resource' => $resource, 'units' => 1, 'credits' => 10, 'tax' => 0, 'seller' => $drone,
            'buyer' => $drone, 'location' => 3, 'game_t' => $this->now - 3600]);
        $this->get('/market/history')->assertOk()->assertSee('const MARKS = ', false)->assertSee('"label":"Outage"', false)
            ->assertSee('Marks on the charts:');
    }
}
