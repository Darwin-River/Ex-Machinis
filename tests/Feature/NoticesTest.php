<?php

namespace Tests\Feature;

use App\Mail\Notice;
use App\Services\Notices;
use App\Services\Players;
use App\Services\World;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Opt-in e-mail notices (#761): off until turned on, one daily e-mail with
 * can-mature, at-risk and wrecked items, a weekly digest, a teacher's class
 * summary for pupils (who have no address), and a link that turns them off
 */
class NoticesTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['forge.new_rules' => true, 'forge.trial_auto_verify' => false, 'forge.seedling_mature_margin' => 500]);
        Mail::fake();
        Cache::flush();
        if (!DB::table('game_clock')->where('id', 1)->exists()) {
            DB::table('game_clock')->insert(['id' => 1, 'real_anchor' => time(), 'game_anchor' => time(), 'scale' => 1,
                'epoch' => time() - 100 * 86400]);
        }
    }

    private function player(string $name): \App\User
    {
        $email = strtolower(str_replace(' ', '.', $name)) . '@example.com';
        $user = Players::create($name, 'long-enough', $email);
        Players::verify($user->user_id, sha1($email));
        return \App\User::where('user_id', $user->user_id)->first();
    }

    /** A live seedling of the owner's, with no drone (so at risk: no working drone) */
    private function seedling(int $owner, int $credits, int $debt = 3000): int
    {
        return DB::table('companies')->insertGetId(['user_id' => $owner, 'owner_id' => $owner, 'seedling' => 1,
            'name' => 'Notice Co ' . uniqid(), 'credits' => $credits, 'debt' => $debt, 'compute_debt' => 0, 'house' => 0,
            'founded_t' => World::gameNow() - 86400]);
    }

    private function sent(string $to): array
    {
        $out = [];
        Mail::assertSent(Notice::class, function ($m) use ($to, &$out) {
            if ($m->hasTo($to)) {
                $out[] = $m;
            }
            return true;
        });
        return $out;
    }

    public function testNothingIsSentUntilAPlayerTurnsItOn()
    {
        $p = $this->player('Quiet Pilot');
        $this->seedling($p->user_id, 9000);
        Notices::runIfDue(true);
        Mail::assertNothingSent();
        $this->assertSame(['can_mature' => false, 'at_risk' => false, 'wrecked' => false, 'digest' => false], Notices::settings($p));
    }

    public function testTheDailyEmailSaysWhatNeedsThemOnceADay()
    {
        $p = $this->player('Daily Pilot');
        $ready = $this->seedling($p->user_id, 4000);     // 3,000 debt + 500 margin: it can mature
        $this->actingAs($p)->post('/account/notices', ['notices' => ['can_mature', 'at_risk', 'wrecked', 'nonsense']])
            ->assertRedirect('/account#notices');
        $this->assertSame(['can_mature' => true, 'at_risk' => true, 'wrecked' => true, 'digest' => false], Notices::settings($p));
        $this->actingAs($p)->get('/account')->assertOk()->assertSee('id="notices"', false)->assertSee('A weekly digest of my companies and shares');
        // A wreck after turning it on is told; one before isn't
        DB::table('agents')->insert(['name' => 'Old Wreck ' . uniqid(), 'user_id' => $p->user_id, 'company_id' => $ready,
            'object_id' => 3, 'hull_type' => 1, 'wrecked_at' => World::gameNow() - 3600, 'wreck_cause' => 'fuel']);
        DB::table('notice_settings')->where('user_id', $p->user_id)->update(['wrecks_since' => World::gameNow() - 60]);
        $wreck = DB::table('agents')->insertGetId(['name' => 'New Wreck', 'user_id' => $p->user_id, 'company_id' => $ready,
            'object_id' => 3, 'hull_type' => 1, 'wrecked_at' => World::gameNow(), 'wreck_cause' => 'fuel']);

        $this->assertSame(1, Notices::runIfDue(true)['daily']);
        [$mail] = $this->sent('daily.pilot@example.com');
        $kinds = array_column($mail->items, 'kind');
        $this->assertSame(['can_mature', 'at_risk', 'wrecked'], $kinds);
        $this->assertStringContainsString('can mature: it holds 4,000 of the 3,500 credits', $mail->items[0]['text']);
        $this->assertStringContainsString('no working drone', $mail->items[1]['text']);
        $this->assertStringContainsString('New Wreck', $mail->items[2]['text']);
        $this->assertStringNotContainsString('Old Wreck', implode(' ', array_column($mail->items, 'text')));
        $this->assertSame(url('spacecraft/' . $wreck), $mail->items[2]['link']);
        foreach ($mail->items as $i) {
            $this->assertNotEmpty($i['try'], 'each says what to try next');
        }
        $this->assertStringContainsString('/notices/unsubscribe/' . $p->user_id, $mail->unsubscribe);
        $html = $mail->render();
        $this->assertStringContainsString('Try next:', $html);
        $this->assertStringContainsString('turn them all off', $html);

        // Not again within the real day
        Mail::fake();
        $this->assertSame(0, Notices::runIfDue(true)['daily']);
        Mail::assertNothingSent();
        // A day on: "can mature" isn't told twice, the risk is
        DB::table('notice_settings')->where('user_id', $p->user_id)->update(['last_daily_at' => now()->subDays(2)]);
        DB::table('notices_sent')->where('user_id', $p->user_id)->update(['sent_at' => now()->subDays(2)]);
        $this->assertSame(1, Notices::runIfDue(true)['daily']);
        [$again] = $this->sent('daily.pilot@example.com');
        $this->assertSame(['at_risk'], array_column($again->items, 'kind'));
    }

    public function testTheLinkTurnsThemAllOff()
    {
        $p = $this->player('Leaving Pilot');
        Notices::save($p, ['can_mature', 'digest']);
        $this->get(Notices::unsubscribeUrl($p->user_id))->assertRedirect();
        $this->assertSame(['can_mature' => false, 'at_risk' => false, 'wrecked' => false, 'digest' => false], Notices::settings($p));
        // Unsigned, it does nothing
        Notices::save($p, ['digest']);
        $this->get('/notices/unsubscribe/' . $p->user_id)->assertForbidden();
        $this->assertTrue(Notices::settings($p)['digest']);
    }

    public function testTheWeeklyDigest()
    {
        $p = $this->player('Digest Pilot');
        $this->seedling($p->user_id, 1200);
        Notices::save($p, ['digest']);
        // Not before a week has gone by
        $this->assertSame(0, Notices::runIfDue(true)['digests']);
        DB::table('notice_settings')->where('user_id', $p->user_id)->update(['last_digest_at' => now()->subDays(8)]);
        $this->assertSame(1, Notices::runIfDue(true)['digests']);
        [$mail] = $this->sent('digest.pilot@example.com');
        $this->assertStringContainsString('1,200 of the 3,500 credits it needs to mature', $mail->items[0]['text']);
        $this->assertSame('Your week in Ex Machinis', $mail->heading);
    }

    public function testATeacherGetsAClassSummaryAndPupilsNothing()
    {
        $teacher = $this->player('Ms Orbit');
        $group = Players::createClassGroup($teacher, 'Notice Class', 2);
        $pupil = DB::table('users')->where('login', 'Notice Class 1')->value('user_id');
        $this->seedling($pupil, 5000);
        // Pupils can't turn notices on: they have no address
        $pupilUser = \App\User::where('user_id', $pupil)->first();
        $this->actingAs($pupilUser)->post('/account/notices', ['notices' => ['can_mature']])->assertRedirect('/account#notices');
        $this->assertFalse(Notices::settings($pupilUser)['can_mature']);
        $this->actingAs($pupilUser)->get('/account')->assertOk()->assertSee('Your teacher can turn on a daily');

        // Off until the teacher turns it on
        Notices::runIfDue(true);
        Mail::assertNothingSent();
        $this->actingAs($teacher)->get('/account')->assertOk()->assertSee('Notice Class: summary off');
        $this->actingAs($teacher)->post('/account/classes/' . $group['id'] . '/notices', ['on' => 1])->assertRedirect('/account#notices');
        $this->assertSame(1, Notices::runIfDue(true)['classes']);
        [$mail] = $this->sent('ms.orbit@example.com');
        $this->assertStringStartsWith('Notice Class 1: ', $mail->items[0]['text']);
        $this->assertSame('Class Notice Class: what needs your pupils', $mail->heading);
        // Someone else's class can't be switched
        $other = $this->player('Mr Other');
        $this->actingAs($other)->post('/account/classes/' . $group['id'] . '/notices', ['on' => 0])->assertNotFound();
    }
}
