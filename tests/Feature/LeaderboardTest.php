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

    /** #548: age in game days, and the max-age filter (kept for old links) */
    public function testAgeFilter()
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
        $this->get('/leaderboard?max_age=10')->assertOk()->assertSee('Young Co')->assertDontSee('Old Rich Co');
        $this->getJson('/api/v1/leaderboard?max_age=10&limit=500')->assertOk()
            ->assertJsonFragment(['company' => 'Young Co'])->assertJsonMissing(['company' => 'Old Rich Co']);
        // Leaderboard names link to their companies (#591)
        $this->get('/leaderboard?max_age=10')->assertSee('href="' . url('/companies/' . $young->company_id) . '"', false);
    }

    /**
     * #614: every company ranked by its net wealth when it was a given age,
     * read from its daily history (from 0 when founded, to its wealth now);
     * a company's rank is its wealth now against every company's at its age
     */
    public function testAtAgeRanksEveryCompanyAsItWasAtThatAge()
    {
        // A clock whose world began 1000 game days ago; game time runs with real time
        $now = time();
        DB::table('game_clock')->delete();
        DB::table('game_clock')->insert(['id' => 1, 'real_anchor' => $now, 'game_anchor' => $now, 'scale' => 1, 'epoch' => $now - 1000 * 86400]);
        $today = 1000;
        $at = fn (float $day) => (int) round($now - (1000 - $day) * 86400);
        $made = [];
        foreach (['Elder' => 900, 'Middle' => 980, 'Infant' => $today - 5, 'Gone' => 950] as $name => $born) {
            $made[$name] = Release::release($this->player(strtolower($name) . '@example.com', $name), $name . ' Co', ': launched ;')->company_id;
            DB::table('companies')->where('company_id', $made[$name])->update(['founded_t' => $at($born)]);
        }
        $days = fn (string $name, array $rows) => DB::table('company_days')->insert(array_map(fn ($d, $w) =>
            ['company_id' => $made[$name], 'day' => $d, 'credits' => 0, 'net_wealth' => $w, 'drones' => 1], array_keys($rows), $rows));
        // Elder was poor when young and is rich now; Middle did well early; Gone did best, then ended at 12 days old
        $days('Elder', [910 => 1000, 920 => 3000, 999 => 90000]);
        $days('Middle', [990 => 2000]);
        $days('Gone', [960 => 5000, 962 => 5200]);
        DB::table('companies')->where('company_id', $made['Elder'])->increment('credits', 100000);
        DB::table('companies')->where('company_id', $made['Middle'])->increment('credits', 2500);
        DB::table('companies')->where('company_id', $made['Gone'])->update(['eliminated_at' => $at(962.5)]);

        $board = Leaderboard::atAge(10);
        $ids = array_column($board['rows'], 'company_id');
        $this->assertSame([$made['Gone'], $made['Middle'], $made['Elder']], array_values(array_intersect($ids, $made)),
            'at 10 days old, Gone (5,000) beat Middle (2,000) and Elder (1,000), whatever they are worth now');
        $row = fn ($b, $name) => $b['rows'][array_search($made[$name], array_column($b['rows'], 'company_id'))];
        $this->assertSame(1000, $row($board, 'Elder')['wealth']);
        $this->assertTrue($row($board, 'Gone')['ended']);
        $this->assertNull($row($board, 'Gone')['now']);
        $this->assertSame(2500, $row($board, 'Middle')['now']);
        $this->assertNotContains($made['Infant'], $ids, 'a company that is 5 days old has not reached 10');
        $this->assertGreaterThanOrEqual(1, $board['too_young']);

        // Between history points it is read on a line: Elder at 15 days, halfway from 1,000 to 3,000;
        // Middle at 15, from 2,000 (day 10) toward its 2,500 now (day 20)
        $board = Leaderboard::atAge(15);
        $this->assertSame(2000, $row($board, 'Elder')['wealth']);
        $this->assertSame(10.0, $row($board, 'Elder')['gap']);
        $this->assertSame(2250, $row($board, 'Middle')['wealth']);
        $this->assertNotContains($made['Gone'], array_column($board['rows'], 'company_id'), 'Gone ended at 12.5 days');
        $this->assertGreaterThanOrEqual(1, $board['ended_before']);
        // From 0 on the day it was founded
        $this->assertSame(500, $row(Leaderboard::atAge(5), 'Elder')['wealth']);

        // Middle's rank: its 2,500 now (20 days old) against Elder's 3,000 at 20 days, not its 100,000 now
        $rank = Leaderboard::peerRank($made['Middle']);
        $this->assertEqualsWithDelta(20.0, $rank['age'], 0.1);
        $at20 = array_column(Leaderboard::atAge($rank['age'])['rows'], 'company_id');
        $this->assertSame(array_search($made['Middle'], $at20) + 1, $rank['rank']);
        $this->assertLessThan(array_search($made['Middle'], $at20), array_search($made['Elder'], $at20));
        $this->assertSame(Leaderboard::atAge($rank['age'])['of'], $rank['of']);

        // The page, the API, and the company page's stat row
        $this->get('/leaderboard?age=10')->assertOk()->assertSee('At age 10 game days')
            ->assertSeeInOrder(['Gone Co', '5,000', 'ended', 'Middle Co', '2,000', '2,500', 'Elder Co', '1,000'])
            ->assertSee('href="' . url('/companies/' . $made['Middle']) . '"', false);
        $this->get('/leaderboard?age=15')->assertSee('~2,000');
        $this->getJson('/api/v1/leaderboard?age=10&limit=500')->assertOk()->assertJsonPath('age', 10)
            ->assertJsonFragment(['company' => 'Gone Co', 'net_wealth_at_age' => 5000, 'net_wealth' => null, 'ended' => true]);
        $this->get('/companies/' . $made['Middle'])->assertOk()->assertSee('Rank at its age')
            ->assertSee(' of ' . $rank['of'], false)->assertSee('leaderboard?age=20', false);
        $this->getJson('/api/v1/companies/' . $made['Middle'])->assertOk()->assertJsonPath('peer_rank.rank', $rank['rank']);
    }

    /** #614: every day's history is kept, so a company's early days stay day by day */
    public function testDailyHistoryIsNotThinned()
    {
        $now = time();
        DB::table('game_clock')->delete();
        DB::table('game_clock')->insert(['id' => 1, 'real_anchor' => $now, 'game_anchor' => $now, 'scale' => 1, 'epoch' => $now - 500 * 86400]);
        $co = Release::release($this->player('k@example.com', 'Keeper'), 'Keeper Co', ': launched ;')->company_id;
        DB::table('company_days')->delete();
        DB::table('company_days')->insert(['company_id' => $co, 'day' => 401, 'credits' => 0, 'net_wealth' => 7, 'drones' => 1]);
        $this->assertTrue(Leaderboard::dailyIfDue());
        $this->assertTrue(DB::table('company_days')->where('day', 401)->exists(), 'a day 99 days old is kept');
        $this->assertTrue(DB::table('company_days')->where('day', 500)->exists());
    }
}
