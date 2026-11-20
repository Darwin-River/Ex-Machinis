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
        config(['forge.new_rules' => true, 'forge.trial_auto_verify' => false, 'forge.startup_mature_margin' => 500]);
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

    /** A live startup of the owner's, with no drone (so at risk: no working drone) */
    private function startup(int $owner, int $credits, int $debt = 3000): int
    {
        return DB::table('companies')->insertGetId(['user_id' => $owner, 'owner_id' => $owner, 'startup' => 1,
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
        $this->startup($p->user_id, 9000);
        Notices::runIfDue(true);
        Mail::assertNothingSent();
        $this->assertSame(['can_mature' => false, 'at_risk' => false, 'wrecked' => false, 'digest' => false], array_slice(Notices::settings($p), 0, 4));
    }

    /** #836: the notice settings through the API, as on the account page */
    public function testTheSettingsThroughTheApi()
    {
        $p = $this->player('Api Pilot');
        $h = ['Authorization' => 'Bearer ' . Players::issueToken($p)];
        $this->getJson('/api/v1/account/notices')->assertStatus(401);
        $this->getJson('/api/v1/account/notices', $h)->assertOk()->assertJsonPath('notices.can_mature', false)
            ->assertJsonPath('kinds.digest', 'A weekly digest of my companies and shares');
        $this->postJson('/api/v1/account/notices', ['notices' => ['can_mature', 'at_cap']], $h)->assertOk()
            ->assertJsonPath('notices.can_mature', true)->assertJsonPath('notices.at_cap', true)->assertJsonPath('notices.digest', false);
        $this->postJson('/api/v1/account/notices', ['notices' => ['nonsense']], $h)->assertStatus(422);
        $this->assertTrue(Notices::settings($p)['can_mature'], 'a refused change leaves them as they were');
    }

    public function testTheDailyEmailSaysWhatNeedsThemOnceADay()
    {
        $p = $this->player('Daily Pilot');
        $ready = $this->startup($p->user_id, 4000);     // 3,000 debt + 500 margin: it can mature
        $this->actingAs($p)->post('/account/notices', ['notices' => ['can_mature', 'at_risk', 'wrecked', 'nonsense']])
            ->assertRedirect('/account#notices');
        $this->assertSame(['can_mature' => true, 'at_risk' => true, 'wrecked' => true, 'digest' => false], array_slice(Notices::settings($p), 0, 4));
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
        $this->assertSame(['can_mature' => false, 'at_risk' => false, 'wrecked' => false, 'digest' => false], array_slice(Notices::settings($p), 0, 4));
        // Unsigned, it does nothing
        Notices::save($p, ['digest']);
        $this->get('/notices/unsubscribe/' . $p->user_id)->assertForbidden();
        $this->assertTrue(Notices::settings($p)['digest']);
    }

    public function testTheWeeklyDigest()
    {
        $p = $this->player('Digest Pilot');
        $this->startup($p->user_id, 1200);
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
        $this->startup($pupil, 5000);
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

    /** #761, part 2: the six trading notices */
    public function testTheTradingNotices()
    {
        $p = $this->player('Trade Pilot');
        $other = $this->player('Other Trader');
        $now = World::gameNow();
        $epoch = (int) DB::table('game_clock')->where('id', 1)->value('epoch');
        $today = intdiv($now - $epoch, 86400);
        Notices::save($p, ['at_cap', 'fills', 'orders', 'bank_paid', 'cash_out', 'price_alerts']);
        // What came before turning them on isn't told: an old fill
        $held = DB::table('companies')->insertGetId(['user_id' => $other->user_id, 'owner_id' => $other->user_id, 'startup' => 0,
            'name' => 'Held Co ' . uniqid(), 'credits' => 50000, 'house' => 0, 'founded_t' => $now - 30 * 86400]);
        $trade = fn () => DB::table('player_share_trades')->insertGetId(['company_id' => $held, 'seller_id' => $other->user_id,
            'buyer_id' => $p->user_id, 'units' => 3, 'price' => 210, 'game_t' => World::gameNow()]);
        DB::table('notice_settings')->where('user_id', $p->user_id)->update(['fills_after' => $trade()]);
        $trade();

        // A startup past its cap
        $seed = $this->startup($p->user_id, 2000);
        DB::table('startup_skims')->insert(['company_id' => $seed, 'game_t' => $now - 60, 'amount' => 75]);
        // An order that can't fill (its shares don't trade)
        $order = DB::table('player_share_orders')->insertGetId(['user_id' => $p->user_id, 'company_id' => $seed, 'side' => 'buy',
            'units' => 2, 'price' => 100, 'created_at' => now(), 'game_t' => $now]);
        // A bank payment, cut to the lower value
        DB::table('bank_payments')->insert(['user_id' => $p->user_id, 'company_id' => $held, 'units' => 4, 'price_at_sale' => 250,
            'sold_t' => $now - 86400, 'due_t' => $now, 'paid_t' => $now + 1, 'paid' => 800]);
        // A holding whose cash-out rose 20%, and one that ended
        DB::table('player_shares')->insert(['user_id' => $p->user_id, 'company_id' => $held, 'units' => 5]);
        DB::table('company_days')->insert([
            ['company_id' => $held, 'day' => $today - 7, 'credits' => 0, 'net_wealth' => 0, 'drones' => 1, 'cash_out' => 20000, 'shares' => 100],
            ['company_id' => $held, 'day' => $today, 'credits' => 0, 'net_wealth' => 0, 'drones' => 1, 'cash_out' => 24000, 'shares' => 100]]);
        $gone = DB::table('companies')->insertGetId(['user_id' => $other->user_id, 'owner_id' => $other->user_id, 'startup' => 0,
            'name' => 'Gone Co ' . uniqid(), 'credits' => 0, 'house' => 0, 'founded_t' => $now - 30 * 86400,
            'eliminated_at' => $now + 1, 'end_cause' => 'dissolved']);
        DB::table('player_shares')->insert(['user_id' => $p->user_id, 'company_id' => $gone, 'units' => 2]);
        // A price alert the House bids past
        DB::table('resources')->insertOrIgnore(['id' => 4311, 'name' => 'Pumps (A1)', 'mass' => 1, 'capacity' => 0, 'slot_size' => 0]);
        DB::table('stations')->delete();
        DB::table('stations')->insert(['id' => 2, 'name' => 'Moon Depot', 'object_id' => 10, 'budget_cap' => 1000,
            'refill_per_day' => 1000, 'swing_permille' => 200, 'cycle_days' => 7, 'shock_permille' => 300]);
        $house = DB::table('agents')->insertGetId(['name' => 'Moon Depot ' . uniqid(), 'user_id' => $other->user_id, 'company_id' => $held,
            'object_id' => 10, 'hull_type' => 1, 'station_id' => 2]);
        DB::table('station_goods')->delete();
        DB::table('station_goods')->insert(['station_id' => 2, 'resource' => 4311, 'side' => 1, 'price' => 900, 'daily_units' => 16, 'from_day' => 0, 'steady' => 1]);
        DB::table('latest_orders')->insert(['drone' => $house, 'resource' => 4311, 'event_type' => 5, 'event_id' => 0, 'price' => 900, 'quantity' => 12, 'units' => 0]);
        $this->actingAs($p)->post('/account/alerts', ['resource' => 4311, 'place' => 10, 'above' => 850])->assertRedirect('/account#notices');
        $this->actingAs($p)->post('/account/alerts', ['resource' => 4311, 'place' => 3, 'above' => 850])->assertSessionHasErrors('alert');
        $this->actingAs($p)->get('/account')->assertOk()->assertSee('Your price alerts')->assertSee('Pumps (A1) over 850');

        $sent = Notices::runIfDue(true);
        $this->assertSame([1, 1], [$sent['daily'], $sent['fills']]);
        $mails = $this->sent('trade.pilot@example.com');
        $daily = collect($mails)->firstWhere('heading', 'What needs you in Ex Machinis');
        $byKind = collect($daily->items)->keyBy('kind');
        $this->assertStringContainsString('75 credits it earned went to the bank', $byKind['at_cap']['text']);
        $this->assertStringContainsString("can't fill: its shares don't trade now", $byKind['orders']['text']);
        $this->assertStringContainsString('paid you 800 credits for 4', $byKind['bank_paid']['text']);
        $this->assertStringContainsString('less than the 250', $byKind['bank_paid']['text']);
        $this->assertStringContainsString('rose 20% over 7 game days, from 200 to 240', $byKind['cash_out']['text']);
        $this->assertStringContainsString('has ended (it had no credits for too long', $byKind['ended']['text']);
        $this->assertStringContainsString('bids 900 for Pumps (A1)', $byKind['price_alerts']['text']);
        $this->assertStringContainsString('over your 850', $byKind['price_alerts']['text']);
        $fills = collect($mails)->firstWhere('heading', 'Your share orders filled');
        $this->assertCount(1, $fills->items, 'only the fill after turning it on');
        $this->assertStringContainsString('You bought 3', $fills->items[0]['text']);
        $this->assertStringContainsString('from Other Trader at 210', $fills->items[0]['text']);

        // Not again: the fill and payment are told, the day's e-mail is sent
        Mail::fake();
        $this->assertSame(0, array_sum(Notices::runIfDue(true)));
        // Alerts can be removed, only by their owner
        $alert = (int) DB::table('notice_alerts')->where('user_id', $p->user_id)->value('id');
        $this->actingAs($other)->post('/account/alerts/' . $alert . '/remove')->assertNotFound();
        $this->actingAs($p)->post('/account/alerts/' . $alert . '/remove')->assertRedirect('/account#notices');
        $this->assertSame(0, DB::table('notice_alerts')->where('user_id', $p->user_id)->count());
        unset($order);
    }
}
