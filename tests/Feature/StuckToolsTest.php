<?php

namespace Tests\Feature;

use App\Services\Release;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tools left locked by aborted actions (#711, #712): migration 0067 gives
 * back each locked holding that no pending event will unlock, and leaves a
 * lock alone while its action is still running.
 */
class StuckToolsTest extends TestCase
{
    use DatabaseTransactions;

    private const SMELTER = 5201;

    private function event(int $drone, int $type, int $locked, int $quantity, int $outcome, ?int $action = null): void
    {
        DB::table('events')->insert(['event_type' => $type, 'action' => $action, 'logged' => 1, 'outcome' => $outcome,
            'drone' => $drone, 'resource' => self::SMELTER, 'installed' => 0, 'locked' => $locked,
            'new_quantity' => $quantity, 'game_t' => 1000]);
    }

    private function smelters(int $drone): array
    {
        return DB::table('holdings')->where('drone', $drone)->where('resource', self::SMELTER)
            ->orderBy('locked')->pluck('units', 'locked')->map(fn ($u) => (int) $u)->all();
    }

    private function migrate(): void
    {
        DB::unprepared(file_get_contents(base_path('game-engine/sql/migrations/0067_free_stuck_tools.sql')));
    }

    public function testALockNoActionWillUndoIsGivenBack()
    {
        $drone = (int) Release::release(Release::owner(), 'Stuck Smelter Co', 'function launched() wait(60) end')->agent_id;
        // A make locked the Smelter (free 0, locked 1) and was aborted before unlocking it
        $this->event($drone, 2, 0, 0, 1);
        $this->event($drone, 1, 1, 1, 1);
        $this->assertSame([0 => 0, 1 => 1], $this->smelters($drone));

        $this->migrate();
        $this->assertSame([0 => 1, 1 => 0], $this->smelters($drone), 'the Smelter is free again');

        $this->migrate();
        $this->assertSame([0 => 1, 1 => 0], $this->smelters($drone), 'a second run changes nothing');
    }

    public function testALockItsRunningActionWillUndoIsLeftAlone()
    {
        $drone = (int) Release::release(Release::owner(), 'Busy Smelter Co', 'function launched() wait(60) end')->agent_id;
        $action = DB::table('actions')->insertGetId(['drone' => $drone, 'protocol' => 12021, 'multiplier' => 1, 'aborted' => 0]);
        $this->event($drone, 2, 0, 0, 1, $action);
        $this->event($drone, 1, 1, 1, 1, $action);
        $this->event($drone, 2, 1, 1, 0, $action);   // the unlock still to come

        $this->migrate();
        $this->assertSame([0 => 0, 1 => 1], $this->smelters($drone), 'the running make keeps its lock');
    }
}
