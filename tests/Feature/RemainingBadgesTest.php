<?php

namespace Tests\Feature;

use App\Services\Badges;
use App\Services\PlayerBoards;
use App\Services\Players;
use App\Services\Release;
use App\Services\Startups;
use App\Services\World;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The last eight badges (#1057): Far Traveller, Never Ran Dry, Smelter, Deep
 * Hauler, Courier, Top Ten, Season Winner and Lineage, awarded without
 * reading `events`.
 */
class RemainingBadgesTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['forge.new_rules' => true, 'forge.startup_hull_value' => 2000, 'forge.startup_fee' => 500,
            'forge.starting_credits' => 3000, 'forge.startup_max' => 5, 'forge.startup_mature_margin' => 0]);
        Mail::fake();
        Cache::flush();
    }

    private function player(string $name): User
    {
        $email = strtolower(str_replace(' ', '.', $name)) . '@example.com';
        $u = Players::create($name, 'long-enough', $email);
        Players::verify($u->user_id, sha1($email));
        return User::where('user_id', $u->user_id)->first();
    }

    private function startup(User $owner, string $name): array
    {
        $id = Startups::start($owner, $name, 'function launched() wait(60) end')['company'];
        return [$id, (int) DB::table('agents')->where('company_id', $id)->value('agent_id')];
    }

    private function has(string $kind, int $id, string $badge, int $tier = 1): bool
    {
        return DB::table('badges')->where(['kind' => $kind, 'subject_id' => $id, 'badge' => $badge, 'tier' => $tier])->exists();
    }

    public function testFarTravellerCountsThePlacesADroneArrivedMinedOrTradedAt()
    {
        [, $drone] = $this->startup($this->player('Traveller'), 'Travel Co');
        $now = World::gameNow();
        DB::table('agents')->where('agent_id', $drone)->update(['object_id' => 3, 'arrived_t' => $now]);
        foreach ([10, 4] as $place) {
            DB::table('mining_runs')->insert(['object_id' => $place, 'resource' => 1001, 'drone' => $drone, 'game_t' => $now, 'weight' => 1]);
        }
        foreach ([191, 5] as $place) {
            DB::table('trades')->insert(['resource' => 2011, 'units' => 1, 'credits' => 10, 'seller' => $drone, 'buyer' => 1, 'location' => $place, 'game_t' => $now]);
        }
        Badges::awardAll();
        $this->assertTrue($this->has('drone', $drone, 'far_traveller'), 'five places: Earth, the Moon, Mars, Ceres and one more');
        $this->assertFalse($this->has('drone', $drone, 'far_traveller', 2));
    }

    public function testNeverRanDryCountsOnlyDaysSinceItWasLastFoundEmpty()
    {
        [, $dry] = $this->startup($this->player('Dry Owner'), 'Dry Co');
        [, $full] = $this->startup($this->player('Full Owner'), 'Full Co');
        $now = World::gameNow();
        DB::table('holdings')->where('drone', $dry)->where('resource', Release::FUEL)->delete();
        DB::table('holdings')->updateOrInsert(['drone' => $full, 'resource' => Release::FUEL, 'installed' => 0, 'locked' => 0], ['units' => 40, 'event_id' => 0]);
        Badges::awardAll();   // marks begin; the dry drone is found empty
        $this->assertTrue(DB::table('badge_marks')->where(['subject_id' => $dry, 'mark' => 'ran_dry'])->exists());
        $this->assertFalse(DB::table('badge_marks')->where(['subject_id' => $full, 'mark' => 'ran_dry'])->exists());
        foreach ([$dry, $full] as $d) {
            for ($i = 1; $i <= 30; $i++) {
                DB::table('drone_days')->insert(['drone' => $d, 'day' => 100000 + $i, 'events' => 1, 'failed' => 0, 'actions' => 1,
                    'first_t' => $now + $i * 86400, 'last_t' => $now + $i * 86400]);
            }
        }
        // Found empty again on day 5, then refuelled
        DB::table('badge_marks')->where(['subject_id' => $dry, 'mark' => 'ran_dry'])->update(['game_t' => $now + 5 * 86400]);
        DB::table('holdings')->insert(['drone' => $dry, 'resource' => Release::FUEL, 'installed' => 0, 'locked' => 0, 'units' => 40, 'event_id' => 0]);
        Badges::awardAll();
        $this->assertTrue($this->has('drone', $full, 'never_ran_dry'), '30 working days, never empty');
        $this->assertFalse($this->has('drone', $dry, 'never_ran_dry'), 'only 25 since it was found empty');
    }

    public function testSmelterIsAFinishedRefiningAction()
    {
        [, $a] = $this->startup($this->player('Smelt One'), 'Smelt Co');
        [, $b] = $this->startup($this->player('Smelt Two'), 'Abort Co');
        $old = now()->subMinutes(20);
        DB::table('actions')->insert(['drone' => $a, 'protocol' => 21130, 'multiplier' => 1, 'aborted' => 0, 'timestamp' => $old]);
        DB::table('actions')->insert(['drone' => $b, 'protocol' => 21130, 'multiplier' => 1, 'aborted' => 1, 'timestamp' => $old]);
        $fresh = DB::table('actions')->insertGetId(['drone' => $b, 'protocol' => 21000, 'multiplier' => 1, 'aborted' => 0, 'timestamp' => now()]);
        Badges::awardAll();
        $this->assertTrue($this->has('drone', $a, 'smelter'));
        $this->assertFalse($this->has('drone', $b, 'smelter'), 'an aborted refining, and one still under way');
        DB::table('actions')->where('id', $fresh)->update(['timestamp' => $old]);
        Badges::awardAll();
        $this->assertTrue($this->has('drone', $b, 'smelter'), 'the watermark waits for the one under way');
    }

    public function testDeepHaulerAndCourier()
    {
        $me = $this->player('Hauler');
        [$co, $drone] = $this->startup($me, 'Haul Co');
        [, $other] = $this->startup($this->player('Near Hauler'), 'Near Co');
        $now = World::gameNow();
        DB::table('far_minerals')->insertOrIgnore(['resource' => 1301, 'homes' => 1, 'multiplier' => 10]);
        DB::table('mining_runs')->insert(['object_id' => 5, 'resource' => 1301, 'drone' => $drone, 'game_t' => $now, 'weight' => 1]);
        foreach ([$drone, $other] as $d) {
            DB::table('trades')->insert(['resource' => 2013, 'units' => 2, 'credits' => 300, 'seller' => $d, 'buyer' => 1, 'location' => 3, 'game_t' => $now]);
        }
        $buyer = $this->startup($this->player('Contract Buyer'), 'Buyer Co');
        DB::table('contracts')->insert(['buyer_company' => $buyer[0], 'buyer_drone' => $buyer[1], 'place' => 3, 'resource' => 2011, 'units' => 5,
            'price' => 10, 'deposit' => 0, 'escrow' => 50, 'posted_t' => $now, 'due_t' => $now + 86400, 'seller_company' => $co,
            'taken_t' => $now, 'deposit_held' => 0, 'delivered' => 5, 'pending_drone' => $drone, 'settled_t' => $now, 'outcome' => 'kept']);
        Badges::awardAll();
        $this->assertTrue($this->has('drone', $drone, 'deep_hauler'));
        $this->assertFalse($this->has('drone', $other, 'deep_hauler'), 'Metals (A3) sold, but no far mineral mined');
        $this->assertTrue($this->has('drone', $drone, 'courier'));
        $this->assertFalse($this->has('drone', $other, 'courier'));
    }

    public function testTopTenIsTheLatestSnapshotsTenRichest()
    {
        $taken = now();
        $ids = [];
        for ($i = 0; $i < 11; $i++) {
            [$co] = $this->startup($this->player('Ranked ' . $i), 'Ranked Co ' . $i);
            $ids[] = $co;
            DB::table('wealth_snapshots')->insert(['company_id' => $co, 'taken_at' => $taken, 'wealth' => 1000000 - $i]);
        }
        Badges::awardAll();
        $this->assertTrue($this->has('company', $ids[0], 'top_ten'));
        $this->assertTrue($this->has('company', $ids[9], 'top_ten'));
        $this->assertFalse($this->has('company', $ids[10], 'top_ten'), 'eleventh');
    }

    public function testSeasonWinnerIsTheDaysBiggestGrowerOnThePublicBoard()
    {
        $winner = $this->player('Season Winner');
        $rich = $this->player('Season Rich');
        $pupil = $this->player('Season Pupil');
        foreach ([$winner, $rich, $pupil] as $u) {
            $this->startup($u, $u->login . ' Co');   // played
        }
        $group = DB::table('class_groups')->insertGetId(['teacher_id' => $rich->user_id, 'name' => 'Season class'] + (\Illuminate\Support\Facades\Schema::hasColumn('class_groups', 'created_at') ? ['created_at' => now()] : []));
        DB::table('users')->where('user_id', $pupil->user_id)->update(['class_group_id' => $group]);
        DB::table('site_counts')->where('name', 'badges_season_day')->delete();
        $d1 = now('UTC')->subDays(3)->format('Y-m-d');
        $d2 = now('UTC')->subDays(2)->format('Y-m-d');
        DB::table('player_wealth_days')->where('day', '>=', $d1)->delete();
        foreach ([[$winner, 1000, 1500], [$rich, 100000, 120000], [$pupil, 100, 1000]] as [$u, $start, $end]) {
            DB::table('player_wealth_days')->insert(['user_id' => $u->user_id, 'day' => $d1, 'start_wealth' => $start]);
            DB::table('player_wealth_days')->insert(['user_id' => $u->user_id, 'day' => $d2, 'start_wealth' => $end]);
        }
        Badges::awardAll();
        $this->assertTrue($this->has('player', (int) $winner->user_id, 'season_winner'), '+50% beats +20%');
        $this->assertFalse($this->has('player', (int) $rich->user_id, 'season_winner'));
        $this->assertFalse($this->has('player', (int) $pupil->user_id, 'season_winner'), 'a pupil is not on the public board');
        $this->assertSame((int) str_replace('-', '', $d1), (int) DB::table('site_counts')->where('name', 'badges_season_day')->value('n'),
            'the second day waits for its next day\'s start');
        $this->assertNotSame($d2, PlayerBoards::day());
    }

    public function testLineageIsFiveOwnCompaniesInOneLine()
    {
        $me = $this->player('Lineage Owner');
        $other = $this->player('Lineage Other');
        $revisions = [];
        foreach (range(1, 5) as $i) {
            [$co] = $this->startup($i === 3 ? $other : $me, 'Line Co ' . $i);
            $revisions[$i] = DB::table('code_revisions')->insertGetId(['company_id' => $co, 'push_number' => 0, 'game_t' => World::gameNow(),
                'source_hash' => sha1('line' . $i), 'size' => 10]);
            if ($i > 1) {
                DB::table('code_parents')->insert(['revision_id' => $revisions[$i], 'parent_id' => $revisions[$i - 1], 'rank_no' => 1, 'share' => 1]);
            }
        }
        Badges::awardAll();
        $this->assertFalse($this->has('player', (int) $me->user_id, 'lineage'), 'the third company in the line is another player\'s');
        DB::table('companies')->where('name', 'Line Co 3')->update(['owner_id' => $me->user_id]);
        Badges::awardAll();
        $this->assertTrue($this->has('player', (int) $me->user_id, 'lineage'));
        $this->assertFalse($this->has('player', (int) $other->user_id, 'lineage'));
    }
}
