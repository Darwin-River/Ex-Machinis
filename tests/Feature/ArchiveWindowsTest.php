<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The archiver's windows are the next events that exist, not a range of
 * ids (#818): after a restart a pass starts from the oldest event, and
 * through ids already archived a window of ids held almost nothing
 */
class ArchiveWindowsTest extends TestCase
{
    public function testAWindowReachesAcrossIdsAlreadyArchived()
    {
        // No transaction around it: the procedure moves rows itself, so clean up by hand
        $drone = (int) DB::table('agents')->value('agent_id');
        // The procedure's clock: the game's, or the real time with none (as it falls back)
        $clock = DB::selectOne('SELECT ROUND(game_anchor + (UNIX_TIMESTAMP() - real_anchor) * scale) AS t FROM game_clock WHERE id = 1');
        $now = $clock ? (int) $clock->t : time();
        $old = $now - 60 * 86400;
        $base = (int) DB::table('events')->max('id') + 100000;
        $ids = [];
        try {
            // An old move (the drone's oldest event), then a gap of 50,000 ids
            // (archived long ago), then old moves with a newer one after them
            $rows = [[$base, $old]];
            for ($i = 0; $i < 10; $i++) {
                $rows[] = [$base + 50000 + $i, $old + 3600 * ($i + 1)];
            }
            $rows[] = [$base + 60000, $now - 3600];   // the drone's latest: stays
            foreach ($rows as [$id, $t]) {
                DB::table('events')->insert(['id' => $id, 'event_type' => 3, 'action' => null, 'logged' => 1, 'outcome' => 1,
                    'drone' => $drone, 'new_location' => 3, 'game_t' => $t]);
                $ids[] = $id;
            }
            DB::statement('SET @events_archiving = 0');
            DB::statement('CALL archive_events(?, 2000, ?, @moved, @next)', [$base, 30 * 86400]);
            $moved = (int) DB::selectOne('SELECT @moved AS m')->m;
            // A window of 2,000 ids from the oldest would have held one row
            // The oldest and nine beyond the gap go; the drone's latest event at
            // the cutoff (the engines look it up) and its latest of all stay
            $this->assertSame(10, $moved, 'one window moves the old events beyond the gap');
            $this->assertSame([$base + 50009, $base + 60000],
                DB::table('events')->whereIn('id', $ids)->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all());
            $this->assertSame(10, DB::table('events_archive')->whereIn('id', $ids)->count());
        } finally {
            DB::table('events')->whereIn('id', $ids)->delete();
            DB::table('events_archive')->whereIn('id', $ids)->delete();
        }
    }
}
