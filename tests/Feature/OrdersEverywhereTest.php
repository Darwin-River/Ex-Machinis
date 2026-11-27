<?php

namespace Tests\Feature;

use App\Services\LiveQuery;
use App\Services\Release;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Orders everywhere (query 615, #1078): a busy exchange's whole book, every
 * order for a metal, ran drones out of memory. Each place and side gives its
 * best five orders from others and all the drone's own, with total, all the
 * units others offer or want there.
 */
class OrdersEverywhereTest extends TestCase
{
    use DatabaseTransactions;

    private const METALS = 2011;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $sql = file_get_contents(base_path('game-engine/sql/game_data.sql'));
        $sql = substr($sql, strpos($sql, 'START TRANSACTION;') + strlen('START TRANSACTION;'));
        DB::unprepared(preg_replace('/^COMMIT;\s*$/m', '', $sql));
        $this->user = User::create(['name' => 'Book Player', 'email' => uniqid() . '@example.com', 'password' => bcrypt('secret-password')]);
    }

    /** A drone at Earth holding units of metals, with an order: sell (4) above $keep, or buy (5) up to $keep */
    private function trader(int $type, int $price, int $units, int $keep, int $credits = 100000): int
    {
        static $n = 0;
        $drone = (int) Release::release($this->user, 'Book Co ' . (++$n) . uniqid(), 'function launched() end')->agent_id;
        $company = (int) DB::table('agents')->where('agent_id', $drone)->value('company_id');
        DB::table('companies')->where('company_id', $company)->update(['credits' => $credits]);
        $place = (int) DB::table('agents')->where('agent_id', $drone)->value('object_id');
        foreach ([['event_type' => 1, 'new_quantity' => $units], ['event_type' => $type, 'new_quantity' => $keep, 'new_credits' => $price]] as $e) {
            DB::table('events')->insert(array_merge(['action' => 0, 'logged' => 1, 'outcome' => 1, 'drone' => $drone,
                'resource' => self::METALS, 'installed' => 0, 'locked' => 0, 'game_t' => 1000, 'place' => $place], $e));
        }
        return $drone;
    }

    public function testEachPlaceGivesItsBestFiveASideAndItsWholeStock()
    {
        // Twelve sellers of 10 at 30..41, eight buyers of 20 at 10..17, at Earth
        $sellers = [];
        foreach (range(0, 11) as $i) {
            $sellers[] = $this->trader(4, 41 - $i, 10, 0);
        }
        foreach (range(0, 7) as $i) {
            $this->trader(5, 10 + $i, 0, 20);
        }
        $me = $sellers[0];   // the dearest seller asks: its own order is kept though it isn't among the best

        $r = LiveQuery::lua(615, $me, [self::METALS]);
        $this->assertSame(0, $r['code']);
        $rows = array_map(fn ($row) => array_combine(array_map(fn ($f) => $f['name'], $row), array_map(fn ($f) => $f['value'], $row)), $r['rows']);
        $sells = array_values(array_filter($rows, fn ($o) => (int) $o['side'] === 2));
        $buys = array_values(array_filter($rows, fn ($o) => (int) $o['side'] === 1));

        $others = array_values(array_filter($sells, fn ($o) => (int) $o['mine'] === 0));
        $this->assertSame([30, 31, 32, 33, 34], array_map(fn ($o) => (int) $o['price'], $others), 'the five cheapest sells');
        $this->assertCount(1, array_filter($sells, fn ($o) => (int) $o['mine'] === 1), 'and its own');
        $this->assertSame([110], array_values(array_unique(array_map(fn ($o) => (int) $o['total'], $sells))), 'the eleven others\' 10s');
        $this->assertSame([17, 16, 15, 14, 13], array_map(fn ($o) => (int) $o['price'], array_reverse($buys)), 'the five dearest buys');
        $this->assertSame([160], array_values(array_unique(array_map(fn ($o) => (int) $o['total'], $buys))));
    }
}
