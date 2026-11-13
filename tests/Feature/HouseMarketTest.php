<?php

namespace Tests\Feature;

use App\Services\HouseMarket;
use App\Services\HouseStations;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Markets that move (#380 round 36): the House's prices and daily units
 * move over game time, each exchange its own way (house_market), and its
 * orders are posted for its station drones (HouseMarket).
 */
class HouseMarketTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([5911 => 'Import Station', 5921 => 'Export Station', 2061 => 'Fuel (A1)', 2011 => 'Metals (A1)',
                  4311 => 'Pumps (A1)'] as $id => $name) {
            DB::table('resources')->insertOrIgnore(['id' => $id, 'name' => $name, 'mass' => in_array($id, [5911, 5921]) ? 0 : 1,
                'capacity' => 0, 'slot_size' => 0]);
        }
        DB::table('hulls')->insertOrIgnore(['id' => HouseStations::HULL, 'name' => 'Drop Station',
            'cargo_capacity' => 1000000, 'a_slots' => 0, 'b_slots' => 0, 'c_slots' => 0, 'd_slots' => 0, 'e_slots' => 0]);
        DB::table('stations')->delete();
        DB::table('stations')->insert([
            ['id' => 1, 'name' => 'Earth Exchange', 'object_id' => 3, 'budget_cap' => 1000, 'refill_per_day' => 1000,
             'swing_permille' => 80, 'cycle_days' => 20, 'shock_permille' => 150],
            ['id' => 2, 'name' => 'Moon Depot', 'object_id' => 10, 'budget_cap' => 1000, 'refill_per_day' => 1000,
             'swing_permille' => 200, 'cycle_days' => 7, 'shock_permille' => 300],
        ]);
        DB::table('station_goods')->delete();
        $goods = [];
        foreach ([1, 2] as $st) {
            $goods[] = ['station_id' => $st, 'resource' => 2011, 'side' => 1, 'price' => 20, 'daily_units' => 80, 'from_day' => 0, 'steady' => 0];
            $goods[] = ['station_id' => $st, 'resource' => 2011, 'side' => 2, 'price' => 40, 'daily_units' => 80, 'from_day' => 0, 'steady' => 0];
            $goods[] = ['station_id' => $st, 'resource' => 4311, 'side' => 1, 'price' => 800, 'daily_units' => 16, 'from_day' => 0, 'steady' => 0];
            $goods[] = ['station_id' => $st, 'resource' => 4311, 'side' => 2, 'price' => 1600, 'daily_units' => 16, 'from_day' => 0, 'steady' => 0];
            $goods[] = ['station_id' => $st, 'resource' => 2061, 'side' => 2, 'price' => 5, 'daily_units' => 2000, 'from_day' => 0, 'steady' => 1];
        }
        DB::table('station_goods')->insert($goods);
        $this->atDay(10);
    }

    /** Stops the game clock at a game day */
    private function atDay(float $day): void
    {
        DB::table('game_clock')->updateOrInsert(['id' => 1], ['real_anchor' => time(), 'game_anchor' => 1000000 + $day * 86400,
            'scale' => 0, 'epoch' => 1000000]);
    }

    private function prices(): array
    {
        $p = [];
        foreach (DB::table('house_market')->get() as $r) {
            $p["{$r->station_id}/{$r->resource}/{$r->side}"] = (int) $r->price;
        }
        return $p;
    }

    public function testPricesMoveByPlaceAndOverTime()
    {
        $day10 = $this->prices();
        $this->atDay(13.5);
        $later = $this->prices();
        $this->assertNotEquals($day10, $later, 'prices move over game time');
        // Each exchange its own way: the Moon's pumps don't move with Earth's
        $this->assertNotEquals($day10['1/4311/1'] / $later['1/4311/1'], $day10['2/4311/1'] / $later['2/4311/1']);
        // Fuel keeps its price
        $this->assertSame(5, $later['1/2061/2']);
        $this->assertSame(5, $later['2/2061/2']);
    }

    public function testNoExchangeBidsMoreThanAnyAsks()
    {
        for ($day = 0; $day < 60; $day += 1.5) {
            $this->atDay($day);
            $over = DB::table('house_market as b')->where('b.side', 1)
                ->whereRaw('b.price > (SELECT MIN(a.price) FROM house_market a WHERE a.resource = b.resource AND a.side = 2)')
                ->count();
            $this->assertSame(0, $over, "day $day");
        }
    }

    public function testBoomsAndSlumpsMakeTheNews()
    {
        // Some 8-day block has a boom or slump somewhere (one tier and side in four)
        $found = false;
        for ($day = 4; $day < 400 && !$found; $day += 8) {
            $this->atDay($day);
            $found = DB::table('house_market')->where('shock', '<>', 0)->exists();
        }
        $this->assertTrue($found);
        $news = HouseMarket::news();
        $this->assertNotEmpty($news);
        $this->assertContains($news[0]['kind'], ['boom', 'slump', 'shortage', 'glut']);
        $this->assertSame($news[0]['from_day'] + 8, $news[0]['to_day']);
        $this->assertStringContainsString($news[0]['place'], $news[0]['text']);
    }

    /** A deadlock as MySQL reports it (#420) */
    private function deadlock(): QueryException
    {
        $pdo = new \PDOException('Deadlock found when trying to get lock; try restarting transaction');
        $pdo->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock'];
        return new QueryException('mysql', 'insert into events', [], $pdo);
    }

    public function testADeadlockedInsertIsTriedAgain()
    {
        $tries = 0;
        $result = HouseMarket::retryOnDeadlock(function () use (&$tries) {
            if (++$tries < 3) {
                throw $this->deadlock();
            }
            return 'posted';
        });
        $this->assertSame('posted', $result);
        $this->assertSame(3, $tries);
    }

    public function testAPersistentDeadlockOrOtherErrorIsNotHidden()
    {
        $tries = 0;
        try {
            HouseMarket::retryOnDeadlock(function () use (&$tries) {
                $tries++;
                throw $this->deadlock();
            });
            $this->fail('expected the deadlock');
        } catch (QueryException $e) {
            $this->assertTrue(HouseMarket::isDeadlock($e));
            $this->assertSame(3, $tries);
        }
        $tries = 0;
        $this->expectException(\RuntimeException::class);
        HouseMarket::retryOnDeadlock(function () use (&$tries) {
            $tries++;
            throw new \RuntimeException('not a deadlock');
        });
    }

    public function testOrdersArePostedForTheStationDronesWhenTheyChange()
    {
        HouseStations::ensure();
        $drone = (int) DB::table('agents')->where('station_id', 2)->value('agent_id');
        $this->assertSame(0, HouseMarket::refresh(), 'nothing changed since it opened');

        $this->atDay(14);
        $this->assertGreaterThan(0, HouseMarket::refresh());
        $bid = DB::table('events')->where('drone', $drone)->where('event_type', 5)->where('resource', 4311)
            ->orderBy('id', 'desc')->value('new_credits');
        $this->assertEquals(DB::table('house_market')->where('station_id', 2)->where('resource', 4311)->where('side', 1)->value('price'), $bid);

        // Its bid falls as its stock of what it bought fills
        DB::table('events')->insert(['event_type' => 1, 'action' => 0, 'logged' => 1, 'outcome' => 1, 'drone' => $drone,
            'resource' => 4311, 'installed' => 0, 'locked' => 0, 'new_quantity' => 50, 'new_cargo' => 0]);
        HouseMarket::refresh();
        $lower = DB::table('events')->where('drone', $drone)->where('event_type', 5)->where('resource', 4311)
            ->orderBy('id', 'desc')->value('new_credits');
        $this->assertLessThan($bid, $lower);
    }

    /**
     * #550: how much more the House takes today, at what bid, and when it
     * refills. #608: as its buy order on the book says, and how much was
     * sold into it today
     */
    public function testHouseDemandShowsWhatItStillTakesToday()
    {
        HouseStations::ensure();
        $drone = (int) DB::table('agents')->where('station_id', 2)->value('agent_id');
        $this->atDay(10.75);
        $pumps = fn ($company = null) => collect(HouseMarket::demand(4311, 10, $company))->first();
        $listed = (int) DB::table('house_market')->where('station_id', 2)->where('resource', 4311)->where('side', 1)->value('price');
        $daily = (int) DB::table('house_market')->where('station_id', 2)->where('resource', 4311)->where('side', 1)->value('daily_units');
        $keep = max(1, intdiv((int) DB::table('house_market')->where('station_id', 2)->where('resource', 4311)->where('side', 2)->value('daily_units'), 4));
        DB::table('events')->where('drone', $drone)->where('event_type', 5)->where('resource', 4311)->delete();
        DB::table('latest_orders')->where('drone', $drone)->where('event_type', 5)->where('resource', 4311)->delete();
        $d = $pumps();
        $this->assertSame([0, 0], [$d['price'], $d['left']], 'no order on the book: no bid, whatever the day\'s demand');
        $this->assertSame($daily, $d['daily']);

        HouseMarket::refresh();
        $d = $pumps();
        $this->assertSame($listed, $d['price'], 'nothing bought yet: the listed price');
        $this->assertSame($daily, $d['left']);
        $this->assertSame(6, $d['hours'], 'it refills at the start of the next game day');
        $this->assertSame([0, 0, null], [$d['sold'], $d['mine'], $d['empty_hours']]);

        // Half a day's demand bought (above the stock it keeps to sell), from
        // one company today: half left, the bid it posted until the next tick
        $half = intdiv($daily, 2);
        DB::table('events')->insert(['event_type' => 1, 'action' => 0, 'logged' => 1, 'outcome' => 1, 'drone' => $drone,
            'resource' => 4311, 'installed' => 0, 'locked' => 0, 'new_quantity' => $keep + $half, 'new_cargo' => 0]);
        $seller = DB::table('agents')->whereNull('station_id')->whereNotNull('company_id')->first(['agent_id', 'company_id']);
        DB::table('trades')->insert(['resource' => 4311, 'units' => $half, 'credits' => $half * $listed, 'tax' => 0,
            'seller' => $seller->agent_id, 'buyer' => $drone, 'location' => 10, 'game_t' => \App\Services\World::gameNow() - 3600]);
        $d = $pumps((int) $seller->company_id);
        $this->assertSame($daily - $half, $d['left']);
        $this->assertSame($listed, $d['price'], 'the order on the book until the House posts again');
        $this->assertSame([$half, $half], [$d['sold'], $d['mine']]);
        // 18 game hours gone, half sold: the rest goes in about 18 more
        $this->assertSame((int) ceil(($daily - $half) * 18 * 3600 / $half / 3600), $d['empty_hours']);
        HouseMarket::refresh();
        $d = $pumps();
        $this->assertSame($listed - intdiv($listed * $half, 2 * $daily), $d['price'], 'its new bid, once posted');
        $this->assertSame(0, $d['mine'], 'no company asked');

        $this->getJson('/api/v1/house-demand?resource=4311&place=10&company=' . $seller->company_id)->assertOk()
            ->assertJsonPath('0.left', $daily - $half)->assertJsonPath('0.place_name', 'Moon')->assertJsonPath('0.mine', $half);
        $this->get('/market?house=4311')->assertOk()->assertSee('House demand')->assertSee('Sold today')
            ->assertSee(number_format($daily - $half) . ' of ' . number_format($daily));
    }

    /**
     * #630: what the House sells, how much is on sale, what it refills to,
     * and when its station restocks: its rounds of imports
     */
    public function testHouseSupplyShowsStockAndRestocking()
    {
        HouseStations::ensure();
        $drone = (int) DB::table('agents')->where('station_id', 2)->value('agent_id');
        $this->atDay(10.75);
        $now = \App\Services\World::gameNow();
        $daily = (int) DB::table('house_market')->where('station_id', 2)->where('resource', 2011)->where('side', 2)->value('daily_units');
        // Three rounds of imports, 1.5 game hours apart, the last an hour ago
        foreach ([4, 2.5, 1] as $hoursAgo) {
            foreach ([2011 => 0, 4311 => 300] as $resource => $later) {
                $action = DB::table('actions')->insertGetId(['drone' => $drone, 'protocol' => HouseMarket::IMPORT_PROTOCOL, 'multiplier' => 1, 'aborted' => 0]);
                DB::table('events')->insert(['event_type' => 1, 'action' => $action, 'logged' => 1, 'outcome' => 1, 'drone' => $drone,
                    'resource' => $resource, 'installed' => 0, 'locked' => 0, 'new_quantity' => 1, 'new_cargo' => 0,
                    'game_t' => (int) ($now - $hoursAgo * 3600 + $later)]);
            }
        }
        // It holds 30 Metals (A1) now (an event sets the total) and posts its sell order
        DB::table('events')->insert(['event_type' => 1, 'action' => 0, 'logged' => 1, 'outcome' => 1, 'drone' => $drone,
            'resource' => 2011, 'installed' => 0, 'locked' => 0, 'new_quantity' => 30, 'new_cargo' => 0, 'game_t' => $now - 600]);
        HouseMarket::refresh();
        // 7 sold today
        $buyer = DB::table('agents')->whereNull('station_id')->value('agent_id');
        DB::table('trades')->insert(['resource' => 2011, 'units' => 7, 'credits' => 7 * 40, 'tax' => 0,
            'seller' => $drone, 'buyer' => $buyer, 'location' => 10, 'game_t' => $now - 1800]);

        $m = collect(HouseMarket::supply(2011, 10))->first();
        $this->assertSame(10, $m['place']);
        $this->assertSame(30, $m['on_sale'], 'what its sell order offers');
        $this->assertGreaterThan(0, $m['price']);
        $this->assertSame(max(1, intdiv($daily, 4)), $m['refill_to'], 'a quarter of the day\'s supply');
        $this->assertSame(7, $m['sold']);
        $this->assertSame(1.0, $m['last_restock_hours']);
        $this->assertSame(1.5, $m['round_hours'], 'its rounds come every 1.5 game hours');
        $this->assertSame(0.5, $m['next_hours'], 'the next is due in half an hour');

        // Sold out: price 0, and listed last
        DB::table('events')->insert(['event_type' => 2, 'action' => 0, 'logged' => 1, 'outcome' => 1, 'drone' => $drone,
            'resource' => 2011, 'installed' => 0, 'locked' => 0, 'new_quantity' => 0, 'new_cargo' => 0, 'game_t' => $now - 60]);
        $m = collect(HouseMarket::supply(2011, 10))->first();
        $this->assertSame([0, 0], [$m['on_sale'], $m['price']]);

        // Drones read it from the stored table (query 707): times in game minutes
        $this->assertTrue(HouseMarket::storeSupply());
        $this->assertFalse(HouseMarket::storeSupply(), 'once a real minute');
        $sql = str_replace(['[game_now]', '[value_1]'], [(string) $now, '2011'],
            (string) DB::table('queries')->where('id', 707)->value('script'));
        if ($sql !== '') {
            $row = collect(DB::select($sql))->firstWhere('place', 10);
            $this->assertSame([0, 60, 90, 30], [(int) $row->on_sale, (int) $row->last_minutes, (int) $row->round_minutes, (int) $row->next_minutes]);
        }

        $this->getJson('/api/v1/house-supply?resource=2011&place=Moon')->assertOk()
            ->assertJsonPath('0.place_name', 'Moon')->assertJsonPath('0.round_hours', 1.5)->assertJsonPath('0.refill_to', max(1, intdiv($daily, 4)));
        $this->getJson('/api/v1/house-supply?place=Atlantis')->assertNotFound();
        $this->get('/market?supply=2011')->assertOk()->assertSee('House supply')->assertSee('sold out')
            ->assertSee('in 0.5 game hours (every 1.5)');
    }

    public function testAStationDroneOnAnOldProgramGetsTheNewOne()
    {
        HouseStations::ensure();
        $drone = (int) DB::table('agents')->where('station_id', 1)->value('agent_id');
        DB::table('agents')->where('agent_id', $drone)->update(['program_md5' => 'old', 'vm' => 'x']);
        DB::table('commands')->where('agent_id', $drone)->delete();
        DB::table('commands')->insert(['agent_id' => $drone, 'code' => '@@@RESUME@@@', 'subject' => 'Station', 'email_content' => '']);

        $made = HouseStations::ensure();
        $this->assertContains($drone, $made);
        $agent = DB::table('agents')->where('agent_id', $drone)->first();
        $this->assertNull($agent->vm);
        $this->assertSame(md5(HouseStations::script()), $agent->program_md5);
        $codes = DB::table('commands')->where('agent_id', $drone)->orderBy('id')->pluck('code')->all();
        $this->assertNotContains('@@@RESUME@@@', $codes);
        $this->assertSame('<launched>', end($codes));
        $this->assertSame([], HouseStations::ensure(), 'once is enough');
    }

    /** #591: the Market page has jump links and filters its order book by resource */
    public function testTheMarketPageFiltersTheOrderBook()
    {
        HouseStations::ensure();
        HouseMarket::refresh();
        $this->get('/market')->assertOk()->assertSee('href="#house-demand"', false)->assertSee('Still buying today');
        // #609: where fuel is on sale now, and where it isn't
        $fuel = \App\Services\World::fuelForSale();
        $this->assertSame(count($fuel['selling']) + count($fuel['none']), DB::table('stations')->count(),
            'every exchange is either selling fuel or listed as not');
        $this->get('/market')->assertSee('id="fuel-for-sale"', false)->assertSee('Fuel on sale now');
        $html = $this->get('/market?resource=4311')->assertOk()->getContent();
        $this->assertStringContainsString('Pumps (A1)', $html);
        $book = substr($html, strpos($html, 'id="order-book"'), strpos($html, 'id="house-demand"') - strpos($html, 'id="order-book"'));
        $this->assertStringNotContainsString('<td class="resource">Metals (A1)</td>', $book, 'only pumps in the book');
    }
}
