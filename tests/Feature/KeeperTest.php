<?php

namespace Tests\Feature;

use App\Http\Controllers\WorldController;
use App\Services\Dashboard;
use App\Services\Kept;
use App\Services\World;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The kept pages are rebuilt by the scheduler's keeper (#738): until round 71
 * a view started the rebuild with defer(), which this app's kernel never
 * runs, so the dashboard and the stock exchange froze at their first build.
 */
class KeeperTest extends TestCase
{
    use DatabaseTransactions;

    public function testTheKeeperRebuildsWhatIsOldAndLeavesWhatIsFresh()
    {
        Cache::flush();
        Dashboard::compact();
        Dashboard::full();
        WorldController::sharesPage();
        $this->assertSame(['dashboard:compact' => 'fresh', 'dashboard:full' => 'fresh', WorldController::sharesKey() => 'fresh'], Kept::keepAll());

        // Two minutes on, all three are rebuilt
        foreach (array_keys(Kept::jobs()) as $key) {
            $kept = Cache::get($key);
            Cache::forever($key, ['data' => ['old'], 'at' => $kept['at'] - 3600]);
        }
        $this->assertSame(['old'], Dashboard::compact(), 'a view serves the kept copy');
        $done = Kept::keepAll();
        foreach ($done as $key => $took) {
            $this->assertIsFloat($took, "$key was rebuilt");
        }
        $this->assertArrayHasKey('tiles', Dashboard::compact());
        $this->assertArrayHasKey('exchange', WorldController::sharesPage());
    }

    public function testTheKeepRouteNeedsTheKey()
    {
        $this->get('/keep/wrong')->assertStatus(403);
        config(['app.cron_key' => 'k738']);
        $this->getJson('/keep/k738')->assertOk()->assertJsonStructure(['dashboard:compact', 'dashboard:full']);
    }

    public function testOldFiguresSayHowOldTheyAre()
    {
        $this->assertNull(Dashboard::staleNote(World::gameNow() - 600));
        $this->assertStringContainsString('3 game days old', Dashboard::staleNote(World::gameNow() - 3 * 86400 - 5));

        config(['forge.new_rules' => true]);
        Cache::forever('dashboard:compact', ['data' => array_merge(Dashboard::summaryNow(), ['as_of_t' => World::gameNow() - 5 * 86400]), 'at' => time()]);
        $this->get('/')->assertOk()->assertSee('id="economy-stale"', false)->assertSee('5 game days old');
    }
}
