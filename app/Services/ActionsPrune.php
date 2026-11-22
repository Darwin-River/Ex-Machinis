<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Finished actions go after 30 game days (#944). Nothing deleted from
 * `actions`, and on the live world it had grown to 44.6 million rows, 3.07
 * GB, the database's largest table. Once a real minute on the keeper, this
 * walks the table by id, a window of BATCH actions at a time, and deletes
 * each action that is finished and old:
 *
 *   - finished: no event of it is left in `events`. So none is pending
 *     (outcome 0), and nothing the engines or the drones' queries read
 *     (they read `events` alone: 803, 804, 820, 821, 845, the order book,
 *     a drone in flight) loses its action; the archiver (#694) moves an
 *     event out only once its action has nothing left to settle and it is
 *     no longer the latest of its kind. And nothing else holds it: no tool
 *     lock (migration 0082), no drone waiting for it (agents.waiting_action,
 *     which also holds back the drone's resume), no share request on its way
 *     to Earth, no station offer whose batch hasn't cleared;
 *   - old: its last event, in events_archive, is more than
 *     house_rules.actions_keep_days game days before the game clock's now.
 *     An action whose events are all gone (pruned by HistoryBudget, or lock
 *     and unlock events the archiver drops) has no game time left: it goes
 *     once its row is as many real days old, which is at least as many game
 *     days at any clock speed of 1x or more.
 *
 * Each window is read without locks (a plain read) and its finished actions
 * are deleted by id, so the only row locks taken are on rows nothing else
 * touches: the engines update an action only while it has pending events.
 * The walk stops short of the newest actions (see ceiling()) and starts
 * again from the oldest a real hour after it gets there.
 */
class ActionsPrune
{
    /** Actions read a window (the next this many that exist) */
    public const BATCH = 2000;

    /** Real seconds a keeper call may spend */
    public const SECONDS = 15;

    /** Where the walk is: the next window starts at this id */
    public const CURSOR = 'actions-prune-from';

    /**
     * Once a pass reaches the ceiling, the next starts this many real seconds
     * later: what is left below it (the latest events of every kind keep
     * their actions in `events`) would be read again every minute for little
     */
    public const REST = 'actions-prune-rest';
    public const REST_SECONDS = 3600;

    /** house_rules.actions_keep_days: 30 with no house_rules row, 0 (off) before migration 0093 */
    public static function keepDays(): int
    {
        try {
            $days = DB::table('house_rules')->where('id', 1)->value('actions_keep_days');
        } catch (\Throwable $e) {
            return 0;
        }
        return max(0, (int) ($days ?? 30));
    }

    /**
     * On the keeper: deletes finished actions older than the keep, within
     * SECONDS: {deleted, read, batches, from (where the next call starts),
     * done (the walk reached the newest it may look at)}
     */
    public static function runIfDue(?int $seconds = null): array
    {
        $out = ['deleted' => 0, 'read' => 0, 'batches' => 0, 'from' => null, 'done' => false];
        $days = self::keepDays();
        if ($days <= 0 || Cache::has(self::REST)) {
            return $out;
        }
        $cutoff = World::gameNow() - $days * 86400;
        $ceiling = self::ceiling($cutoff);
        $held = self::held();
        $from = max((int) Cache::get(self::CURSOR, 0), (int) DB::table('actions')->min('id'));
        $until = microtime(true) + ($seconds ?? self::SECONDS);
        while (microtime(true) < $until) {
            if ($from >= $ceiling) {
                $out['done'] = true;
                $from = 0;   // the next pass starts again from the oldest
                Cache::put(self::REST, 1, self::REST_SECONDS);
                break;
            }
            $batch = self::pruneWindow($from, $ceiling, $cutoff, $days, $held);
            $out['deleted'] += $batch['deleted'];
            $out['read'] += $batch['read'];
            $out['batches']++;
            $from = $batch['next'];
        }
        Cache::forever(self::CURSOR, $from);
        $out['from'] = $from;
        if ($out['deleted'] > 0) {
            Log::info('actions prune: deleted ' . $out['deleted'] . ' finished actions older than ' . $days
                . ' game days, of ' . $out['read'] . ' read' . ($out['done'] ? ' (pass done)' : ', next from action ' . $from));
        }
        return $out;
    }

    /**
     * One window: the next BATCH actions from $from (not past $ceiling).
     * Deletes those finished before $cutoff: {deleted, read, next}
     */
    public static function pruneWindow(int $from, int $ceiling, int $cutoff, int $days, array $held = []): array
    {
        $hi = DB::table('actions')->where('id', '>=', $from)->orderBy('id')->offset(self::BATCH)->limit(1)->value('id');
        $hi = min($hi === null ? $ceiling : (int) $hi, $ceiling);
        $read = (int) DB::table('actions')->where('id', '>=', $from)->where('id', '<', $hi)->count();
        // A plain read: no locks on actions, events or the archive
        $ids = array_map(fn ($r) => (int) $r->id, DB::select(
            'SELECT x.id FROM actions AS x
             WHERE x.id >= ? AND x.id < ?
               AND NOT EXISTS (SELECT 1 FROM events AS e WHERE e.action = x.id)
               AND NOT EXISTS (SELECT 1 FROM tool_locks AS t WHERE t.action = x.id)
               AND NOT EXISTS (SELECT 1 FROM share_requests AS r WHERE r.action = x.id)
               AND IFNULL((SELECT MAX(a.game_t) FROM events_archive AS a WHERE a.action = x.id),
                          IF(x.timestamp IS NULL OR x.timestamp < NOW() - INTERVAL ? DAY, 0, ?)) < ?',
            [$from, $hi, $days, $cutoff, $cutoff]));
        $ids = array_values(array_filter($ids, fn ($id) => !isset($held[$id])));
        $deleted = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $deleted += DB::table('actions')->whereIn('id', $chunk)->delete();
        }
        return ['deleted' => $deleted, 'read' => $read, 'next' => $hi];
    }

    /**
     * Where a pass stops: the action of the first event at or after the
     * cutoff (by game time), or past the newest action if there is none.
     * Actions after it are newer than the cutoff, or nearly all are: a pass
     * leaves them for a later one, by when the cutoff has moved on
     */
    public static function ceiling(int $cutoff): int
    {
        $at = DB::selectOne('SELECT e.action FROM events AS e FORCE INDEX (events_game_t_idx)
            WHERE e.game_t >= ? AND e.action IS NOT NULL ORDER BY e.game_t LIMIT 1', [$cutoff]);
        return $at ? (int) $at->action : (int) DB::table('actions')->max('id') + 1;
    }

    /**
     * Actions something holds other than an event (id => true): drones
     * waiting for them and station offers not yet cleared. Small tables,
     * read once a call; an old finished action never becomes held again
     */
    public static function held(): array
    {
        $ids = DB::table('agents')->where('waiting_action', '>', 0)->pluck('waiting_action')
            ->concat(DB::table('station_offers')->whereNull('cleared_t')->pluck('action_id'));
        return array_fill_keys($ids->map(fn ($id) => (int) $id)->all(), true);
    }
}
