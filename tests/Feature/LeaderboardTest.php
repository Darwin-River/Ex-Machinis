<?php

namespace Tests\Feature;

use App\Services\Leaderboard;
use App\Services\Release;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The leaderboard (#324 step 18; companies since #367 step 2): companies by
 * net wealth, their launched drones included, all time and Rising over the
 * last real week.
 */
class LeaderboardTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['forge.email' => false, 'forge.invite_only' => true]);
        DB::table('wealth_snapshots')->delete();
    }

    private function player(string $email, string $name): User
    {
        return User::create(['name' => $name, 'email' => $email, 'password' => bcrypt('secret-password')]);
    }

    public function testANewReleaseIsWorthNothingAndWealthIsWhatYouMake()
    {
        $user = $this->player('a@example.com', 'Ada');
        $agent = Release::release($user, 'Ada Mining', ': launched ;');
        $this->assertSame(0, Leaderboard::wealth()[$agent->company_id]['wealth'], 'a fresh release nets 0');
        $this->assertSame($agent->agent_id, Leaderboard::wealth()[$agent->company_id]['drone'], 'it stands for its company');

        DB::table('companies')->where('company_id', $agent->company_id)->increment('credits', 500);
        $this->assertSame(500, Leaderboard::wealth()[$agent->company_id]['wealth']);

        // Goods count at the House price (what a station pays)
        $resource = (int) DB::table('resources')->value('id');
        DB::table('stations')->insert(['id' => 902, 'name' => 'Board Depot', 'object_id' => 3, 'budget_cap' => 5000, 'refill_per_day' => 5000]);
        DB::table('station_goods')->insert(['station_id' => 902, 'resource' => $resource, 'side' => 1, 'price' => 7, 'daily_units' => 100, 'from_day' => 0]);
        DB::table('events')->insert(['event_type' => 1, 'action' => 0, 'logged' => 1, 'outcome' => 1, 'drone' => $agent->agent_id,
            'resource' => $resource, 'installed' => 0, 'locked' => 0, 'new_quantity' => 10, 'new_cargo' => 10]);
        $this->assertSame(570, Leaderboard::wealth()[$agent->company_id]['wealth']);

        // A clone's goods count toward the drone that started the company
        $clone = DB::table('agents')->insertGetId(['user_id' => $user->user_id, 'company_id' => $agent->company_id,
            'name' => 'Ada Clone', 'object_id' => 3, 'hull_type' => 1, 'parent_id' => $agent->agent_id, 'generation' => 1]);
        DB::table('events')->insert(['event_type' => 1, 'action' => 0, 'logged' => 1, 'outcome' => 1, 'drone' => $clone,
            'resource' => $resource, 'installed' => 0, 'locked' => 0, 'new_quantity' => 5, 'new_cargo' => 5]);
        $this->assertSame(605, Leaderboard::wealth()[$agent->company_id]['wealth']);
        $this->assertSame(2, Leaderboard::wealth()[$agent->company_id]['drones']);

        // A wreck's goods are not the company's any more
        DB::table('agents')->where('agent_id', $clone)->update(['wrecked_at' => 1, 'wreck_cause' => 'fuel']);
        $this->assertSame(570, Leaderboard::wealth()[$agent->company_id]['wealth']);
    }

    public function testRisingIsTheGainOverTheLastWeek()
    {
        $old = $this->player('old@example.com', 'Old Timer');
        $agent = Release::release($old, 'Old Co', ': launched ;');
        DB::table('companies')->where('company_id', $agent->company_id)->increment('credits', 300);
        DB::table('wealth_snapshots')->insert(['company_id' => $agent->company_id, 'taken_at' => now()->subDays(8), 'wealth' => 200]);
        $new = $this->player('new@example.com', 'Newcomer');
        $newAgent = Release::release($new, 'New Co', ': launched ;');
        DB::table('companies')->where('company_id', $newAgent->company_id)->increment('credits', 150);

        $rising = collect(Leaderboard::rising())->keyBy('company_id');
        $this->assertSame(100, $rising[$agent->company_id]['gain'], 'gain since the snapshot a week ago');
        $this->assertSame(150, $rising[$newAgent->company_id]['gain'], 'a newcomer counts from 0');
    }

    public function testSnapshotsAreHourly()
    {
        Release::release($this->player('a@example.com', 'Ada'), 'Ada Mining', ': launched ;');
        $this->assertTrue(Leaderboard::snapshotIfDue());
        $this->assertFalse(Leaderboard::snapshotIfDue(), 'not again within the hour');
        $this->assertGreaterThan(0, DB::table('wealth_snapshots')->count());
    }

    public function testLeaderboardPageRanksCompanies()
    {
        $player = $this->player('rich@example.com', 'Rich Player');
        $rich = Release::release($player, 'Rich Co', ': launched ;');
        DB::table('companies')->where('company_id', $rich->company_id)->increment('credits', 9000);
        $poor = Release::release($player, 'Poor Co', ': launched ;');
        $this->get('/leaderboard')->assertOk()->assertSee('Rising this week')
            ->assertSeeInOrder(['Rich Co', '9,000', 'Poor Co'])
            ->assertDontSee($rich->name);
    }
}
