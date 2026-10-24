<?php

namespace Tests\Feature;

use App\Event;
use App\GameClock;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Events the website creates carry the current game time (game_t), since the
 * engines order and look up events by it (#327). A new drone's starter kit
 * is one such event.
 */
class GameClockTest extends TestCase
{
    use DatabaseTransactions;

    public function testWithoutAClockGameTimeIsRealTime()
    {
        DB::table('game_clock')->delete();

        $this->assertEqualsWithDelta(time(), GameClock::now(), 2);
    }

    public function testGameTimeRunsAtTheClocksScale()
    {
        DB::table('game_clock')->delete();
        // Anchored an hour ago at game time 1000, running 24 times faster
        DB::insert('INSERT INTO game_clock (id, real_anchor, game_anchor, scale) VALUES (1, UNIX_TIMESTAMP() - 3600, 1000, 24)');

        $this->assertEqualsWithDelta(1000 + 3600 * 24, GameClock::now(), 48);
    }

    public function testEventsTheWebsiteCreatesGetTheGameTime()
    {
        DB::table('game_clock')->delete();
        DB::insert('INSERT INTO game_clock (id, real_anchor, game_anchor, scale) VALUES (1, UNIX_TIMESTAMP(), 5000, 1)');

        $event = new Event();
        $event->event_type = 1;
        $event->action = 0;
        $event->logged = true;
        $event->outcome = 1;
        $event->drone = 1;
        $event->resource = 2;
        $event->installed = false;
        $event->locked = false;
        $event->new_quantity = 1;
        $event->save();

        $gameT = DB::table('events')->where('id', $event->id)->value('game_t');
        $this->assertEqualsWithDelta(5000, (int) $gameT, 2);
    }
}
