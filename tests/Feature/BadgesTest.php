<?php

namespace Tests\Feature;

use App\Services\Badges;
use App\Services\Players;
use App\Services\Startups;
use App\Services\World;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Badges (#1048): awarded from the game's records on the tick, kept for good,
 * in tiers where they count; recognition only. Everyone by the same rules; a
 * pupil's shown only to them and their teacher; no badge from trading with
 * your own companies.
 */
class BadgesTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['forge.new_rules' => true, 'forge.startup_hull_value' => 2000, 'forge.startup_fee' => 500,
            'forge.starting_credits' => 3000, 'forge.startup_max' => 5, 'forge.startup_mature_margin' => 0, 'forge.lend_min_credits' => 5000]);
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

    public function testThePathsBadgesAreAwardedFromTheRecordsAndKept()
    {
        $me = $this->player('Badge Climber');
        $other = $this->player('Badge Other');
        [$mine, $drone] = $this->startup($me, 'Climb Co');
        [$theirs] = $this->startup($other, 'Other Co');
        $now = World::gameNow();
        // A fix, a crash, a day's work, a sale; then it matures
        DB::table('code_pushes')->insert(['company_id' => $mine, 'number' => 1, 'game_t' => $now, 'credits_before' => 3000,
            'permille' => 0, 'fee' => 0, 'drones' => 1, 'script' => 'function launched() wait(5) end']);
        DB::table('drone_crashes')->insert(['drone' => $drone, 'game_t' => $now, 'code' => -10, 'in_row' => 1]);
        DB::table('drone_days')->insert(['drone' => $drone, 'day' => 1, 'events' => 3, 'failed' => 0, 'actions' => 3, 'first_t' => $now, 'last_t' => $now]);
        DB::table('trades')->insert(['resource' => 2011, 'units' => 3, 'credits' => 48, 'seller' => $drone, 'buyer' => 1, 'location' => 3,
            'game_t' => $now, 'tax' => 0]);
        DB::table('companies')->whereIn('company_id', [$mine, $theirs])->update(['credits' => 9000]);
        $this->assertTrue(Startups::mature($me, $mine)['ok']);
        $this->assertTrue(Startups::mature($other, $theirs)['ok']);
        // A share in another's company, and a loan to it
        DB::table('player_share_trades')->insert(['company_id' => $theirs, 'seller_id' => $other->user_id, 'buyer_id' => $me->user_id,
            'units' => 1, 'price' => 50, 'game_t' => $now]);
        DB::table('loans')->insert(['request_id' => 1, 'company_id' => $theirs, 'user_id' => $me->user_id, 'principal' => 1000,
            'rate_bp' => 500, 'lent_t' => $now, 'due_t' => $now + 86400, 'owed' => 1050]);

        $this->assertGreaterThan(0, Badges::awardAll());
        foreach (['founder', 'fixer', 'first_crash', 'shareholder', 'investor', 'lender', 'climber'] as $badge) {
            $this->assertTrue($this->has('player', (int) $me->user_id, $badge), "player $badge");
        }
        foreach (['founded', 'matured'] as $badge) {
            $this->assertTrue($this->has('company', $mine, $badge), "company $badge");
        }
        $this->assertTrue($this->has('company', $theirs, 'gone_public'), 'its first share was sold');
        foreach (['first_light', 'first_sale'] as $badge) {
            $this->assertTrue($this->has('drone', $drone, $badge), "drone $badge");
        }
        $this->assertFalse($this->has('player', (int) $other->user_id, 'investor'), 'selling is not investing');
        // Kept, and not awarded twice
        $this->assertSame(0, Badges::awardAll());
        DB::table('player_share_trades')->where('buyer_id', $me->user_id)->delete();
        Badges::awardAll();
        $this->assertTrue($this->has('player', (int) $me->user_id, 'investor'), 'an award is kept');
    }

    public function testTiersAreAwardedAtEachThreshold()
    {
        $me = $this->player('Tier Owner');
        [$id] = $this->startup($me, 'Tier Co');
        $agent = (array) DB::table('agents')->where('company_id', $id)->first();
        unset($agent['agent_id']);
        foreach (range(1, 7) as $i) {
            DB::table('agents')->insert(array_merge($agent, ['name' => 'Tier copy ' . $i]));
        }
        Badges::awardAll();
        $this->assertTrue($this->has('company', $id, 'fleet', 1), 'bronze at 3 drones');
        $this->assertTrue($this->has('company', $id, 'fleet', 2), 'silver at 8');
        $this->assertFalse($this->has('company', $id, 'fleet', 3), 'not gold under 20');
        $this->assertSame('silver', collect(Badges::of('company', $id))->firstWhere('key', 'fleet')['tier_name'], 'its best tier shows');
    }

    public function testNoBadgeFromTradingWithYourOwnCompanies()
    {
        $me = $this->player('Self Dealer');
        [$id] = $this->startup($me, 'Self Co');
        DB::table('companies')->where('company_id', $id)->update(['credits' => 9000]);
        $this->assertTrue(Startups::mature($me, $id)['ok']);
        DB::table('player_share_trades')->insert(['company_id' => $id, 'seller_id' => null, 'buyer_id' => $me->user_id, 'units' => 5,
            'price' => 50, 'game_t' => World::gameNow()]);
        DB::table('loans')->insert(['request_id' => 1, 'company_id' => $id, 'user_id' => $me->user_id, 'principal' => 1000,
            'rate_bp' => 500, 'lent_t' => World::gameNow(), 'due_t' => World::gameNow() + 86400, 'owed' => 1050]);
        Badges::awardAll();
        $this->assertFalse($this->has('player', (int) $me->user_id, 'investor'));
        $this->assertFalse($this->has('player', (int) $me->user_id, 'lender'));
    }

    public function testTestPilotForADriveSignedInOrBeforeSigningUp()
    {
        $me = $this->player('Pilot One');
        $this->actingAs($me)->post('/try', ['starter' => 'simple_miner', 'script' => "function launched()\n  wait(60)\nend"]);
        $this->assertTrue($this->has('player', (int) $me->user_id, 'test_pilot'));
        auth()->logout();
        $this->post('/try', ['starter' => 'simple_miner', 'script' => "function launched()\n  wait(60)\nend"]);
        $this->post('/signup', ['name' => 'Pilot Two', 'email' => 'pilot.two@example.com', 'password' => 'long-enough']);
        $this->assertTrue($this->has('player', (int) DB::table('users')->where('login', 'Pilot Two')->value('user_id'), 'test_pilot'));
    }

    public function testBadgesShowOnTheirPagesAndAPupilsOnlyToThemAndTheirTeacher()
    {
        $me = $this->player('Shown Player');
        [$id, $drone] = $this->startup($me, 'Shown Co');
        Badges::award('player', (int) $me->user_id, 'founder');
        Badges::award('company', $id, 'founded');
        Badges::award('drone', $drone, 'first_light');
        $this->get('/companies/' . $id)->assertOk()->assertSee('id="company-badges"', false)->assertSee('Founded');
        $this->get('/spacecraft/' . $drone)->assertOk()->assertSee('id="drone-badges"', false)->assertSee('First Light');
        $this->actingAs($me)->get('/account')->assertOk()->assertSee('Your badges')->assertSee('id="badge-founder"', false);
        $this->actingAs($me)->get('/')->assertOk()->assertSee('You earned the Founder badge');
        // A pupil's: only to them and their teacher
        $teacher = $this->player('Shown Teacher');
        $group = Players::createClassGroup($teacher, 'Lyra', 1);
        $pupil = User::where('login', 'Lyra 1')->first();
        [$pid] = $this->startup($pupil, 'Pupil Co');
        Badges::award('company', $pid, 'founded');
        auth()->logout();
        $this->get('/companies/' . $pid)->assertOk()->assertDontSee('id="company-badges"', false);
        $this->actingAs($teacher)->get('/companies/' . $pid)->assertOk()->assertSee('id="company-badges"', false);
        $this->assertNotNull($group['id']);
    }

    public function testThePublicPlayerPageAndApiListBadges()
    {
        $me = $this->player('Public Badger');
        [$id] = $this->startup($me, 'Public Badge Co');
        Badges::award('player', (int) $me->user_id, 'founder');
        // A player with a company is on the public boards, and so has a page
        $this->get('/players/' . rawurlencode('Public Badger'))->assertOk()->assertSee('id="player-badges"', false)->assertSee('Founder');
        $this->getJson('/api/v1/players/' . rawurlencode('Public Badger'))->assertOk()->assertJsonPath('badges.0.key', 'founder')
            ->assertJsonPath('badges.0.name', 'Founder');
        $this->assertNotNull($id);
    }

    // #1055

    public function testTheBadgesPageDescribesEveryBadgeAtItsOwnAnchor()
    {
        $me = $this->player('Page Reader');
        Badges::award('player', (int) $me->user_id, 'founder');
        [$id] = $this->startup($me, 'Page Co');
        Badges::award('company', $id, 'fleet', 1);
        $html = $this->get('/badges')->assertOk()->assertSee('Recognition for what drones, companies and players achieve.')
            ->assertSee('nothing any rule reads')->getContent();
        foreach (Badges::CATALOG as $key => [, $name, $text]) {
            $this->assertStringContainsString('id="' . $key . '"', $html, "$key has its anchor");
            $this->assertStringContainsString(e($name), $html);
            $this->assertStringContainsString(e($text), $html);
        }
        foreach (['drone-badges', 'company-badges', 'player-badges'] as $group) {
            $this->assertStringContainsString('href="#' . $group . '"', $html);
        }
        $this->assertStringContainsString('<b>Silver</b> at 8 drones', $html, 'tiers with their thresholds');
        $catalog = collect(Badges::catalog()['player'])->keyBy('key');
        $this->assertGreaterThanOrEqual(1, $catalog['founder']['earned'], 'how many have earned it: a count, naming no one');
        $this->assertStringNotContainsString('Page Reader', $html);
    }

    public function testEveryBadgeShownLinksToItsDescription()
    {
        $me = $this->player('Link Follower');
        [$id, $drone] = $this->startup($me, 'Link Co');
        Badges::award('player', (int) $me->user_id, 'founder');
        Badges::award('company', $id, 'founded');
        Badges::award('drone', $drone, 'first_light');
        $this->get('/companies/' . $id)->assertOk()->assertSee('href="' . url('badges') . '#founded"', false);
        $this->get('/spacecraft/' . $drone)->assertOk()->assertSee('href="' . url('badges') . '#first_light"', false);
        $this->actingAs($me)->get('/account')->assertOk()->assertSee('href="' . url('badges') . '#founder"', false)
            ->assertSee('href="' . url('badges') . '"', false);
        $this->assertSame(url('badges') . '#investor', Badges::url('investor'));
    }

    public function testAnAwardPassRunsAtMostEveryFifteenMinutes()
    {
        Badges::awardDue();
        $this->assertSame(0, Badges::awardDue(), 'the second within the window does nothing');
        $this->assertCount(count(Badges::CATALOG), array_unique(array_keys(Badges::CATALOG)));
        foreach (Badges::CATALOG as $key => [$kind]) {
            $this->assertContains($kind, ['drone', 'company', 'player'], $key);
        }
    }
}
