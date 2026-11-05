<?php

namespace Tests\Feature;

use App\Services\Release;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Current state beside the history (#430, migration 0031): triggers on
 * events keep holdings and latest_orders saying what the history says,
 * whichever program writes the event and in whatever order.
 */
class CurrentStateTest extends TestCase
{
    use DatabaseTransactions;

    private const METALS = 2011;
    private int $drone;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::create(['name' => 'State Player', 'email' => uniqid() . '@example.com', 'password' => bcrypt('secret-password')]);
        $this->drone = Release::release($user, 'State Co', 'function launched() end')->agent_id;
    }

    private function event(array $fields): int
    {
        return (int) DB::table('events')->insertGetId(array_merge([
            'event_type' => 1, 'action' => 0, 'logged' => 1, 'outcome' => 1, 'drone' => $this->drone,
            'resource' => self::METALS, 'installed' => 0, 'locked' => 0, 'game_t' => 1000,
        ], $fields));
    }

    private function units(int $installed = 0, int $locked = 0): ?int
    {
        $u = DB::table('holdings')->where(['drone' => $this->drone, 'resource' => self::METALS,
            'installed' => $installed, 'locked' => $locked])->value('units');
        return $u === null ? null : (int) $u;
    }

    /** What the history says the drone holds, the way the queries worked it out before #430 */
    private function fromHistory(): int
    {
        return (int) DB::selectOne('SELECT IFNULL(SUM(e.new_quantity), 0) AS u FROM events e WHERE e.drone = ? '
            . 'AND e.resource = ? AND e.outcome = 1 AND e.event_type IN (1, 2) AND NOT EXISTS (SELECT 1 FROM events n '
            . 'WHERE n.drone = e.drone AND n.resource = e.resource AND n.installed = e.installed AND n.locked = e.locked '
            . 'AND n.outcome = 1 AND n.event_type IN (1, 2) AND n.id > e.id)', [$this->drone, self::METALS])->u;
    }

    public function testHoldingsFollowTheNewestInventoryEventOfEachKind()
    {
        $this->event(['new_quantity' => 10]);
        $this->assertSame(10, $this->units());
        $this->event(['event_type' => 2, 'new_quantity' => 4]);
        $this->assertSame(4, $this->units());
        $this->event(['installed' => 1, 'new_quantity' => 1]);
        $this->assertSame(4, $this->units());
        $this->assertSame(1, $this->units(1, 0));
        // A failed event, or one of another type, changes nothing
        $this->event(['new_quantity' => 99, 'outcome' => 2]);
        $this->event(['event_type' => 3, 'new_quantity' => 77]);
        $this->assertSame(4, $this->units());
        $this->assertSame(5, (int) DB::table('sqInventory')->where(['drone' => $this->drone, 'resource' => self::METALS])->value('units'));
        $this->assertSame($this->fromHistory(), 5);
    }

    public function testAPendingEventCountsWhenItSucceeds()
    {
        $this->event(['new_quantity' => 10]);
        $pending = $this->event(['new_quantity' => 25, 'outcome' => 0]);
        $this->assertSame(10, $this->units());
        DB::table('events')->where('id', $pending)->update(['outcome' => 1]);
        $this->assertSame(25, $this->units());
        // An update that doesn't touch the holding (the place) leaves it
        DB::table('events')->where('id', $pending)->update(['place' => 3]);
        $this->assertSame(25, $this->units());
    }

    public function testZeroingOrDeletingTheNewestEventFallsBackToTheHistory()
    {
        $this->event(['new_quantity' => 10]);
        $newest = $this->event(['new_quantity' => 30]);
        DB::table('events')->where('id', $newest)->update(['new_quantity' => 0]);
        $this->assertSame(0, $this->units());
        DB::table('events')->where('id', $newest)->delete();
        $this->assertSame(10, $this->units());
        DB::table('events')->where('drone', $this->drone)->where('resource', self::METALS)->delete();
        $this->assertNull($this->units());
    }

    public function testAnOlderEventSettlingLateDoesNotHideANewerOne()
    {
        // The newest by id wins, as in the history
        $older = $this->event(['new_quantity' => 5, 'outcome' => 0]);
        $this->event(['new_quantity' => 8]);
        DB::table('events')->where('id', $older)->update(['outcome' => 1]);
        $this->assertSame(8, $this->units());
        $this->assertSame(8, $this->fromHistory());
    }

    /** An order's stock and whether it is open, as the order books read them (#452, migration 0034) */
    private function order(int $type): array
    {
        $o = DB::table('latest_orders')->where(['drone' => $this->drone, 'resource' => self::METALS, 'event_type' => $type])
            ->first(['units', 'is_open']);
        return [(int) $o->units, (int) $o->is_open];
    }

    public function testOrdersCarryTheDronesStockAndWhetherTheyAreOpen()
    {
        $this->event(['new_quantity' => 3]);
        // Sell while more than 5 are held; buy while fewer than 10 are
        $this->event(['event_type' => 4, 'new_quantity' => 5, 'new_credits' => 20]);
        $this->event(['event_type' => 5, 'new_quantity' => 10, 'new_credits' => 12]);
        $this->assertSame([3, 0], $this->order(4));
        $this->assertSame([3, 1], $this->order(5));
        // The stock changes: both orders follow, installed and locked units count too
        $this->event(['new_quantity' => 8]);
        $this->event(['installed' => 1, 'new_quantity' => 4]);
        $this->assertSame([12, 1], $this->order(4));
        $this->assertSame([12, 0], $this->order(5));
        // A pending event counts only once it succeeds; a deleted one falls back
        $pending = $this->event(['new_quantity' => 1, 'outcome' => 0]);
        $this->assertSame([12, 1], $this->order(4));
        DB::table('events')->where('id', $pending)->update(['outcome' => 1]);
        $this->assertSame([5, 0], $this->order(4));
        DB::table('events')->where('id', $pending)->delete();
        $this->assertSame([12, 1], $this->order(4));
    }

    public function testLatestOrdersFollowTheNewestOrderEvent()
    {
        $this->event(['event_type' => 4, 'new_quantity' => 3, 'new_credits' => 20]);
        $this->event(['event_type' => 5, 'new_quantity' => 50, 'new_credits' => 12]);
        $sell = $this->event(['event_type' => 4, 'new_quantity' => 0, 'new_credits' => 25]);
        $book = DB::table('latest_orders')->where(['drone' => $this->drone, 'resource' => self::METALS])
            ->orderBy('event_type')->get(['event_type', 'price', 'quantity'])->map(fn ($o) => (array) $o)->all();
        $this->assertEquals([['event_type' => 4, 'price' => 25, 'quantity' => 0],
            ['event_type' => 5, 'price' => 12, 'quantity' => 50]], $book);
        DB::table('events')->where('id', $sell)->delete();
        $this->assertSame(20, (int) DB::table('latest_orders')->where(['drone' => $this->drone,
            'resource' => self::METALS, 'event_type' => 4])->value('price'));
        DB::table('agents')->where('agent_id', $this->drone)->delete();
        $this->assertSame(0, DB::table('latest_orders')->where('drone', $this->drone)->count()
            + DB::table('holdings')->where('drone', $this->drone)->count());
    }
}
