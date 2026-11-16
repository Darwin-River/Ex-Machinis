<?php

namespace Tests\Feature;

use App\Services\DronePage;
use App\Services\EventHistory;
use App\Services\Release;
use App\Services\World;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Old settled events move to events_archive (#694 stage 3, migration 0072's
 * archive_events, which the events engine calls once a real minute). The
 * engines' lookups must still find the latest event of every kind, nothing
 * pending or still needed moves, and the pages that show old history read
 * both tables.
 */
class EventsArchiveTest extends TestCase
{
    use DatabaseTransactions;

    private const METALS = 2011;
    private const DAY = 86400;
    private int $drone;
    private int $now;
    private int $first = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::create(['name' => 'Archive Player', 'email' => uniqid() . '@example.com', 'password' => bcrypt('secret-password')]);
        $this->drone = Release::release($user, 'Archive Co', 'function launched() end')->agent_id;
        $this->now = World::gameNow();
    }

    private function event(array $fields): int
    {
        $id = (int) DB::table('events')->insertGetId(array_merge([
            'event_type' => 1, 'action' => 0, 'logged' => 1, 'outcome' => 1, 'drone' => $this->drone,
            'resource' => self::METALS, 'installed' => 0, 'locked' => 0, 'new_quantity' => 1, 'game_t' => $this->now,
        ], $fields));
        $this->first = $this->first ?: $id;
        return $id;
    }

    private function ago(float $days): int
    {
        return $this->now - (int) round($days * self::DAY);
    }

    /** Archives what is older than $days game days, from this test's first event; returns how many moved */
    private function archive(float $days = 2): int
    {
        DB::statement('CALL archive_events(?, 100000, ?, @moved, @next)', [$this->first, (int) round($days * self::DAY)]);
        return (int) DB::selectOne('SELECT @moved AS moved')->moved;
    }

    private function hot(int $id): bool
    {
        return DB::table('events')->where('id', $id)->exists();
    }

    private function archived(int $id): bool
    {
        return DB::table(EventHistory::ARCHIVE)->where('id', $id)->exists();
    }

    /** The events engine's lookup of an inventory event's base (db_get_previous_event, PREVIOUS_EVENT_BY_RESOURCE_INFO) */
    private function base(int $gameT, int $installed = 0): ?int
    {
        $id = DB::table('events')->where(['drone' => $this->drone, 'resource' => self::METALS, 'installed' => $installed,
            'locked' => 0, 'outcome' => 1])->whereIn('event_type', [1, 2])->where('game_t', '<=', $gameT)
            ->orderBy('game_t', 'desc')->orderBy('id', 'desc')->value('id');
        return $id === null ? null : (int) $id;
    }

    public function testOldInventoryEventsMoveButTheLatestOfEachKindStays()
    {
        $e1 = $this->event(['new_quantity' => 10, 'game_t' => $this->ago(5)]);
        $e2 = $this->event(['event_type' => 2, 'new_quantity' => 4, 'game_t' => $this->ago(4)]);
        $e3 = $this->event(['new_quantity' => 7, 'game_t' => $this->ago(3)]);
        $installed = $this->event(['installed' => 1, 'new_quantity' => 1, 'game_t' => $this->ago(5)]);
        $young = $this->event(['new_quantity' => 9, 'game_t' => $this->ago(0.5)]);
        $holding = fn () => DB::table('holdings')->where(['drone' => $this->drone, 'resource' => self::METALS,
            'installed' => 0, 'locked' => 0])->first(['units', 'event_id']);
        $before = $holding();

        $this->assertSame(2, $this->archive(2));
        $this->assertTrue($this->archived($e1) && !$this->hot($e1));
        $this->assertTrue($this->archived($e2) && !$this->hot($e2));
        // The latest of its kind at the cutoff stays, so any lookup from the
        // cutoff on finds its base; the only one of its kind stays
        $this->assertTrue($this->hot($e3) && !$this->archived($e3));
        $this->assertTrue($this->hot($installed));
        $this->assertTrue($this->hot($young));
        $this->assertSame($young, $this->base($this->now));
        $this->assertSame($e3, $this->base($this->ago(1)));
        $this->assertSame($installed, $this->base($this->now, 1));
        // Holdings untouched (the delete trigger is skipped while archiving)
        $this->assertEquals($before, $holding());
        $this->assertSame(10, (int) DB::table('sqInventory')->where(['drone' => $this->drone, 'resource' => self::METALS])->value('units'));
        $this->assertSame(0, (int) DB::selectOne('SELECT @events_archiving AS a')->a);

        // Nothing more to move: the same again moves nothing
        $this->assertSame(0, $this->archive(2));
    }

    public function testADeleteOutsideArchivingStillRefreshesTheHolding()
    {
        $this->event(['new_quantity' => 10, 'game_t' => $this->ago(5)]);
        $this->event(['new_quantity' => 7, 'game_t' => $this->ago(3)]);
        $young = $this->event(['new_quantity' => 9, 'game_t' => $this->ago(0.5)]);
        $this->archive(2);
        DB::table('events')->where('id', $young)->delete();
        $this->assertSame(7, (int) DB::table('holdings')->where(['drone' => $this->drone, 'resource' => self::METALS,
            'installed' => 0, 'locked' => 0])->value('units'));
    }

    public function testOrdersKeepTheirLatestAndThoseStillStanding()
    {
        $o1 = $this->event(['event_type' => 4, 'new_credits' => 10, 'new_quantity' => 5, 'installed' => null, 'locked' => null, 'game_t' => $this->ago(5)]);
        $o2 = $this->event(['event_type' => 4, 'new_credits' => 10, 'new_quantity' => 5, 'installed' => null, 'locked' => null, 'game_t' => $this->ago(4)]);
        $o3 = $this->event(['event_type' => 4, 'new_credits' => 12, 'new_quantity' => 5, 'installed' => null, 'locked' => null, 'game_t' => $this->ago(3)]);
        // A buy order placed long ago and never changed still stands
        $buy = $this->event(['event_type' => 5, 'new_credits' => 8, 'new_quantity' => 50, 'installed' => null, 'locked' => null, 'game_t' => $this->ago(6)]);
        $o4 = $this->event(['event_type' => 4, 'new_credits' => 12, 'new_quantity' => 5, 'installed' => null, 'locked' => null, 'game_t' => $this->ago(0.5)]);

        $this->archive(2);
        $this->assertTrue($this->archived($o1) && $this->archived($o2));
        $this->assertTrue($this->hot($o3) && $this->hot($o4) && $this->hot($buy));
        $this->assertSame($o4, (int) DB::table('latest_orders')->where(['drone' => $this->drone, 'resource' => self::METALS, 'event_type' => 4])->value('event_id'));
        $this->assertSame($buy, (int) DB::table('latest_orders')->where(['drone' => $this->drone, 'resource' => self::METALS, 'event_type' => 5])->value('event_id'));
    }

    public function testNothingPendingOrStillNeededMoves()
    {
        $open = (int) DB::table('actions')->insertGetId(['drone' => $this->drone, 'protocol' => 9, 'aborted' => 0]);
        $done = (int) DB::table('actions')->insertGetId(['drone' => $this->drone, 'protocol' => 9, 'aborted' => 0]);
        // An action whose arrival is still to come keeps its fuel burn (a
        // failure gives it back)
        $burn = $this->event(['action' => $open, 'event_type' => 2, 'new_quantity' => 3, 'game_t' => $this->ago(5)]);
        $pending = $this->event(['action' => $open, 'event_type' => 3, 'outcome' => 0, 'resource' => null, 'game_t' => $this->now + 3600]);
        // An old failure moves, as does a finished action's old event
        $failed = $this->event(['action' => $done, 'event_type' => 2, 'outcome' => -2, 'new_quantity' => 99, 'game_t' => $this->ago(5)]);
        $this->event(['new_quantity' => 7, 'game_t' => $this->ago(3)]);
        // A pending event, even an old one of an aborted action, never moves
        $aborted = (int) DB::table('actions')->insertGetId(['drone' => $this->drone, 'protocol' => 9, 'aborted' => 1]);
        $stuck = $this->event(['action' => $aborted, 'outcome' => 0, 'game_t' => $this->ago(5)]);
        $this->event(['new_quantity' => 8, 'game_t' => $this->ago(0.5)]);

        $this->archive(2);
        $this->assertTrue($this->hot($burn) && $this->hot($pending) && $this->hot($stuck));
        $this->assertTrue($this->archived($failed));
    }

    public function testNothingMovesPastAnEventStillToSettle()
    {
        $action = (int) DB::table('actions')->insertGetId(['drone' => $this->drone, 'protocol' => 9, 'aborted' => 0]);
        $old = $this->event(['new_quantity' => 10, 'game_t' => $this->ago(5)]);
        $kept = $this->event(['new_quantity' => 6, 'game_t' => $this->ago(4.8)]);
        $this->event(['new_quantity' => 7, 'game_t' => $this->ago(4)]);
        $this->event(['new_quantity' => 8, 'game_t' => $this->ago(3)]);
        // The events engine is four game days behind: it still has to settle this
        $this->event(['action' => $action, 'outcome' => 0, 'new_quantity' => 1, 'game_t' => $this->ago(4.5)]);
        $this->archive(2);
        // The cutoff is that event, not two days ago: the latest before it stays
        $this->assertTrue($this->archived($old));
        $this->assertTrue($this->hot($kept));
        $this->assertSame(1, DB::table(EventHistory::ARCHIVE)->where('drone', $this->drone)->count());
    }

    public function testDronePageAndHullAgeReadTheArchive()
    {
        $action = (int) DB::table('actions')->insertGetId(['drone' => $this->drone, 'protocol' => 9, 'aborted' => 0, 'outcome' => 1]);
        $this->event(['action' => $action, 'resource' => 2061, 'event_type' => 2, 'new_quantity' => 40, 'game_t' => $this->ago(40)]);
        $this->event(['resource' => 2061, 'new_quantity' => 50, 'game_t' => $this->ago(35)]);
        $this->event(['resource' => 2061, 'new_quantity' => 60, 'game_t' => $this->ago(33)]);
        $this->assertGreaterThan(0, $this->archive(30));

        $timeline = DronePage::timeline($this->drone, 10);
        $row = collect($timeline)->firstWhere('action', $action);
        $this->assertNotNull($row, 'an archived action is still on the timeline');
        $this->assertSame($this->ago(40), $row['game_t']);
        $this->assertSame(40, (int) $row['fuel']);
        $this->assertSame($this->ago(40), EventHistory::firstGameT($this->drone));
    }

    public function testTheEventsPageListsArchivedEvents()
    {
        $old = $this->event(['new_quantity' => 10, 'game_t' => $this->ago(40)]);
        $this->event(['new_quantity' => 7, 'game_t' => $this->ago(35)]);
        $young = $this->event(['new_quantity' => 8, 'game_t' => $this->ago(1)]);
        foreach ([$old, $young] as $id) {
            DB::table('observations')->insert(['drone' => 0, 'event' => $id, 'time' => now()->subMinute(), 'game_t' => $this->now]);
        }
        $this->archive(30);
        $this->assertTrue($this->archived($old));
        // Its observation stays (no cascade from events since migration 0072)
        $this->assertTrue(DB::table('observations')->where('event', $old)->exists());

        $rows = collect($this->getJson('/events/search?keyword=Archive')->assertOk()->json('data'));
        $times = $rows->pluck('game_t')->map(fn ($t) => (int) $t)->all();
        $this->assertContains($this->ago(40), $times);
        $this->assertContains($this->ago(1), $times);
    }
}
