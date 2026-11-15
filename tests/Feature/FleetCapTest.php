<?php

namespace Tests\Feature;

use App\Services\Release;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The fleet cap (#682): a company may have at most house_rules.fleet_cap
 * working drones. The engine refuses a launch past it (-20); query 709,
 * behind fleet() and fleet_full(), tells a script before it buys a kit.
 */
class FleetCapTest extends TestCase
{
    use DatabaseTransactions;

    private const SCRIPT = "function launched() wait(60) end";

    /** Query 709 as the game data loads it */
    private function fleetSql(int $drone): string
    {
        $data = file_get_contents(base_path('game-engine/sql/game_data.sql'));
        $this->assertSame(1, preg_match("/\\(709, 'My fleet', '(?:[^']|'')*', 0, '((?:[^']|'')*)'\\)/", $data, $m), 'query 709 is in the game data');
        return str_replace('[drone_id]', (string) $drone, str_replace("''", "'", $m[1]));
    }

    private function fleet(int $drone): array
    {
        $row = DB::select($this->fleetSql($drone))[0];
        return [(int) $row->drones, (int) $row->cap, (int) $row->coming];
    }

    public function testMyFleetCountsWorkingDronesTheCapAndLaunchesOnTheirWay()
    {
        DB::table('house_rules')->updateOrInsert(['id' => 1], ['tax_period' => 0, 'tax_grace' => 0, 'tax_flat' => 0,
            'tax_permille' => 0, 'plunder_share' => 50, 'compute_tax_per_million' => 15, 'fleet_cap' => 8]);
        $agent = Release::release(Release::owner(), 'Fleet Cap Co', self::SCRIPT);
        $company = (int) $agent->company_id;
        $first = (int) $agent->agent_id;

        $this->assertSame([1, 8, 0], $this->fleet($first));

        // Two more drones, one of them a wreck: only working drones count
        foreach ([null, 1] as $wrecked) {
            $copy = (array) DB::table('agents')->where('agent_id', $first)->first();
            unset($copy['agent_id']);
            $copy['name'] = 'FC' . random_int(100000, 999999);
            $copy['wrecked_at'] = $wrecked;
            DB::table('agents')->insert($copy);
        }
        $this->assertSame([2, 8, 0], $this->fleet($first));

        // A launch performed but not yet arrived counts as coming
        DB::table('events')->insert(['event_type' => 8, 'action' => 0, 'logged' => 0, 'outcome' => 0, 'drone' => $first,
            'resource' => 0, 'installed' => 0, 'locked' => 0, 'new_quantity' => $first, 'game_t' => 0]);
        $this->assertSame([2, 8, 1], $this->fleet($first));

        // The House's own companies are never capped
        DB::table('companies')->where('company_id', $company)->update(['house' => 1]);
        $this->assertSame(0, $this->fleet($first)[1]);
    }

    public function testTheMigrationScrapsTheNewestDronesPastTheCap()
    {
        $sql = file_get_contents(base_path('game-engine/sql/migrations/0064_fleet_cap.sql'));
        $this->assertStringContainsString("wreck_cause = 'fleet cap'", $sql);
        $this->assertStringContainsString('ROW_NUMBER() OVER (PARTITION BY a.company_id ORDER BY a.agent_id)', $sql,
            'the oldest drones are kept');
        $this->assertStringContainsString('c.house = 0', $sql, 'the House is never capped');
    }
}
