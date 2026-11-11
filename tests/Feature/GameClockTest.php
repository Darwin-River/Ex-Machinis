<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Events inserted without a game time (by the website, such as a new drone's
 * starter kit, or by any script) get the current game time from the
 * events_game_t trigger (migration 0002), since the engines order and look
 * up events by game_t (#327).
 */
class GameClockTest extends TestCase
{
    use DatabaseTransactions;

    private function insertEvent(): ?int
    {
        $id = DB::table('events')->insertGetId([
            'event_type' => 1, 'outcome' => 1, 'drone' => 1, 'resource' => 2, 'new_quantity' => 1,
        ]);

        $gameT = DB::table('events')->where('id', $id)->value('game_t');
        return $gameT === null ? null : (int) $gameT;
    }

    public function testEventsGetTheGameTimeAtTheClocksScale()
    {
        DB::table('game_clock')->delete();
        // Anchored an hour ago at game time 1000, running 24 times faster
        DB::insert('INSERT INTO game_clock (id, real_anchor, game_anchor, scale) VALUES (1, UNIX_TIMESTAMP() - 3600, 1000, 24)');

        $this->assertEqualsWithDelta(1000 + 3600 * 24, $this->insertEvent(), 48);
    }

    public function testWithoutAClockEventsGetRealTime()
    {
        DB::table('game_clock')->delete();

        $this->assertEqualsWithDelta(time(), $this->insertEvent(), 2);
    }

    public function testAGivenGameTimeIsKept()
    {
        $id = DB::table('events')->insertGetId([
            'event_type' => 1, 'outcome' => 1, 'drone' => 1, 'resource' => 2, 'new_quantity' => 1, 'game_t' => 42,
        ]);

        $this->assertSame(42, (int) DB::table('events')->where('id', $id)->value('game_t'));
    }
}
