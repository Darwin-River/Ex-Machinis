<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A game's whole event history (#694 stage 3): the events engine moves
 * settled events older than house_rules.events_archive_days game days (30)
 * from events to events_archive, so the engines' table stays small. Pages
 * that show old history (a drone's timeline and post-mortem, the events
 * page, a hull's age) read both tables through here; pages that show the
 * present (holdings, open orders, what is under way) read events alone,
 * which always keeps the latest event of every kind.
 */
class EventHistory
{
    public const ARCHIVE = 'events_archive';

    /**
     * Both tables as one, aliased $alias, each filtered by $where before the
     * union so each uses its own indexes. $where takes a query builder and
     * names columns without a table (e.g. where('action', 5)).
     */
    public static function both(\Closure $where, string $alias = 'e', array $columns = ['*']): Builder
    {
        $hot = DB::table('events')->select($columns);
        $where($hot);
        $old = DB::table(self::ARCHIVE)->select($columns);
        $where($old);
        return DB::query()->fromSub($hot->unionAll($old), $alias);
    }

    /** The earliest game time of a drone's events, or null: when it came into the game */
    public static function firstGameT(int $drone): ?int
    {
        $times = array_filter([
            DB::table('events')->where('drone', $drone)->min('game_t'),
            DB::table(self::ARCHIVE)->where('drone', $drone)->min('game_t'),
        ], fn ($t) => $t !== null);
        return $times ? (int) min($times) : null;
    }
}
