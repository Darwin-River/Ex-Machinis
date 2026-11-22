<?php

namespace Tests\Feature;

use App\Services\ActionsPrune;
use App\Services\DronePage;
use App\Services\HistoryBudget;
use App\Services\World;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Finished actions go after 30 game days (#944) */
class ActionsPruneTest extends TestCase
{
    use DatabaseTransactions;

    private int $drone;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(ActionsPrune::CURSOR);
        Cache::forget(ActionsPrune::REST);
        $this->drone = (int) DB::table('agents')->value('agent_id');
    }

    /** An action of the drone's, with one event at $gameT: in events_archive (settled), or in events at $outcome */
    private function action(int $gameT, string $table = 'events_archive', int $outcome = 1): int
    {
        $id = DB::table('actions')->insertGetId(['drone' => $this->drone, 'protocol' => 3, 'multiplier' => 1, 'aborted' => 0]);
        $row = ['event_type' => 1, 'action' => $id, 'logged' => 1, 'outcome' => $outcome, 'drone' => $this->drone,
            'resource' => 1, 'installed' => 0, 'locked' => 0, 'new_quantity' => 5, 'game_t' => $gameT];
        if ($table === 'events_archive') {
            $row['id'] = (int) DB::table('events')->max('id') + (int) DB::table('events_archive')->max('id') + 1000;
        }
        DB::table($table)->insert($row);
        return $id;
    }

    private function exists(int $id): bool
    {
        return DB::table('actions')->where('id', $id)->exists();
    }

    public function testOldFinishedActionsGoAndEverythingStillInUseStays()
    {
        $now = World::gameNow();
        $old = $now - 40 * 86400;
        $oldDone = $this->action($old);
        $recent = $this->action($now - 10 * 86400);
        $pending = $this->action($old, 'events', 0);
        $settledButLive = $this->action($old, 'events', 1);   // the latest of its kind: the archiver kept it
        $locked = $this->action($old);
        DB::table('tool_locks')->insert(['drone' => $this->drone, 'resource' => 1, 'installed' => 0, 'units' => 1, 'action' => $locked]);
        $waited = $this->action($old);
        DB::table('agents')->where('agent_id', $this->drone)->update(['waiting_action' => $waited]);
        $shares = $this->action($old);
        DB::table('share_requests')->insert(['action' => $shares, 'company' => 1, 'drone' => $this->drone, 'kind' => 3,
            'share_company' => 1, 'units' => 1, 'placed_t' => $old]);
        // No events left anywhere (its history pruned): by its row's real age
        $bare = DB::table('actions')->insertGetId(['drone' => $this->drone, 'protocol' => 3, 'multiplier' => 1, 'aborted' => 0,
            'timestamp' => now()->subDays(40)]);
        $bareNew = DB::table('actions')->insertGetId(['drone' => $this->drone, 'protocol' => 3, 'multiplier' => 1, 'aborted' => 0]);
        // The pass's end: an action with an event now
        $this->action($now, 'events', 0);

        $r = ActionsPrune::runIfDue(30);
        $this->assertTrue($r['done'], 'one call walks this small table');
        $this->assertFalse($this->exists($oldDone), 'an old finished action is deleted');
        $this->assertFalse($this->exists($bare), 'and one whose events are all gone, once its row is as old');
        $this->assertTrue($this->exists($recent), 'a recent one is kept');
        $this->assertTrue($this->exists($pending), 'one with a pending event is kept');
        $this->assertTrue($this->exists($settledButLive), 'one with an event still in events is kept');
        $this->assertTrue($this->exists($locked), 'one holding a tool lock is kept');
        $this->assertTrue($this->exists($waited), 'one a drone waits for is kept');
        $this->assertTrue($this->exists($shares), 'one with a share request on its way is kept');
        $this->assertTrue($this->exists($bareNew), 'a new action with no events yet is kept');
        $this->assertGreaterThanOrEqual(2, $r['deleted']);

        // Its archived events stay, and the drone's pages still work without it
        $this->assertTrue(DB::table('events_archive')->where('action', $oldDone)->exists());
        $agent = \App\Agent::where('agent_id', $this->drone)->first();
        $this->assertIsArray(DronePage::timeline($this->drone, 50));
        $this->assertNotContains($oldDone, array_column(DronePage::timeline($this->drone, 50), 'action'));
        $this->get('/spacecraft/' . $this->drone)->assertOk();

        // A pass done, the next waits a real hour; then the recent one goes once it is old enough
        DB::table('events_archive')->where('action', $recent)->update(['game_t' => $old]);
        $this->assertSame(0, ActionsPrune::runIfDue(30)['batches'], 'resting');
        $this->assertTrue($this->exists($recent));
        Cache::forget(ActionsPrune::REST);
        ActionsPrune::runIfDue(30);
        $this->assertFalse($this->exists($recent));
    }

    public function testTheHouseRuleAtZeroTurnsItOff()
    {
        $old = $this->action(World::gameNow() - 400 * 86400);
        DB::table('house_rules')->insert(['id' => 1, 'tax_period' => 86400, 'tax_grace' => 0, 'tax_flat' => 0,
            'tax_permille' => 0, 'plunder_share' => 0, 'actions_keep_days' => 0]);
        $this->assertSame(0, ActionsPrune::keepDays());
        $r = ActionsPrune::runIfDue(30);
        $this->assertSame(0, $r['deleted']);
        $this->assertSame(0, $r['batches']);
        $this->assertTrue($this->exists($old));

        // A longer keep: what is younger than it stays
        DB::table('house_rules')->where('id', 1)->update(['actions_keep_days' => 500]);
        ActionsPrune::runIfDue(30);
        $this->assertTrue($this->exists($old));
        DB::table('house_rules')->where('id', 1)->update(['actions_keep_days' => 300]);
        Cache::forget(ActionsPrune::REST);
        ActionsPrune::runIfDue(30);
        $this->assertFalse($this->exists($old));
    }

    public function testTheKeeperRunsIt()
    {
        // The history budget would prune the archived event first on a full disk
        config(['app.cron_key' => 'k944', 'forge.history_budget_percent' => 0]);
        $old = $this->action(World::gameNow() - 40 * 86400);
        $this->getJson('/keep/k944')->assertOk()->assertJsonStructure(['actions_pruned' => ['deleted', 'read', 'batches', 'done']]);
        $this->assertFalse($this->exists($old));
    }

    public function testAWrecksPageSaysItsActionsWereCleared()
    {
        $this->drone = (int) \App\Services\Release::release(\App\Services\Release::owner(), 'Long Gone Co', 'function launched() wait(60) end')->agent_id;
        $old = $this->action(World::gameNow() - 40 * 86400);
        DB::table('agents')->where('agent_id', $this->drone)->update(['wrecked_at' => World::gameNow() - 35 * 86400, 'wreck_cause' => 'fuel']);
        $this->get('/spacecraft/' . $this->drone)->assertOk()->assertDontSee('no longer kept');
        ActionsPrune::runIfDue(30);
        $this->assertFalse($this->exists($old));
        $this->get('/spacecraft/' . $this->drone)->assertOk()->assertSee('Its actions are no longer kept');
    }

    public function testActionsCanBeRebuiltOnceItsBacklogIsGone()
    {
        $gb = 1_000_000_000;
        $this->assertContains('actions', HistoryBudget::TABLES);
        $this->assertSame(['actions'], array_keys(HistoryBudget::rebuildable(['actions' => ['rows' => $gb / 10, 'free' => 3 * $gb]])));
    }
}
