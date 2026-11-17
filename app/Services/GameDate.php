<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * The simulation's calendar (#753). Game time is Unix time: the orbits
 * (orbits.c) place every planet and moon by it, so game_time 2077237825 is
 * 29 Oct 2035, 02:30 UTC. The site writes game times as that date, with
 * the time of day where it matters (an action, a trade, an event), and
 * game days by their date: never "day N".
 */
class GameDate
{
    /** The date format: "29 Oct 2035" */
    public const DATE = 'j M Y';

    private static ?int $epoch = null;

    /** The world's first game day (game_clock.epoch), kept for the request */
    public static function epoch(): int
    {
        return self::$epoch ??= (int) (DB::table('game_clock')->where('id', 1)->value('epoch') ?? 0);
    }

    /** Forgets the kept epoch (tests that move the clock) */
    public static function forget(): void
    {
        self::$epoch = null;
    }

    /** A game time as a date and time of day, "29 Oct 2035, 02:30", or the date alone; '' for none */
    public static function at(?int $gameT, bool $time = true): string
    {
        if (!$gameT) {
            return '';
        }
        return gmdate($time ? self::DATE . ', H:i' : self::DATE, $gameT);
    }

    /** The date of a game day counted from the world's first (as company_days.day and the charts count them) */
    public static function ofDay(int $day): string
    {
        return gmdate(self::DATE, self::epoch() + $day * 86400);
    }

    /** A stretch of game days as dates: "8 Mar to 4 Sep 2034", or "8 Mar 2034 to 4 Jan 2035" across a year */
    public static function span(int $from, int $to): string
    {
        $a = self::epoch() + $from * 86400;
        $b = self::epoch() + $to * 86400;
        return gmdate('Y', $a) === gmdate('Y', $b)
            ? gmdate('j M', $a) . ' to ' . gmdate(self::DATE, $b)
            : gmdate(self::DATE, $a) . ' to ' . gmdate(self::DATE, $b);
    }
}
