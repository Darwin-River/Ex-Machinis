<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Local sightings (#324 step 20): a drone sees the events where it is since
 * it arrived, worked out when asked, not stored as a row per drone. Each
 * drone's arrival time is kept by triggers on agents.
 */
class LocalSightingsTest extends TestCase
{
    use DatabaseTransactions;

    private const EARTH = 3;
    private const MOON = 10;

    private int $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = time();
        DB::table('game_clock')->updateOrInsert(['id' => 1], [
            'real_anchor' => $this->now, 'game_anchor' => $this->now, 'scale' => 1, 'epoch' => $this->now - 86400,
        ]);
    }

    private function drone(string $name, int $place): int
    {
        $user = DB::table('users')->insertGetId(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)) . '@example.com',
            'password' => 'x']);
        return DB::table('agents')->insertGetId(['name' => $name, 'user_id' => $user, 'object_id' => $place, 'hull_type' => 1]);
    }

    private function event(int $drone, ?int $place, int $gameT): int
    {
        return DB::table('events')->insertGetId([
            'event_type' => 1, 'action' => 0, 'logged' => 1, 'outcome' => 1, 'drone' => $drone, 'resource' => 1100,
            'installed' => 0, 'locked' => 0, 'new_quantity' => 1, 'new_cargo' => 1, 'game_t' => $gameT,
            'place' => $place, 'timestamp' => date('Y-m-d H:i:s', $gameT),
        ]);
    }

    private function seenBy(int $drone): array
    {
        $json = $this->getJson('/events/search?observing_agent=' . $drone)->assertOk()->json();
        return array_map(fn ($row) => $row['affected_agent_id'], $json['data']);
    }

    public function testArrivalTimeIsKeptByTheDatabase()
    {
        $id = $this->drone('Arrival Probe', self::EARTH);
        $arrived = (int) DB::table('agents')->where('agent_id', $id)->value('arrived_t');
        $this->assertEqualsWithDelta($this->now, $arrived, 2, 'a new drone arrives now');

        // Changing something else leaves it alone; moving sets it again
        DB::table('game_clock')->where('id', 1)->update(['game_anchor' => $this->now + 5000]);
        DB::table('agents')->where('agent_id', $id)->update(['name' => 'Renamed Probe']);
        $this->assertSame($arrived, (int) DB::table('agents')->where('agent_id', $id)->value('arrived_t'));
        DB::table('agents')->where('agent_id', $id)->update(['object_id' => self::MOON]);
        $this->assertEqualsWithDelta($this->now + 5000, (int) DB::table('agents')->where('agent_id', $id)->value('arrived_t'), 2);
    }

    public function testADroneSeesTheEventsWhereItIsSinceItArrived()
    {
        $watcher = $this->drone('Watcher', self::EARTH);
        DB::table('agents')->where('agent_id', $watcher)->update(['arrived_t' => $this->now - 1000]);
        $here = $this->drone('Local Worker', self::EARTH);
        $there = $this->drone('Moon Worker', self::MOON);

        $this->event($here, self::EARTH, $this->now - 500);      // here, since it arrived: seen
        $this->event($here, self::EARTH, $this->now - 2000);     // here, before it arrived: not seen
        $this->event($there, self::MOON, $this->now - 500);      // elsewhere: not seen
        $this->event($here, null, $this->now - 500);             // not observable: not seen
        $this->event($here, self::EARTH, $this->now + 3600);     // not yet (game time): not seen

        $this->assertSame([$here], $this->seenBy($watcher));
        $this->assertSame(0, DB::table('observations')->where('drone', $watcher)->count(), 'no row per drone');

        // Its own observation rows still count
        $own = $this->event($watcher, self::EARTH, $this->now - 5000);
        DB::table('observations')->insert(['drone' => $watcher, 'event' => $own, 'time' => date('Y-m-d H:i:s', $this->now - 5000),
            'game_t' => $this->now - 5000]);
        $this->assertEqualsCanonicalizing([$here, $watcher], $this->seenBy($watcher));
    }
}
