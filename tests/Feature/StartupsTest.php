<?php

namespace Tests\Feature;

use App\Services\CodePush;
use App\Services\Players;
use App\Services\Startups;
use App\Services\World;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Startups (#637, step 2 of the new rules in #635): every company starts in
 * debt to the bank, its player can reprogram, close or (once it can pay)
 * mature it, and a guest's can be claimed. The engine's trading rules are
 * checked by test_startup.c.
 */
class StartupsTest extends TestCase
{
    use DatabaseTransactions;

    private const SCRIPT = "function launched() wait(60) end";

    protected function setUp(): void
    {
        parent::setUp();
        config(['forge.new_rules' => true, 'forge.startup_max' => 5, 'forge.startup_hull_value' => 2000,
            'forge.startup_fee' => 500, 'forge.startup_push_fee' => 100, 'forge.starting_credits' => 3000,
            'forge.startup_guest_per_day' => 5, 'forge.player_share_credits' => 5000,
            'forge.startup_mature_margin' => 500]);
        Mail::fake();
    }

    private function player(string $name, bool $verified = true): User
    {
        $email = strtolower(str_replace(' ', '.', $name)) . '@example.com';
        $user = Players::create($name, 'long-enough', $email);
        if ($verified) {
            Players::verify($user->user_id, sha1($email));
        }
        return User::where('user_id', $user->user_id)->first();
    }

    private function company(int $id): object
    {
        return DB::table('companies')->where('company_id', $id)->first();
    }

    public function testAStartupStartsInDebt()
    {
        $owner = $this->player('Seed Grower');
        $made = Startups::start($owner, 'Sprout Co', self::SCRIPT);
        $this->assertTrue($made['ok']);
        $this->assertNull($made['claim_code']);
        $c = $this->company($made['company']);
        $this->assertSame(1, (int) $c->startup);
        $this->assertSame((int) $owner->user_id, (int) $c->owner_id);
        $this->assertSame(5500, (int) $c->debt, 'its hull, its starting credits and the fee');
        $this->assertSame(3000, (int) $c->credits);
        $this->get('/companies/' . $made['company'])->assertOk()->assertSee('A startup: it owes the bank 5,500 credits');
        $this->actingAs($owner)->get('/account')->assertOk()->assertSee('Sprout Co')->assertSee('5,500')->assertDontSee('>Mature<', false);
    }

    public function testFiveSlotsAndAClosedOneWaitsAGameDay()
    {
        $owner = $this->player('Busy Grower');
        $ids = [];
        foreach (range(1, 5) as $i) {
            $ids[] = Startups::start($owner, "Busy $i", self::SCRIPT)['company'];
        }
        $sixth = Startups::start($owner, 'Busy 6', self::SCRIPT);
        $this->assertFalse($sixth['ok']);
        $this->assertSame(409, $sixth['status']);

        // Closing one frees its slot only after a game day
        $this->assertTrue(Startups::close($owner, $ids[0])['ok']);
        $this->assertFalse(Startups::start($owner, 'Busy 6', self::SCRIPT)['ok']);
        DB::table('companies')->where('company_id', $ids[0])->update(['eliminated_at' => World::gameNow() - 86401]);
        $this->assertTrue(Startups::start($owner, 'Busy 6', self::SCRIPT)['ok']);
    }

    public function testAStartupsCreditsAreCapped()
    {
        // #672: at most twice its debt; the events engine sends a sale's excess
        // to the bank and records it, and the account page and API show it
        DB::table('house_rules')->updateOrInsert(['id' => 1], ['tax_period' => 0, 'tax_grace' => 0, 'tax_flat' => 0,
            'tax_permille' => 0, 'plunder_share' => 50, 'compute_tax_per_million' => 15, 'startup_credit_cap_permille' => 2000]);
        $owner = $this->player('Capped Grower');
        $id = Startups::start($owner, 'Capped Co', self::SCRIPT)['company'];
        DB::table('startup_skims')->insert(['company_id' => $id, 'game_t' => World::gameNow() + 60, 'amount' => 750]);
        $s = Startups::of($owner)['startups'][0];
        $this->assertSame(11000, $s['credit_cap'], 'twice its 5,500 debt');
        $this->assertSame(750, $s['to_bank']);
        $this->actingAs($owner)->get('/account')->assertOk()->assertSee('11,000')->assertSee('750');
        $this->assertSame(750, \App\Services\PlayerBoards::digest($owner, false)['companies'][0]['to_bank']);

        // #684: push fees go on its debt but don't raise the cap
        $this->assertTrue(Startups::reprogram($owner, $id, self::SCRIPT)['ok'], 'the first push is free');
        $this->assertTrue(Startups::reprogram($owner, $id, self::SCRIPT)['ok']);
        $this->assertTrue(Startups::reprogram($owner, $id, self::SCRIPT)['ok']);
        $s = Startups::of($owner)['startups'][0];
        $this->assertSame(5700, $s['debt'], 'two paid pushes of 100');
        $this->assertSame(11000, $s['credit_cap'], 'still twice its 5,500 starting debt');
    }

    public function testTheReleasePageExplainsStartups()
    {
        // #656: the new rules' copy, and no push password
        $this->get('/release')->assertOk()->assertSee('Every release is a startup')->assertSee('5,500')
            ->assertDontSee('Push password')->assertDontSee('starter=trader')->assertSee('starter=miner');
    }

    public function testClosingAStartup()
    {
        $owner = $this->player('Closer');
        $id = Startups::start($owner, 'Short Lived', self::SCRIPT)['company'];
        $drone = (int) DB::table('agents')->where('company_id', $id)->value('agent_id');
        $this->actingAs($owner)->post("/startups/$id/close")->assertRedirect('/account#startups')->assertSessionHas('success');
        $c = $this->company($id);
        $this->assertNotNull($c->eliminated_at);
        $this->assertSame('closed', $c->end_cause);
        $this->assertSame(0, (int) $c->debt, 'its debt is cancelled');
        $this->assertSame(0, (int) $c->credits);
        $a = DB::table('agents')->where('agent_id', $drone)->first();
        $this->assertNotNull($a->wrecked_at);
        $this->assertNotNull($a->plundered_at, 'nobody can plunder what it held');
        $this->assertSame(0, DB::table('holdings')->where('drone', $drone)->count(), 'its hold is empty (#666)');
        // Its post-mortem says it was closed, not plundered (#654)
        $this->get("/spacecraft/$drone")->assertOk()->assertSee('Its player closed its startup')
            ->assertDontSee('it was plundered by');
        $this->assertFalse(Startups::close($owner, $id)['ok'], 'once');
        // Only its own player
        $other = $this->player('Not Mine');
        $theirs = Startups::start($other, 'Theirs', self::SCRIPT)['company'];
        $this->assertSame(404, Startups::close($owner, $theirs)['status']);
    }

    public function testReprogrammingAddsTheFeeToItsDebt()
    {
        $owner = $this->player('Tinkerer');
        $id = Startups::start($owner, 'Tinker Co', self::SCRIPT)['company'];
        $drone = (int) DB::table('agents')->where('company_id', $id)->value('agent_id');
        $before = DB::table('commands')->where('agent_id', $drone)->count();
        $result = Startups::reprogram($owner, $id, "function launched() wait(120) end");
        $this->assertTrue($result['ok']);
        $c = $this->company($id);
        $this->assertSame(5500, (int) $c->debt, 'its first push is free (#658)');
        $this->assertSame(3000, (int) $c->credits, 'its credits untouched');
        $this->assertSame(1, (int) $c->pushes);
        $this->assertGreaterThan($before, DB::table('commands')->where('agent_id', $drone)->count(), 'the code is on its way');
        $this->assertSame(0, (int) DB::table('code_pushes')->where('company_id', $id)->value('fee'));
        $this->assertSame(422, Startups::reprogram($owner, $id, 'function nope(')['status']);

        // The old password push is not for startups
        $this->assertSame(403, CodePush::push($id, 'whatever', self::SCRIPT)['status']);
        $this->actingAs($owner)->post("/startups/$id/push", ['script' => "function launched() wait(30) end"])
            ->assertSessionHas('success');
        $this->assertSame(5600, (int) $this->company($id)->debt, 'the next costs the fee');
        // The push preview says so (#653)
        $this->postJson('/api/v1/check', ['script' => self::SCRIPT, 'company' => $id])->assertOk()
            ->assertJsonPath('push.startup', true)->assertJsonPath('push.fee', 100)->assertJsonPath('push.debt_after', 5700);
    }

    public function testMaturing()
    {
        $owner = $this->player('Harvester');
        $id = Startups::start($owner, 'Ripe Co', self::SCRIPT)['company'];
        $this->assertSame(409, Startups::mature($owner, $id)['status'], 'it can\'t pay its debt yet');
        // Its debt alone isn't enough: it keeps 500 to work with (#664)
        DB::table('companies')->where('company_id', $id)->update(['credits' => 5800]);
        $this->assertFalse(Startups::of($owner)['startups'][0]['can_mature']);
        $r = Startups::mature($owner, $id);
        $this->assertSame(409, $r['status']);
        $this->assertStringContainsString('6,000', $r['error']);

        DB::table('companies')->where('company_id', $id)->update(['credits' => 8000]);
        $this->actingAs($owner)->get('/account')->assertSee('>Mature<', false);
        $unverified = $this->player('Unverified', false);
        $theirs = Startups::start($unverified, 'Unripe', self::SCRIPT)['company'];
        DB::table('companies')->where('company_id', $theirs)->update(['credits' => 8000]);
        $this->assertSame(403, Startups::mature($unverified, $theirs)['status'], 'a verified account matures');

        $this->actingAs($owner)->post("/startups/$id/mature")->assertSessionHas('success');
        $c = $this->company($id);
        $this->assertSame(0, (int) $c->startup);
        $this->assertSame(0, (int) $c->debt);
        $this->assertSame(2500, (int) $c->credits, 'it repaid 5,500');
        $this->assertNotNull($c->matured_t);
        $this->assertSame(100, (int) DB::table('player_shares')->where('user_id', $owner->user_id)->where('company_id', $id)->value('units'));
        $this->get('/companies/' . $id)->assertSee('its code is locked for good');
        $this->assertSame(403, CodePush::push($id, 'whatever', self::SCRIPT)['status'], 'nobody can push to it');
        $this->assertSame(409, Startups::reprogram($owner, $id, self::SCRIPT)['status']);
        $this->assertSame(409, Startups::close($owner, $id)['status']);
        // It no longer takes a slot
        $this->assertSame(0, Startups::slotsInUse((int) $owner->user_id));
    }

    /** #871: only a player founds a startup; a guest's founded before then can still be claimed */
    public function testOnlyAPlayerFoundsAStartupAndAnOldGuestsCanBeClaimed()
    {
        $this->get('/release')->assertOk()->assertSee('id="needs-account"', false)->assertDontSee('>Found startup</button>', false);
        $this->post('/release', ['company_name' => 'Guest Sprout', 'script' => self::SCRIPT])->assertRedirect()
            ->assertSessionHasErrors('company_name');
        $this->assertFalse(DB::table('companies')->where('name', 'Guest Sprout')->exists());
        $this->assertSame(401, Startups::start(null, 'Guest Two', self::SCRIPT, '10.0.0.9')['status']);

        // A guest's startup from before the change: still claimable, once
        $owner = $this->player('Claimer');
        $id = Startups::start($owner, 'Old Guest Sprout', self::SCRIPT)['company'];
        $code = 'an-old-guest-code-of-24ch';
        DB::table('companies')->where('company_id', $id)->update(['owner_id' => null, 'guest_key' => hash('sha256', $code)]);
        $this->assertFalse(Startups::claim($owner, 'not-the-code')['ok']);
        $this->actingAs($owner)->post('/startups/claim', ['code' => $code])->assertSessionHas('success');
        $c = $this->company($id);
        $this->assertSame((int) $owner->user_id, (int) $c->owner_id);
        $this->assertNull($c->guest_key);
        $this->assertFalse(Startups::claim($this->player('Late'), $code)['ok'], 'once');
        $this->actingAs($owner)->get('/release')->assertOk()->assertSee('>Found startup</button>', false);
    }

    /** #872: a name that only looks like another's is refused; spacing is tidied */
    public function testTheTraderStarterIsRefused()
    {
        // #764: a startup trades only with the House, so the Trader would only stop
        $h = ['Authorization' => 'Bearer ' . Players::issueToken($this->player('Trader Fan'))];
        $trader = file_get_contents(base_path('examples/starters/trader.lua'));
        $this->postJson('/api/v1/release', ['company_name' => 'Would Trade', 'script' => $trader], $h)->assertStatus(422)
            ->assertJsonFragment(['error' => \App\Services\Startups::TRADER]);
        $this->assertSame(0, DB::table('companies')->where('name', 'Would Trade')->count());
        // An edited Trader is the player's own code: it goes through
        $this->postJson('/api/v1/release', ['company_name' => 'Own Trade', 'script' => $trader . "\n-- mine\n"], $h)->assertCreated();
    }

    public function testLookAlikeNamesAreRefused()
    {
        $h = ['Authorization' => 'Bearer ' . Players::issueToken($this->player('Namer'))];
        $this->postJson('/api/v1/release', ['company_name' => '  Look   Alike 10 ', 'script' => self::SCRIPT], $h)->assertCreated()
            ->assertJsonPath('company.name', 'Look Alike 10');
        foreach (['Look  Alike 10', 'look alike 10', 'Look Alike lO', "Look\u{200B} Alike 10"] as $twin) {
            $errors = $this->postJson('/api/v1/release', ['company_name' => $twin, 'script' => self::SCRIPT], $h)->assertStatus(422)
                ->json('errors.company_name');
            $this->assertContains("That name looks the same as Look Alike 10's: choose one that reads differently.", $errors, $twin);
        }
        $this->postJson('/api/v1/release', ['company_name' => 'Look Alike 11', 'script' => self::SCRIPT], $h)->assertCreated();
    }

    /** #876: a push that would leave a startup unable to mature under its credit cap is refused */
    public function testAPushThatWouldMakeMaturingImpossibleIsRefused()
    {
        DB::table('house_rules')->updateOrInsert(['id' => 1], ['tax_period' => 0, 'tax_grace' => 0, 'tax_flat' => 0,
            'tax_permille' => 0, 'plunder_share' => 50, 'compute_tax_per_million' => 15, 'startup_credit_cap_permille' => 2000]);
        $owner = $this->player('Pusher Too');
        $id = Startups::start($owner, 'Push Limit Co', self::SCRIPT)['company'];
        $c = $this->company($id);
        $cap = Startups::creditCap($c);
        $this->assertNotNull($cap);
        // Paid pushes have raised its debt to just under the line
        $fees = $cap - Startups::matureMargin() - (int) $c->debt;
        DB::table('code_pushes')->insert(['company_id' => $id, 'number' => 1, 'game_t' => 0, 'credits_before' => 0,
            'permille' => 0, 'fee' => $fees, 'drones' => 1, 'script' => 'x']);
        DB::table('companies')->where('company_id', $id)->update(['debt' => (int) $c->debt + $fees, 'pushes' => 2]);
        $this->assertSame($cap, Startups::creditCap($this->company($id)), 'push fees leave the cap where it was');
        $r = Startups::reprogram($owner, $id, self::SCRIPT);
        $this->assertSame(409, $r['status']);
        $this->assertStringContainsString('so it could never mature', $r['error']);
    }

    public function testTheApi()
    {
        $owner = $this->player('Api Grower');
        $token = Players::issueToken($owner);
        $h = ['Authorization' => 'Bearer ' . $token];
        $made = $this->postJson('/api/v1/release', ['company_name' => 'Api Sprout', 'script' => self::SCRIPT], $h)
            ->assertCreated()->assertJsonPath('startup', true)->assertJsonPath('debt', 5500)->assertJsonPath('claim_code', null);
        $id = $made->json('company.id');
        $this->getJson("/api/v1/companies/$id")->assertOk()->assertJsonPath('startup', true)->assertJsonPath('debt', 5500)
            ->assertJsonPath('pushes.startup', true)->assertJsonPath('pushes.next_fee', 0)->assertJsonPath('shares.count', 0);
        $this->getJson('/api/v1/startups', $h)->assertOk()->assertJsonPath('startups.0.company_id', $id)
            ->assertJsonPath('startups.0.can_mature', false)->assertJsonPath('slots.used', 1);
        $this->postJson("/api/v1/startups/$id/push", ['script' => "function launched() wait(5) end"], $h)->assertOk()
            ->assertJsonPath('debt', 5500);
        $this->postJson("/api/v1/startups/$id/mature", [], $h)->assertStatus(409);
        DB::table('companies')->where('company_id', $id)->update(['credits' => 6000]);
        $this->postJson("/api/v1/startups/$id/mature", [], $h)->assertOk()->assertJsonPath('shares', 100);
        $this->getJson('/api/v1/startups', $h)->assertJsonPath('companies.0.company_id', $id)->assertJsonCount(0, 'startups');
        $this->getJson('/api/v1/startups')->assertStatus(401);

        // Without a token, no startup (#871)
        $this->postJson('/api/v1/release', ['company_name' => 'Api Guest', 'script' => self::SCRIPT])->assertStatus(401)
            ->assertJsonPath('error', Startups::NEEDS_ACCOUNT);
    }

    public function testTheOldRulesAreUntouched()
    {
        config(['forge.new_rules' => false]);
        $this->post('/release', ['company_name' => 'Old Rules Co', 'script' => self::SCRIPT])->assertRedirect();
        $c = DB::table('companies')->where('name', 'Old Rules Co')->first();
        $this->assertSame(0, (int) $c->startup);
        $this->assertSame(0, (int) $c->debt);
        $this->assertNull($c->owner_id);
        $this->postJson('/api/v1/startups/claim', ['code' => 'x'])->assertNotFound();
    }
}
