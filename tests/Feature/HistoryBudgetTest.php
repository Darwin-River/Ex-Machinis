<?php

namespace Tests\Feature;

use App\Services\EventHistory;
use App\Services\HistoryBudget;
use App\Services\World;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The game's history within a disk budget (#813) */
class HistoryBudgetTest extends TestCase
{
    use DatabaseTransactions;

    /** Archived events for a drone, oldest first: their ids */
    private function archive(int $drone, int $n, int $from): array
    {
        $base = (int) DB::table('events_archive')->max('id') + (int) DB::table('events')->max('id') + 1000;
        $ids = [];
        for ($i = 0; $i < $n; $i++) {
            $id = $base + $i;
            DB::table('events_archive')->insert(['id' => $id, 'event_type' => 1, 'action' => 900000 + intdiv($i, 2),
                'outcome' => $i % 5 === 0 ? -2 : 1, 'drone' => $drone, 'resource' => 1, 'game_t' => $from + $i * 3600]);
            DB::table('observations')->insert(['drone' => $drone, 'event' => $id, 'game_t' => $from + $i * 3600]);
            $ids[] = $id;
        }
        return $ids;
    }

    private function disk(float $effective, float $used = null): array
    {
        return ['total' => 100 * 1024 ** 3, 'free' => 0, 'used_percent' => $used ?? $effective, 'held_free' => 0, 'effective_percent' => $effective];
    }

    public function testItPrunesTheOldestArchivedHistoryIntoDailySummariesOnlyWhenOverBudget()
    {
        config(['forge.history_budget_percent' => 70]);
        $drone = (int) DB::table('agents')->value('agent_id');
        $epoch = (int) DB::table('game_clock')->where('id', 1)->value('epoch');
        $ids = $this->archive($drone, 30, $epoch + 2 * 86400);

        // Under budget: nothing goes
        $r = HistoryBudget::pruneIfDue($this->disk(60));
        $this->assertSame(0, $r['pruned']);
        $this->assertSame(30, DB::table('events_archive')->whereIn('id', $ids)->count());

        // A batch: the oldest go, with their observations, summed by drone and game day
        $b = HistoryBudget::pruneBatch(30);
        $this->assertSame(30, $b['events']);
        $this->assertGreaterThanOrEqual(30, $b['observations']);
        $this->assertSame(0, DB::table('events_archive')->whereIn('id', $ids)->count());
        $this->assertSame(0, DB::table('observations')->whereIn('event', $ids)->count());
        $days = DB::table('drone_days')->where('drone', $drone)->orderBy('day')->get();
        $this->assertSame(30, (int) $days->sum('events'));
        $this->assertSame(6, (int) $days->sum('failed'), 'every fifth failed');
        $this->assertSame(2, (int) $days->first()->day, 'game days since the world began');
        // A hull's age still knows when the drone first appeared
        $this->assertLessThanOrEqual($epoch + 2 * 86400, EventHistory::firstGameT($drone));

        // Over budget: it prunes about the excess, at most what there is
        $more = $this->archive($drone, 10, $epoch + 3 * 86400);
        $r = HistoryBudget::pruneIfDue($this->disk(95));
        $this->assertGreaterThan(0, $r['pruned']);
        $this->assertSame(0, DB::table('events_archive')->whereIn('id', $more)->count());

        // Off at 0
        config(['forge.history_budget_percent' => 0]);
        $this->archive($drone, 3, $epoch + 4 * 86400);
        $this->assertSame(0, HistoryBudget::pruneIfDue($this->disk(99))['pruned']);
    }

    public function testTheClockSaysHowFullTheDiskIs()
    {
        $this->getJson('/api/v1/clock')->assertOk()->assertJsonStructure(['disk' => ['used_percent', 'effective_percent', 'budget_percent', 'warning']]);
        $d = HistoryBudget::disk();
        $this->assertNotNull($d);
        $this->assertLessThanOrEqual($d['used_percent'], $d['effective_percent']);
    }

    /** #827: free space only `events` can use isn't room; a table with far more free space than rows is rebuilt */
    public function testOnlyTheGrowingTablesFreeSpaceCountsAndASparseTableIsRebuilt()
    {
        $gb = 1_000_000_000;
        // As live after the archiver caught up: events 0.3 GB of rows and 11 GB free
        $tables = ['events' => ['rows' => (int) (0.3 * $gb), 'free' => 11 * $gb],
            'events_archive' => ['rows' => 2 * $gb, 'free' => (int) (0.2 * $gb)],
            'observations' => ['rows' => (int) (1.2 * $gb), 'free' => (int) (1.7 * $gb)]];
        $this->assertSame((int) (1.9 * $gb), HistoryBudget::heldFree($tables), 'not the 11 GB in events');
        $this->assertSame(['events', 'observations'], array_keys(HistoryBudget::rebuildable($tables)));
        // Less than a gigabyte free, or less free than rows: not worth it
        $this->assertSame([], HistoryBudget::rebuildable(['events' => ['rows' => 5 * $gb, 'free' => 4 * $gb],
            'observations' => ['rows' => 100, 'free' => (int) (0.9 * $gb)]]));
        $this->getJson('/api/v1/clock')->assertOk()->assertJsonStructure(['disk' => ['rebuild']]);

        // The keeper rebuilds what is small enough itself, once a real day; the rest it only logs
        \Illuminate\Support\Facades\Cache::forget('history-rebuild');
        $tables['observations']['rows'] = 2 * $gb;   // too big to copy in a moment
        $tables['observations']['free'] = 3 * $gb;
        \Illuminate\Support\Facades\Log::spy();
        $done = HistoryBudget::rebuildIfDue($tables);
        $this->assertSame(['events'], array_keys($done));
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains($m, 'observations')
            && str_contains($m, 'forge:history-rebuild'));
        $this->assertSame([], HistoryBudget::rebuildIfDue($tables), 'once a real day');

        // The command lists them, and rebuilds one asked for by name
        $this->artisan('forge:history-rebuild', ['--dry-run' => true])->assertExitCode(0);
        $this->artisan('forge:history-rebuild', ['--table' => ['events']])->expectsOutputToContain('Rebuilt events')->assertExitCode(0);
    }
}
