<?php

namespace Tests\Feature;

use App\Agent;
use App\Company;
use App\Services\LaunchQueue;
use App\Services\Release;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The clock floor and the launch queue (#324 step 20): when the game engine
 * marks the world full, releases wait in line and launch in order once
 * there is room; the Home page shows the clock.
 */
class LaunchQueueTest extends TestCase
{
    use DatabaseTransactions;

    private const SCRIPT = ": launched 1 2 + drop ;";

    protected function setUp(): void
    {
        parent::setUp();
    }

    /** The game clock as the game engine leaves it after a load check */
    private function clock(bool $full, int $checkedAgo = 10, float $scale = 8): void
    {
        $now = time();
        DB::table('game_clock')->updateOrInsert(['id' => 1], [
            'real_anchor' => $now, 'game_anchor' => $now, 'scale' => $scale, 'epoch' => $now - 86400,
            'target_scale' => 24, 'floor_scale' => 8, 'lag_seconds' => $full ? 120 : 3,
            'slices_per_minute' => 900, 'is_full' => $full ? 1 : 0, 'load_checked_at' => $now - $checkedAgo,
        ]);
    }

    public function testReleaseLaunchesAtOnceWhenTheWorldIsNotFull()
    {
        $this->clock(false);
        $response = $this->post('/release', ['company_name' => 'Room Enough', 'script' => self::SCRIPT]);
        $company = Company::where('name', 'Room Enough')->first();
        $this->assertNotNull($company);
        $response->assertRedirect('/spacecraft/' . Agent::where('company_id', $company->company_id)->value('agent_id'))
            ->assertSessionHas('success');
        $this->assertSame(0, LaunchQueue::waiting());
    }

    public function testReleaseWaitsInLineWhenTheWorldIsFull()
    {
        $this->clock(true);
        $this->post('/release', ['company_name' => 'First Waiting', 'script' => self::SCRIPT])
            ->assertRedirect('/release');
        $this->assertStringContainsString('number 1 in line', session('success'));
        $this->post('/release', ['company_name' => 'Second Waiting', 'script' => self::SCRIPT]);
        $this->assertStringContainsString('number 2 in line', session('success'));

        $this->assertFalse(Company::where('name', 'First Waiting')->exists(), 'nothing launches while full');
        $this->assertSame(2, LaunchQueue::waiting());
        $this->get('/release')->assertOk()
            ->assertSee('The world is full right now')
            ->assertSee('2 release(s) waiting to launch');

        // A waiting release keeps its company name
        $this->from('/release')
            ->post('/release', ['company_name' => 'First Waiting', 'script' => self::SCRIPT])
            ->assertSessionHasErrors('company_name');

        // Still full: nothing launches
        $this->assertSame(0, LaunchQueue::drain());

        // Room again: they launch in order, a few at a time
        $this->clock(false);
        $this->assertSame(2, LaunchQueue::drain());
        $this->assertSame(0, LaunchQueue::waiting());
        $company = Company::where('name', 'First Waiting')->first();
        $this->assertNotNull($company);
        $this->assertSame(Release::owner()->user_id, (int) $company->user_id);
        $agent = Agent::where('company_id', $company->company_id)->first();
        $this->assertSame($agent->agent_id, (int) DB::table('launch_queue')->where('company_name', 'First Waiting')->value('agent_id'));
        $this->assertTrue(Company::where('name', 'Second Waiting')->exists());
    }

    public function testAStaleCheckIsNotFull()
    {
        // The engine stopped checking: don't hold releases back on old news
        $this->clock(true, LaunchQueue::STALE_SECONDS + 60);
        $this->assertFalse(LaunchQueue::full());
        $this->post('/release', ['company_name' => 'Stale Check', 'script' => self::SCRIPT]);
        $this->assertTrue(Company::where('name', 'Stale Check')->exists());
    }

    public function testTheSchedulerDrainsTheQueue()
    {
        $this->clock(true);
        $this->post('/release', ['company_name' => 'Drained Later', 'script' => self::SCRIPT]);
        $this->clock(false);
        config(['app.cron_key' => 'test-cron-key']);
        $this->get('/tick/test-cron-key')->assertOk();
        $this->assertTrue(Company::where('name', 'Drained Later')->exists());
    }

    public function testHomeShowsTheGameClock()
    {
        $this->clock(true, 10, 8);
        $this->get('/')->assertOk()
            ->assertSee('one real hour is 8 game hours')
            ->assertSee('never below')
            ->assertSee('The world is full');
    }
}
