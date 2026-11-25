<?php

namespace Tests\Feature;

use App\Services\Closures;
use App\Services\CreditCheck;
use App\Services\Ownership;
use App\Services\Players;
use App\Services\PlayerShares;
use App\Services\Startups;
use App\Services\World;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Ownership (#923, #924, #925, #931): a company owned outright changes its
 * code for free; the first share sold locks it; no buying back for 7 real
 * days; a takeover unlocks it; at most 5 owned outright; a company ends
 * through its own code; its code is private while owned outright.
 */
class OwnershipTest extends TestCase
{
    use DatabaseTransactions;

    private const SCRIPT = "function launched() wait(60) end";

    protected function setUp(): void
    {
        parent::setUp();
        config(['forge.new_rules' => true, 'forge.startup_hull_value' => 2000, 'forge.startup_fee' => 500,
            'forge.starting_credits' => 3000, 'forge.player_share_credits' => 5000, 'forge.bank_pay_days' => 30,
            'forge.share_range_low' => 0.8, 'forge.share_range_high' => 1.25, 'forge.hull_life_days' => 365,
            'forge.dividend_permille' => 500, 'forge.startup_max' => 5, 'forge.startup_mature_margin' => 0]);
        Mail::fake();
    }

    private function player(string $name, int $credits = 0): User
    {
        $email = strtolower(str_replace(' ', '.', $name)) . '@example.com';
        $user = Players::create($name, 'long-enough', $email);
        $this->canInvest((int) $user->user_id);   // buyers here are investors (#1040)
        Players::verify($user->user_id, sha1($email));
        DB::table('users')->where('user_id', $user->user_id)->update(['credits' => $credits, 'share_credits' => 0]);
        return User::where('user_id', $user->user_id)->first();
    }

    private function matured(User $owner, string $name, int $credits = 100000): int
    {
        $id = Startups::start($owner, $name, self::SCRIPT)['company'];
        DB::table('companies')->where('company_id', $id)->update(['credits' => $credits + Startups::grant()]);
        $this->assertTrue(Startups::mature($owner, $id)['ok']);
        return $id;
    }

    public function testTheFirstShareSoldLocksTheCodeAndNoBuyingBackForAWeek()
    {
        $owner = $this->player('Lock Owner');
        $buyer = $this->player('Lock Buyer', 1000000);
        $id = $this->matured($owner, 'Lock Co');
        $this->assertSame((int) $owner->user_id, Ownership::owner($id), 'all 100 shares: owned outright');
        $this->assertFalse(Ownership::codePublic($id));
        $this->assertTrue(Startups::reprogram($owner, $id, self::SCRIPT)['ok'], 'its code is still its owner\'s to change, for free');
        $this->assertSame(0, (int) DB::table('code_pushes')->where('company_id', $id)->value('fee'));

        // Shares offered: the code is public, and can't change while they stand
        $p = PlayerShares::prices($id);
        $order = PlayerShares::order($owner, $id, 'sell', 10, $p['high']);
        $this->assertSame(10, $order['standing']);
        $this->assertTrue(Ownership::codePublic($id), 'public from the moment shares are offered');
        $this->assertSame(409, Startups::reprogram($owner, $id, self::SCRIPT)['status']);
        // Withdrawn unsold: nothing locks, and a push makes the new code private again
        $this->assertTrue(PlayerShares::cancel($owner, $order['order']));
        $this->assertTrue(Startups::reprogram($owner, $id, self::SCRIPT)['ok']);
        $this->assertFalse(Ownership::codePublic($id));

        // The first share sold, to a player: public, locked for good
        PlayerShares::order($owner, $id, 'sell', 10, $p['sells_at']);
        $this->assertSame(10, PlayerShares::order($buyer, $id, 'buy', 10, $p['sells_at'])['traded']);
        $this->assertNull(Ownership::owner($id));
        $this->assertTrue(Ownership::codePublic($id));
        $this->assertSame(403, Startups::reprogram($owner, $id, self::SCRIPT)['status'], 'locked');
        // The seller can't buy any back for 7 real days
        DB::table('users')->where('user_id', $owner->user_id)->update(['credits' => 1000000]);
        $r = PlayerShares::order($owner, $id, 'buy', 10, $p['sells_at']);
        $this->assertSame(409, $r['status']);
        $this->assertStringContainsString('7 real days', $r['error']);
        $this->travel(Ownership::BAN_DAYS)->days();
        $this->travel(1)->minutes();
        $this->assertNull(Ownership::bannedUntil((int) $owner->user_id, $id));
        $this->travelBack();

        // Selling to the bank counts too
        $other = $this->matured($owner, 'Bank Lock Co');
        $this->assertTrue(PlayerShares::sellToBank($owner, $other, 1)['ok']);
        $this->assertNull(Ownership::owner($other));
        $this->assertNotNull(Ownership::bannedUntil((int) $owner->user_id, $other));
        $this->assertSame(409, PlayerShares::buyFromBank($owner, $other, 1)['status']);
    }

    public function testATakeoverUnlocksTheCode()
    {
        $founder = $this->player('Take Founder');
        $raider = $this->player('Take Raider', 10000000);
        $id = $this->matured($founder, 'Target Co');
        $p = PlayerShares::prices($id);
        PlayerShares::order($founder, $id, 'sell', 100, $p['sells_at']);
        $this->assertSame(99, PlayerShares::order($raider, $id, 'buy', 99, $p['sells_at'])['traded']);
        $this->assertNull(Ownership::owner($id));
        $this->assertSame(403, Startups::reprogram($raider, $id, self::SCRIPT)['status'], '99 of 100 isn\'t all');
        $this->assertSame(1, PlayerShares::order($raider, $id, 'buy', 1, $p['sells_at'])['traded']);
        $this->assertSame((int) $raider->user_id, Ownership::owner($id), 'all 100: owned outright again');
        $this->assertTrue(Startups::reprogram($raider, $id, self::SCRIPT)['ok'], 'a turnaround: the raider rewrites it');
        $this->assertSame(1, Startups::slotsInUse((int) $raider->user_id), 'and it counts against the raider\'s 5');
        $this->assertSame(403, Startups::reprogram($founder, $id, self::SCRIPT)['status']);
        // Its new code is the raider's to read, not the founder's (#931)
        $this->assertFalse(Ownership::codePublic($id));
        $this->assertTrue(Ownership::canSeeCode($id, $raider));
        $this->assertFalse(Ownership::canSeeCode($id, $founder));
    }

    public function testAtMostFiveOwnedOutright()
    {
        $owner = $this->player('Five Owner', 10000000);
        $seller = $this->player('Five Seller');
        $theirs = $this->matured($seller, 'Sold Co');
        $p = PlayerShares::prices($theirs);
        PlayerShares::order($seller, $theirs, 'sell', 100, $p['sells_at']);
        $this->assertSame(50, PlayerShares::order($owner, $theirs, 'buy', 50, $p['sells_at'])['traded']);

        $this->matured($owner, 'Mine 1');
        foreach (range(2, 5) as $n) {
            $this->assertTrue(Startups::start($owner, "Mine $n", self::SCRIPT)['ok']);
        }
        $this->assertSame(5, Startups::slotsInUse((int) $owner->user_id), '4 startups and a matured company owned outright');
        $this->assertSame(409, Startups::start($owner, 'Mine 6', self::SCRIPT)['status']);
        // Buying the rest of a sixth is refused; a part of it isn't
        $r = PlayerShares::order($owner, $theirs, 'buy', 50, $p['sells_at']);
        $this->assertSame(409, $r['status']);
        $this->assertStringContainsString('outright', $r['error']);
        $this->assertSame(49, PlayerShares::order($owner, $theirs, 'buy', 49, $p['sells_at'])['traded']);
    }

    public function testCodeIsPrivateWhileOwnedOutright()
    {
        $teacher = $this->player('Code Teacher');
        Players::createClassGroup($teacher, 'Orion', 1);
        $pupil = User::where('login', 'Orion 1')->first();
        $id = Startups::start($pupil, 'Secret Co', "function launched() set_status('secret sauce') wait(60) end")['company'];
        $drone = (int) DB::table('agents')->where('company_id', $id)->value('agent_id');
        $stranger = $this->player('Code Stranger');

        $this->actingAs($stranger)->get('/companies/' . $id)->assertOk()->assertSee('id="code-private"', false)
            ->assertDontSee('secret sauce');
        $this->actingAs($pupil)->get('/companies/' . $id)->assertOk()->assertSee('secret sauce');
        $this->actingAs($teacher)->get('/companies/' . $id)->assertOk()->assertSee('secret sauce');
        $this->getJson('/api/v1/drones/' . $drone)->assertOk()->assertJsonPath('script', null);
        $this->actingAs($stranger)->get('/release?copy=' . $drone)->assertOk()->assertDontSee('secret sauce');

        // Once it ends, everyone may read it
        DB::table('companies')->where('company_id', $id)->update(['eliminated_at' => World::gameNow(), 'end_cause' => 'closed']);
        $this->actingAs($stranger)->get('/companies/' . $id)->assertOk()->assertSee('secret sauce');
    }

    public function testAMaturedCompanyClosesThroughItsCode()
    {
        $owner = $this->player('Close Owner');
        $holder = $this->player('Close Holder', 1000000);
        $id = $this->matured($owner, 'Closing Co', 10000);
        $p = PlayerShares::prices($id);
        // The Close button: the owner outright pushes code that calls close_company()
        $r = $this->actingAs($owner)->post("/startups/$id/close")->assertSessionHas('success');
        $this->assertSame(Closures::SCRIPT, DB::table('code_pushes')->where('company_id', $id)->orderByDesc('number')->value('script'));

        // A holder and the bank join in; then its code asks to close
        PlayerShares::order($owner, $id, 'sell', 20, $p['sells_at']);
        PlayerShares::order($holder, $id, 'buy', 20, $p['sells_at']);
        $this->assertTrue(PlayerShares::sellToBank($owner, $id, 10)['ok']);
        $drone = (int) DB::table('agents')->where('company_id', $id)->value('agent_id');
        DB::table('holdings')->updateOrInsert(['drone' => $drone, 'resource' => 2011, 'installed' => 0, 'locked' => 0],
            ['units' => 7, 'event_id' => 0]);
        CreditCheck::restart();
        CreditCheck::check();
        $credits = (int) DB::table('companies')->where('company_id', $id)->value('credits');
        [$o, $h] = [(int) DB::table('users')->where('user_id', $owner->user_id)->value('credits'),
            (int) DB::table('users')->where('user_id', $holder->user_id)->value('credits')];
        DB::table('company_closures')->insert(['company_id' => $id, 'drone' => $drone, 'requested_t' => World::gameNow()]);
        $this->assertSame(1, Closures::settle());

        // Its credits went to its shareholders by their shares; the bank's part left the game
        $per = intdiv($credits, 100);
        // (the owner's sale to the bank, not yet paid, is paid now at no more than a share got)
        $bank = 10 * min((int) DB::table('bank_payments')->where('company_id', $id)->value('price_at_sale'), $per);
        $this->assertSame(70 * $per + $bank, (int) DB::table('users')->where('user_id', $owner->user_id)->value('credits') - $o);
        $this->assertSame(20 * $per, (int) DB::table('users')->where('user_id', $holder->user_id)->value('credits') - $h);
        $c = DB::table('companies')->where('company_id', $id)->first();
        $this->assertSame(['closed', 0], [$c->end_cause, (int) $c->credits]);
        // Its drone is a wreck with its hold, for plunderers
        $a = DB::table('agents')->where('agent_id', $drone)->first();
        $this->assertNotNull($a->wrecked_at);
        $this->assertNull($a->plundered_at);
        $this->assertSame(7, (int) DB::table('holdings')->where('drone', $drone)->where('resource', 2011)->value('units'));
        $this->assertTrue(Ownership::codePublic($id));
        $this->assertSame(0, CreditCheck::check()['difference'], 'credits balance');
        $this->assertSame(0, Closures::settle(), 'once');
    }
}
