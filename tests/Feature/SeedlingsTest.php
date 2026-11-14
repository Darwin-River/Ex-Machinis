<?php

namespace Tests\Feature;

use App\Services\CodePush;
use App\Services\Players;
use App\Services\Seedlings;
use App\Services\World;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Seedlings (#637, step 2 of the new rules in #635): every company starts in
 * debt to the bank, its player can reprogram, close or (once it can pay)
 * mature it, and a guest's can be claimed. The engine's trading rules are
 * checked by test_seedling.c.
 */
class SeedlingsTest extends TestCase
{
    use DatabaseTransactions;

    private const SCRIPT = "function launched() wait(60) end";

    protected function setUp(): void
    {
        parent::setUp();
        config(['forge.new_rules' => true, 'forge.seedling_max' => 5, 'forge.seedling_hull_value' => 2000,
            'forge.seedling_fee' => 500, 'forge.seedling_push_fee' => 100, 'forge.starting_credits' => 3000,
            'forge.seedling_guest_per_day' => 5, 'forge.player_share_credits' => 5000]);
        RateLimiter::clear('guest-seedling|127.0.0.1');
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

    public function testASeedlingStartsInDebt()
    {
        $owner = $this->player('Seed Grower');
        $made = Seedlings::start($owner, 'Sprout Co', self::SCRIPT);
        $this->assertTrue($made['ok']);
        $this->assertNull($made['claim_code']);
        $c = $this->company($made['company']);
        $this->assertSame(1, (int) $c->seedling);
        $this->assertSame((int) $owner->user_id, (int) $c->owner_id);
        $this->assertSame(5500, (int) $c->debt, 'its hull, its starting credits and the fee');
        $this->assertSame(3000, (int) $c->credits);
        $this->get('/companies/' . $made['company'])->assertOk()->assertSee('A seedling: it owes the bank 5,500 credits');
        $this->actingAs($owner)->get('/account')->assertOk()->assertSee('Sprout Co')->assertSee('5,500')->assertDontSee('>Mature<', false);
    }

    public function testFiveSlotsAndAClosedOneWaitsAGameDay()
    {
        $owner = $this->player('Busy Grower');
        $ids = [];
        foreach (range(1, 5) as $i) {
            $ids[] = Seedlings::start($owner, "Busy $i", self::SCRIPT)['company'];
        }
        $sixth = Seedlings::start($owner, 'Busy 6', self::SCRIPT);
        $this->assertFalse($sixth['ok']);
        $this->assertSame(409, $sixth['status']);

        // Closing one frees its slot only after a game day
        $this->assertTrue(Seedlings::close($owner, $ids[0])['ok']);
        $this->assertFalse(Seedlings::start($owner, 'Busy 6', self::SCRIPT)['ok']);
        DB::table('companies')->where('company_id', $ids[0])->update(['eliminated_at' => World::gameNow() - 86401]);
        $this->assertTrue(Seedlings::start($owner, 'Busy 6', self::SCRIPT)['ok']);
    }

    public function testClosingASeedling()
    {
        $owner = $this->player('Closer');
        $id = Seedlings::start($owner, 'Short Lived', self::SCRIPT)['company'];
        $drone = (int) DB::table('agents')->where('company_id', $id)->value('agent_id');
        $this->actingAs($owner)->post("/seedlings/$id/close")->assertRedirect('/account#seedlings')->assertSessionHas('success');
        $c = $this->company($id);
        $this->assertNotNull($c->eliminated_at);
        $this->assertSame('closed', $c->end_cause);
        $this->assertSame(0, (int) $c->debt, 'its debt is cancelled');
        $this->assertSame(0, (int) $c->credits);
        $a = DB::table('agents')->where('agent_id', $drone)->first();
        $this->assertNotNull($a->wrecked_at);
        $this->assertNotNull($a->plundered_at, 'nobody can plunder what it held');
        $this->assertFalse(Seedlings::close($owner, $id)['ok'], 'once');
        // Only its own player
        $other = $this->player('Not Mine');
        $theirs = Seedlings::start($other, 'Theirs', self::SCRIPT)['company'];
        $this->assertSame(404, Seedlings::close($owner, $theirs)['status']);
    }

    public function testReprogrammingAddsTheFeeToItsDebt()
    {
        $owner = $this->player('Tinkerer');
        $id = Seedlings::start($owner, 'Tinker Co', self::SCRIPT)['company'];
        $drone = (int) DB::table('agents')->where('company_id', $id)->value('agent_id');
        $before = DB::table('commands')->where('agent_id', $drone)->count();
        $result = Seedlings::reprogram($owner, $id, "function launched() wait(120) end");
        $this->assertTrue($result['ok']);
        $c = $this->company($id);
        $this->assertSame(5500, (int) $c->debt, 'its first push is free (#658)');
        $this->assertSame(3000, (int) $c->credits, 'its credits untouched');
        $this->assertSame(1, (int) $c->pushes);
        $this->assertGreaterThan($before, DB::table('commands')->where('agent_id', $drone)->count(), 'the code is on its way');
        $this->assertSame(0, (int) DB::table('code_pushes')->where('company_id', $id)->value('fee'));
        $this->assertSame(422, Seedlings::reprogram($owner, $id, 'function nope(')['status']);

        // The old password push is not for seedlings
        $this->assertSame(403, CodePush::push($id, 'whatever', self::SCRIPT)['status']);
        $this->actingAs($owner)->post("/seedlings/$id/push", ['script' => "function launched() wait(30) end"])
            ->assertSessionHas('success');
        $this->assertSame(5600, (int) $this->company($id)->debt, 'the next costs the fee');
        // The push preview says so (#653)
        $this->postJson('/api/v1/check', ['script' => self::SCRIPT, 'company' => $id])->assertOk()
            ->assertJsonPath('push.seedling', true)->assertJsonPath('push.fee', 100)->assertJsonPath('push.debt_after', 5700);
    }

    public function testMaturing()
    {
        $owner = $this->player('Harvester');
        $id = Seedlings::start($owner, 'Ripe Co', self::SCRIPT)['company'];
        $this->assertSame(409, Seedlings::mature($owner, $id)['status'], 'it can\'t pay its debt yet');

        DB::table('companies')->where('company_id', $id)->update(['credits' => 8000]);
        $this->actingAs($owner)->get('/account')->assertSee('>Mature<', false);
        $unverified = $this->player('Unverified', false);
        $theirs = Seedlings::start($unverified, 'Unripe', self::SCRIPT)['company'];
        DB::table('companies')->where('company_id', $theirs)->update(['credits' => 8000]);
        $this->assertSame(403, Seedlings::mature($unverified, $theirs)['status'], 'a verified account matures');

        $this->actingAs($owner)->post("/seedlings/$id/mature")->assertSessionHas('success');
        $c = $this->company($id);
        $this->assertSame(0, (int) $c->seedling);
        $this->assertSame(0, (int) $c->debt);
        $this->assertSame(2500, (int) $c->credits, 'it repaid 5,500');
        $this->assertNotNull($c->matured_t);
        $this->assertSame(1000, (int) DB::table('player_shares')->where('user_id', $owner->user_id)->where('company_id', $id)->value('units'));
        $this->get('/companies/' . $id)->assertSee('its code is locked for good');
        $this->assertSame(403, CodePush::push($id, 'whatever', self::SCRIPT)['status'], 'nobody can push to it');
        $this->assertSame(409, Seedlings::reprogram($owner, $id, self::SCRIPT)['status']);
        $this->assertSame(409, Seedlings::close($owner, $id)['status']);
        // It no longer takes a slot
        $this->assertSame(0, Seedlings::slotsInUse((int) $owner->user_id));
    }

    public function testAGuestsSeedlingAndClaimingIt()
    {
        $this->post('/release', ['company_name' => 'Guest Sprout', 'script' => self::SCRIPT])->assertRedirect()
            ->assertSessionHas('info');
        $code = preg_replace('/.*: /', '', session('info'));
        $c = DB::table('companies')->where('name', 'Guest Sprout')->first();
        $this->assertSame(1, (int) $c->seedling);
        $this->assertNull($c->owner_id);
        $this->assertSame(hash('sha256', $code), $c->guest_key);

        $owner = $this->player('Claimer');
        $this->assertFalse(Seedlings::claim($owner, 'not-the-code')['ok']);
        $this->actingAs($owner)->post('/seedlings/claim', ['code' => $code])->assertSessionHas('success');
        $c = $this->company((int) $c->company_id);
        $this->assertSame((int) $owner->user_id, (int) $c->owner_id);
        $this->assertNull($c->guest_key);
        $this->assertFalse(Seedlings::claim($this->player('Late'), $code)['ok'], 'once');
    }

    public function testGuestsStartOnlySoMany()
    {
        foreach (range(1, 5) as $i) {
            $this->assertTrue(Seedlings::start(null, "Guest $i", self::SCRIPT, '10.0.0.9')['ok']);
        }
        $this->assertSame(429, Seedlings::start(null, 'Guest 6', self::SCRIPT, '10.0.0.9')['status']);
        RateLimiter::clear('guest-seedling|10.0.0.9');
    }

    public function testTheApi()
    {
        $owner = $this->player('Api Grower');
        $token = Players::issueToken($owner);
        $h = ['Authorization' => 'Bearer ' . $token];
        $made = $this->postJson('/api/v1/release', ['company_name' => 'Api Sprout', 'script' => self::SCRIPT], $h)
            ->assertCreated()->assertJsonPath('seedling', true)->assertJsonPath('debt', 5500)->assertJsonPath('claim_code', null);
        $id = $made->json('company.id');
        $this->getJson('/api/v1/seedlings', $h)->assertOk()->assertJsonPath('seedlings.0.company_id', $id)
            ->assertJsonPath('seedlings.0.can_mature', false)->assertJsonPath('slots.used', 1);
        $this->postJson("/api/v1/seedlings/$id/push", ['script' => "function launched() wait(5) end"], $h)->assertOk()
            ->assertJsonPath('debt', 5500);
        $this->postJson("/api/v1/seedlings/$id/mature", [], $h)->assertStatus(409);
        DB::table('companies')->where('company_id', $id)->update(['credits' => 6000]);
        $this->postJson("/api/v1/seedlings/$id/mature", [], $h)->assertOk()->assertJsonPath('shares', 1000);
        $this->getJson('/api/v1/seedlings', $h)->assertJsonPath('companies.0.company_id', $id)->assertJsonCount(0, 'seedlings');
        $this->getJson('/api/v1/seedlings')->assertStatus(401);

        // A guest's release through the API comes with a claim code
        $guest = $this->postJson('/api/v1/release', ['company_name' => 'Api Guest', 'script' => self::SCRIPT])->assertCreated();
        $this->postJson('/api/v1/seedlings/claim', ['code' => $guest->json('claim_code')], $h)->assertOk()
            ->assertJsonPath('name', 'Api Guest');
    }

    public function testTheOldRulesAreUntouched()
    {
        config(['forge.new_rules' => false]);
        $this->post('/release', ['company_name' => 'Old Rules Co', 'script' => self::SCRIPT])->assertRedirect();
        $c = DB::table('companies')->where('name', 'Old Rules Co')->first();
        $this->assertSame(0, (int) $c->seedling);
        $this->assertSame(0, (int) $c->debt);
        $this->assertNull($c->owner_id);
        $this->postJson('/api/v1/seedlings/claim', ['code' => 'x'])->assertNotFound();
    }
}
