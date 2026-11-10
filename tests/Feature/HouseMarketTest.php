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

    /** #550: how much more the House takes today, at what bid, and when it refills */
    public function testHouseDemandShowsWhatItStillTakesToday()
    {
        HouseStations::ensure();
        $drone = (int) DB::table('agents')->where('station_id', 2)->value('agent_id');
        $this->atDay(10.75);
        $pumps = fn () => collect(HouseMarket::demand(4311, 10))->first();
        $d = $pumps();
        $listed = (int) DB::table('house_market')->where('station_id', 2)->where('resource', 4311)->where('side', 1)->value('price');
        $daily = (int) DB::table('house_market')->where('station_id', 2)->where('resource', 4311)->where('side', 1)->value('daily_units');
        $keep = max(1, intdiv((int) DB::table('house_market')->where('station_id', 2)->where('resource', 4311)->where('side', 2)->value('daily_units'), 4));
        $this->assertSame($listed, $d['price'], 'nothing bought yet: the listed price');
        $this->assertSame($daily, $d['left']);
        $this->assertSame(6, $d['hours'], 'it refills at the start of the next game day');

        // Half a day's demand bought (above the stock it keeps to sell): a lower bid, half left
        $half = intdiv($daily, 2);
        DB::table('events')->insert(['event_type' => 1, 'action' => 0, 'logged' => 1, 'outcome' => 1, 'drone' => $drone,
            'resource' => 4311, 'installed' => 0, 'locked' => 0, 'new_quantity' => $keep + $half, 'new_cargo' => 0]);
        $d = $pumps();
        $this->assertSame($daily - $half, $d['left']);
        $this->assertSame($listed - intdiv($listed * $half, 2 * $daily), $d['price']);
        HouseMarket::refresh();
        $posted = (int) DB::table('events')->where('drone', $drone)->where('event_type', 5)->where('resource', 4311)
            ->orderBy('id', 'desc')->value('new_credits');
        $this->assertSame($posted, $d['price'], 'the same as the bid it posts');

        $this->getJson('/api/v1/house-demand?resource=4311&place=10')->assertOk()
            ->assertJsonPath('0.left', $daily - $half)->assertJsonPath('0.place_name', 'Moon');
        $this->get('/market?house=4311')->assertOk()->assertSee('House demand')
            ->assertSee(number_format($daily - $half) . ' of ' . number_format($daily));
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
}
