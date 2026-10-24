<?php

namespace App;

use Illuminate\Support\Facades\DB;

/**
 * The game clock the engines share (game_clock table; see game_time.c).
 * Times the engines compare are game seconds (game_t columns), so anything
 * the website writes for them must use game time too (#327).
 */
class GameClock
{
    /**
     * Current game time in whole game seconds. Before the engines have
     * created the clock, game time is real time.
     */
    public static function now(): int
    {
        $row = DB::selectOne(
            'SELECT ROUND(game_anchor + (UNIX_TIMESTAMP() - real_anchor) * scale) AS t FROM game_clock WHERE id = 1'
        );

        return $row && $row->t !== null ? (int) $row->t : time();
    }
}
