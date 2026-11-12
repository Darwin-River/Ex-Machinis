<?php

namespace Tests\Feature;

use App\Services\Leaderboard;
use App\Services\Release;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
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

    /** #548: age in game days, a max-age filter, and a company ranked among its age or younger */
    public function testAgeFilterAndPeerRank()
    {
        $now = \App\Services\World::gameNow();
        $old = Release::release($this->player('old@example.com', 'Old Timer'), 'Old Rich Co', ': launched ;');
        $young = Release::release($this->player('young@example.com', 'Young One'), 'Young Co', ': launched ;');
        $this->assertSame(0.0, Leaderboard::wealth()[$young->company_id]['age'], 'a release is founded now');
        DB::table('companies')->where('company_id', $old->company_id)
            ->update(['founded_t' => $now - 4000 * 86400, 'credits' => DB::raw('credits + 100000')]);
        DB::table('companies')->where('company_id', $young->company_id)
            ->update(['founded_t' => $now - 2 * 86400, 'credits' => DB::raw('credits + 50')]);

        $this->assertEqualsWithDelta(4000.0, Leaderboard::wealth()[$old->company_id]['age'], 0.1);
        $young10 = array_column(Leaderboard::allTime(500, 10), 'company_id');
        $this->assertContains($young->company_id, $young10);
        $this->assertNotContains($old->company_id, $young10, 'an older company is left out');
        $this->assertNotContains($old->company_id, array_column(Leaderboard::rising(500, 10), 'company_id'));

        $rank = Leaderboard::peerRank($young->company_id);
        $this->assertEqualsWithDelta(2.0, $rank['age'], 0.1);
        $all = array_column(Leaderboard::allTime(500), 'company_id');
        $this->assertLessThan(array_search($young->company_id, $all) + 1, $rank['rank'], 'the older, richer company doesn\'t count');
        $this->assertLessThan(count($all), $rank['of']);
        $this->assertSame(count($all), Leaderboard::peerRank($old->company_id)['of'], 'the oldest is ranked among all');

        $this->get('/leaderboard?max_age=10')->assertOk()->assertSee('Young Co')->assertDontSee('Old Rich Co');
        $this->getJson('/api/v1/leaderboard?max_age=10&limit=500')->assertOk()
            ->assertJsonFragment(['company' => 'Young Co'])->assertJsonMissing(['company' => 'Old Rich Co']);
        // The company page's stat row (#591): net wealth, and its rank among its age
        $this->get('/companies/' . $young->company_id)->assertOk()->assertSee('Net wealth')
            ->assertSee('Rank, its age or younger')->assertSee(' of ' . $rank['of'], false);
        // Leaderboard names link to their companies (#591)
        $this->get('/leaderboard?max_age=10')->assertSee('href="' . url('/companies/' . $young->company_id) . '"', false);
        $this->getJson('/api/v1/companies/' . $young->company_id)->assertOk()->assertJsonPath('peer_rank.rank', $rank['rank']);
    }
}
