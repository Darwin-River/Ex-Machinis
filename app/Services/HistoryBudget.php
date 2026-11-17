<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The game's history within a disk budget (#813). The live disk filled with
 * event history and took the site down (#811): archiving (#694) only moves
 * events between two tables on the same disk. So, once a real minute on the
 * keeper, whenever the disk's effective use is over
 * forge.history_budget_percent (60, #821; 70 before), this deletes the oldest archived events
 * and their observations, a batch at a time, after summing each drone's
 * into drone_days (one row a game day).
 *
 * Effective use is the disk's used space less the free space MySQL already
 * holds inside the history tables: InnoDB keeps a deleted row's space for
 * new rows instead of handing it back, so the disk's own figure doesn't fall
 * after a prune, but the history stops growing until that space is used.
 * Pruning only events_archive leaves the game as it was: the engines and
 * drones' queries (803, 804) read events alone.
 */
class HistoryBudget
{
    /** Archived events pruned in a batch */
    public const BATCH = 5000;

    /** Real seconds a keeper call may spend pruning, and above URGENT_PERCENT */
    public const SECONDS = 15;
    public const URGENT_SECONDS = 40;

    /** Above this disk use the prune works harder (and the clock API warns) */
    public const URGENT_PERCENT = 80;

    /** The history tables whose free space counts as room */
    public const TABLES = ['events', 'events_archive', 'observations'];

    public static function budget(): int
    {
        return max(0, min(100, (int) config('forge.history_budget_percent', 60)));
    }

    /**
     * The disk the database is on, as the website sees it (the containers
     * share the server's disk): {total, free, used_percent, held_free,
     * effective_percent}, in bytes and percent; null when it can't tell
     */
    public static function disk(?string $path = null): ?array
    {
        $path ??= (string) config('forge.history_disk_path', base_path());
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);
        if (!$total || $free === false) {
            return null;
        }
        $held = self::heldFree();
        $used = $total - $free;
        return ['total' => (int) $total, 'free' => (int) $free, 'used_percent' => round(100 * $used / $total, 1),
            'held_free' => $held, 'effective_percent' => round(100 * max(0, $used - $held) / $total, 1)];
    }

    /** Free space MySQL holds inside the history tables, in bytes (fresh, not the cached statistics) */
    public static function heldFree(): int
    {
        try {
            DB::statement('SET SESSION information_schema_stats_expiry = 0');
        } catch (\Throwable $e) {
            // MySQL 5.7 has no such setting: its figures are always fresh
        }
        return (int) DB::table('information_schema.tables')->whereRaw('table_schema = DATABASE()')
            ->whereIn('table_name', self::TABLES)->sum('data_free');
    }

    /**
     * Prunes while the disk is over budget, within the time it may take:
     * {disk (before), pruned events, pruned observations, batches, over}
     * (over: still over budget when it stopped). $disk: the disk as
     * disk() says, for tests
     */
    public static function pruneIfDue(?array $disk = null): array
    {
        $out = ['pruned' => 0, 'observations' => 0, 'batches' => 0, 'over' => false];
        if (self::budget() <= 0 || !Schema::hasTable('drone_days')) {
            return $out;
        }
        $disk ??= self::disk();
        $out['disk'] = $disk;
        if ($disk === null || $disk['effective_percent'] <= self::budget()) {
            return $out;
        }
        $urgent = $disk['used_percent'] >= self::URGENT_PERCENT;
        $until = microtime(true) + ($urgent ? self::URGENT_SECONDS : self::SECONDS);
        // How many rows to take off, from what each row takes: so it stops
        // near the budget even before MySQL reports the space as free
        $excess = ($disk['effective_percent'] - self::budget()) / 100 * $disk['total'];
        $rowsWanted = (int) ceil($excess / max(1, self::bytesPerEvent()));
        while (microtime(true) < $until && $out['pruned'] < $rowsWanted) {
            $batch = self::pruneBatch(min(self::BATCH, $rowsWanted - $out['pruned']));
            if ($batch['events'] === 0) {
                break;   // nothing archived left: what's over is the live game's (the archiver must catch up)
            }
            $out['pruned'] += $batch['events'];
            $out['observations'] += $batch['observations'];
            $out['batches']++;
        }
        $out['over'] = $out['pruned'] < $rowsWanted;
        if ($out['pruned'] > 0 || $out['over']) {
            Log::info('history budget: disk ' . $disk['used_percent'] . '% used (' . $disk['effective_percent']
                . '% counting MySQL\'s free space), budget ' . self::budget() . '%: pruned ' . $out['pruned']
                . ' archived events and ' . $out['observations'] . ' observations' . ($out['over'] ? ', still over' : ''));
        }
        return $out;
    }

    /**
     * Deletes the oldest $n archived events and their observations, after
     * summing them into drone_days: {events, observations}
     */
    public static function pruneBatch(int $n): array
    {
        $ids = DB::table('events_archive')->orderBy('id')->limit(max(1, $n))->pluck('id')->all();
        if (!$ids) {
            return ['events' => 0, 'observations' => 0];
        }
        $epoch = (int) DB::table('game_clock')->where('id', 1)->value('epoch');
        return DB::transaction(function () use ($ids, $epoch) {
            $lo = min($ids);
            $hi = max($ids);
            // One row a drone a game day: how many events, how many failed, from how many actions, and when
            DB::statement('INSERT INTO drone_days (drone, day, events, failed, actions, first_t, last_t)
                SELECT * FROM (SELECT x.drone, x.day, COUNT(*) AS n, SUM(x.outcome < 0) AS bad, COUNT(DISTINCT x.action) AS acts,
                    MIN(x.game_t) AS lo, MAX(x.game_t) AS hi
                FROM (SELECT drone, FLOOR((game_t - ?) / 86400) AS day, outcome, action, game_t FROM events_archive
                      WHERE id BETWEEN ? AND ? AND drone IS NOT NULL AND game_t IS NOT NULL) x
                GROUP BY x.drone, x.day) AS s
                ON DUPLICATE KEY UPDATE events = events + s.n, failed = failed + s.bad, actions = actions + s.acts,
                    first_t = LEAST(IFNULL(first_t, s.lo), s.lo), last_t = GREATEST(IFNULL(last_t, s.hi), s.hi)', [$epoch, $lo, $hi]);
            $obs = DB::table('observations')->whereIn('event', $ids)->delete();
            $events = DB::table('events_archive')->whereBetween('id', [$lo, $hi])->delete();
            return ['events' => $events, 'observations' => $obs];
        });
    }

    /** Bytes an archived event takes with its observations, from the tables' sizes (700 if they can't say) */
    public static function bytesPerEvent(): int
    {
        $rows = DB::table('information_schema.tables')->whereRaw('table_schema = DATABASE()')
            ->whereIn('table_name', ['events_archive', 'observations'])
            ->get(['table_name', 'table_rows', 'data_length', 'index_length'])->keyBy('table_name');
        $archive = $rows['events_archive'] ?? null;
        $obs = $rows['observations'] ?? null;
        if (!$archive || (int) $archive->table_rows <= 0) {
            return 700;
        }
        $perEvent = ((int) $archive->data_length + (int) $archive->index_length) / (int) $archive->table_rows;
        // Observations belong to events in both tables: count their share
        $events = (int) $archive->table_rows + (int) DB::table('information_schema.tables')->whereRaw('table_schema = DATABASE()')
            ->where('table_name', 'events')->value('table_rows');
        if ($obs && $events > 0) {
            $perEvent += ((int) $obs->data_length + (int) $obs->index_length) / $events;
        }
        return max(100, (int) round($perEvent));
    }
}
