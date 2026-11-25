<?php

namespace Tests\Feature;

use App\Services\Players;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use App\Mail\VerifyAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Player accounts (#636, step 1 of the new rules in #635): a name and a
 * password, starting credits for shares, an API token, and teachers' class
 * groups. All of it is behind FORGE_NEW_RULES.
 */
class PlayerAccountsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['forge.new_rules' => true, 'forge.player_share_credits' => 5000, 'forge.player_signups_per_hour' => 3,
            'forge.class_group_max' => 40]);
        RateLimiter::clear('signup|127.0.0.1');
        Mail::fake();
    }

    /** The link the verification mail sent to an address carries */
    private function linkSentTo(string $email): string
    {
        $url = null;
        Mail::assertSent(VerifyAccount::class, function ($mail) use ($email, &$url) {
            if ($mail->hasTo($email)) {
                $url = $mail->url;
                return true;
            }
            return false;
        });
        return $url;
    }

    /** A verified player account */
    private function verifiedPlayer(string $name): \App\User
    {
        $email = strtolower(str_replace(' ', '.', $name)) . '@example.com';
        $user = Players::create($name, 'long-enough', $email);
        $this->assertNotNull(Players::verify($user->user_id, sha1($email)));
        return \App\User::where('user_id', $user->user_id)->first();
    }

    public function testEverythingIsHiddenWhileTheNewRulesAreOff()
    {
        config(['forge.new_rules' => false]);
        $this->get('/signup')->assertNotFound();
        $this->get('/login')->assertNotFound();
        $this->get('/account')->assertNotFound();
        $this->postJson('/api/v1/accounts', ['name' => 'Nobody', 'email' => 'no@example.com', 'password' => 'long-enough'])->assertNotFound();
        $this->get('/')->assertOk()->assertDontSee('href="' . url('login') . '"', false);
    }

    public function testSignUpVerifyLogInAndOut()
    {
        $this->get('/signup')->assertOk()->assertSee("Don't use your real name", false)->assertSee('one account per address');
        // The password once (#1041), and straight on to founding a startup
        $this->post('/signup', ['name' => 'Star Hauler', 'email' => ' Star@Example.com ', 'password' => 'long-enough'])
            ->assertRedirect('/release');
        $this->assertAuthenticated();
        $row = DB::table('users')->where('login', 'Star Hauler')->first();
        $this->assertSame('star@example.com', $row->email, 'kept trimmed and in lower case');
        $this->assertNull($row->email_verified_at);
        $this->assertSame(0, (int) $row->share_credits, 'no share credits until the address is verified');
        $this->assertSame(0, (int) $row->credits);
        $this->assertNotSame('long-enough', $row->password, 'kept as a hash');
        $this->get('/account')->assertOk()->assertSee('Verify your e-mail address')->assertSee('star@example.com')
            ->assertDontSee('Make the group');

        // The link in the mail verifies it, once, and brings the share credits
        $link = $this->linkSentTo('star@example.com');
        $this->assertStringContainsString('/account/verify/' . $row->user_id . '/', $link);
        $mail = new VerifyAccount('Star Hauler', $link);
        $html = $mail->render();
        $this->assertStringContainsString('>Verify my account</a>', $html, 'a button (#754)');
        $this->assertStringContainsString('href="' . e($link) . '"', $html);
        $this->assertStringContainsString('5,000 credits</strong> for buying shares', $html);
        $text = view($mail->build()->textView, $mail->buildViewData())->render();
        $this->assertStringContainsString($link, $text, 'and the plain text for text-only clients');
        $this->assertStringContainsString('5,000 credits for buying shares', $text);
        $this->get($link)->assertRedirect('/account');
        $this->get($link)->assertRedirect('/account');
        $row = DB::table('users')->where('login', 'Star Hauler')->first();
        $this->assertNotNull($row->email_verified_at);
        $this->assertSame(5000, (int) $row->share_credits, 'granted once');
        $this->get('/account')->assertOk()->assertSee('5,000')->assertDontSee('Verify your e-mail address')->assertSee('Make the group');

        // A tampered or unsigned link does nothing
        $this->get(str_replace('/' . $row->user_id . '/', '/' . ($row->user_id + 1) . '/', $link))->assertForbidden();
        $this->get('/account/verify/' . $row->user_id . '/' . sha1('star@example.com'))->assertForbidden();

        $this->post('/logout')->assertRedirect('/');
        $this->assertGuest();
        $this->post('/login', ['name' => 'star hauler', 'password' => 'wrong-one'])->assertSessionHasErrors('name');
        $this->assertGuest();
        $this->post('/login', ['name' => 'star hauler', 'password' => 'long-enough'])->assertRedirect('/account');
        $this->assertAuthenticated();
        $this->get('/')->assertSee('href="' . url('account') . '"', false);
        $this->assertStringNotContainsString('star@example.com', $this->get('/leaderboard')->getContent(), 'the address is private');
    }

    /** A trial without a mail service (#642): accounts are verified at once */
    public function testATrialVerifiesAtOnce()
    {
        config(['forge.trial_auto_verify' => true]);
        $user = Players::create('Trial Player', 'long-enough', 'trial@example.com');
        $this->assertTrue(Players::verified($user));
        $this->assertSame(5000, (int) DB::table('users')->where('user_id', $user->user_id)->value('share_credits'));
    }

    public function testResendingTheLink()
    {
        $user = Players::create('Slow Reader', 'long-enough', 'slow@example.com');
        RateLimiter::clear('verify-mail|' . $user->user_id);
        $this->actingAs($user);
        foreach (range(1, 3) as $i) {
            $this->post('/account/verify')->assertRedirect('/account')->assertSessionHas('success');
        }
        $this->post('/account/verify')->assertSessionHas('error');
        Mail::assertSent(VerifyAccount::class, 3);
        $this->assertFalse(Players::verified($user));
    }

    public function testNamesPasswordsAndAddresses()
    {
        Players::create('Taken Name', 'long-enough', 'taken@example.com');
        $this->expectProblem(fn () => Players::create('taken name', 'long-enough', 'other@example.com'), 'taken', 'names ignore case');
        $this->expectProblem(fn () => Players::create('ab', 'long-enough', 'ab@example.com'), '3 to 32');
        $this->expectProblem(fn () => Players::create('<script>', 'long-enough', 'x@example.com'), '3 to 32');
        $this->expectProblem(fn () => Players::create('Fine Name', 'short', 'fine@example.com'), 'at least 8');
        $this->expectProblem(fn () => Players::create('Fine Name', 'long-enough', 'TAKEN@example.com'), 'already uses', 'one account per address');
        $this->expectProblem(fn () => Players::create('Fine Name', 'long-enough', 'not-an-address'), "doesn't look", '');
        $this->expectProblem(fn () => Players::create('Fine Name', 'long-enough', null), "doesn't look", 'a player needs one');
        $this->post('/signup', ['name' => 'No Password', 'email' => 'np@example.com'])->assertSessionHasErrors('password');
        $this->post('/signup', ['name' => 'No Mail', 'password' => 'long-enough', 'password_confirmation' => 'long-enough'])
            ->assertSessionHasErrors('email');
        // The game's own owners have no login and can't sign in
        $this->assertNull(Players::authenticate('Public Forge', 'anything'));
        Mail::assertNotSent(VerifyAccount::class, fn ($m) => $m->hasTo('fine@example.com'));
    }

    public function testOneAddressMakesOnlySoManyAccountsAnHour()
    {
        foreach (['First One', 'Second One', 'Third One'] as $i => $name) {
            $this->postJson('/api/v1/accounts', ['name' => $name, 'email' => "n$i@example.com", 'password' => 'long-enough'])->assertCreated();
        }
        $this->postJson('/api/v1/accounts', ['name' => 'Fourth One', 'email' => 'n4@example.com', 'password' => 'long-enough'])->assertStatus(429);
        $this->post('/signup', ['name' => 'Fifth One', 'email' => 'n5@example.com', 'password' => 'long-enough',
            'password_confirmation' => 'long-enough'])->assertSessionHasErrors('name');
        $this->assertFalse(DB::table('users')->whereIn('login', ['Fourth One', 'Fifth One'])->exists());
    }

    public function testTheApiToken()
    {
        $made = $this->postJson('/api/v1/accounts', ['name' => 'Bot Owner', 'email' => 'bot@example.com', 'password' => 'long-enough'])
            ->assertCreated()->assertJsonPath('name', 'Bot Owner')->assertJsonPath('share_credits', 0)
            ->assertJsonPath('verified', false)->assertJsonPath('verification_sent', true)->assertJsonPath('email', 'bot@example.com');
        $this->postJson('/api/v1/accounts', ['name' => 'Bot Two', 'email' => 'BOT@example.com', 'password' => 'long-enough'])
            ->assertStatus(422)->assertJsonPath('error', 'An account already uses that e-mail address.');
        $token = $made->json('token');
        $this->assertSame(48, strlen($token));
        $this->getJson('/api/v1/account', ['Authorization' => 'Bearer ' . $token])->assertOk()->assertJsonPath('name', 'Bot Owner');
        $this->getJson('/api/v1/account')->assertStatus(401);
        $this->assertNotSame($token, DB::table('users')->where('login', 'Bot Owner')->value('api_token'), 'kept as a hash');

        // Logging in again gives a new token, and the old one stops working
        $this->postJson('/api/v1/login', ['name' => 'Bot Owner', 'password' => 'nope-nope'])->assertStatus(401);
        $new = $this->postJson('/api/v1/login', ['name' => 'Bot Owner', 'password' => 'long-enough'])->assertOk()->json('token');
        $this->getJson('/api/v1/account', ['Authorization' => 'Bearer ' . $token])->assertStatus(401);
        $this->getJson('/api/v1/account', ['Authorization' => 'Bearer ' . $new])->assertOk();

        // Sending the link again, then verifying
        $id = (int) DB::table('users')->where('login', 'Bot Owner')->value('user_id');
        RateLimiter::clear('verify-mail|' . $id);
        $this->postJson('/api/v1/account/verify', [], ['Authorization' => 'Bearer ' . $new])->assertOk()->assertJsonPath('verification_sent', true);
        $this->get($this->linkSentTo('bot@example.com'))->assertRedirect('/login');
        $this->getJson('/api/v1/account', ['Authorization' => 'Bearer ' . $new])->assertJsonPath('verified', true)
            ->assertJsonPath('share_credits', 5000);
        $this->postJson('/api/v1/account/verify', [], ['Authorization' => 'Bearer ' . $new])->assertStatus(409);
    }

    public function testATeachersClassGroup()
    {
        $unverified = Players::create('Not Yet', 'long-enough', 'notyet@example.com');
        $this->expectProblem(fn () => Players::createClassGroup($unverified, 'Early', 3), 'Verify your e-mail');
        $teacher = $this->verifiedPlayer('Ms Teacher');
        $this->actingAs($teacher);
        $page = $this->post('/account/classes', ['group_name' => 'Orbit', 'pupils' => 3])->assertOk()
            ->assertSee('Orbit 1')->assertSee('Orbit 3')->assertSee("won't be shown again", false);
        $group = (int) DB::table('class_groups')->where('teacher_id', $teacher->user_id)->value('id');
        $pupils = DB::table('users')->where('class_group_id', $group)->orderBy('user_id')->get();
        $this->assertSame(['Orbit 1', 'Orbit 2', 'Orbit 3'], $pupils->pluck('login')->all());
        $this->assertTrue($pupils->every(fn ($p) => $p->email === null && (int) $p->share_credits === 5000),
            'pupils need no e-mail and get their share credits at once');
        $this->assertTrue(Players::verified(\App\User::where('login', 'Orbit 1')->first()));
        Mail::assertNotSent(VerifyAccount::class, fn ($m) => str_contains($m->name, 'Orbit'));

        // The list shows each password once: one of them logs a pupil in
        preg_match('/Orbit 2<\/td><td><code>([a-z0-9-]+)<\/code>/', $page->getContent(), $m);
        $this->assertNotEmpty($m, 'the passwords are on the page');
        $this->assertNotNull(Players::authenticate('Orbit 2', $m[1]));

        // A lost password: the teacher gives a new one, and the old stops working
        $reset = $this->post("/account/classes/$group/reset", ['pupil' => 'Orbit 2'])->assertRedirect("/account/classes/$group");
        $password = session('reset')['password'];
        $this->assertNull(Players::authenticate('Orbit 2', $m[1]));
        $this->assertNotNull(Players::authenticate('Orbit 2', $password));
        $this->get("/account/classes/$group")->assertOk()->assertSee('Orbit 3');

        // Only the teacher's own groups and pupils
        $this->post("/account/classes/$group/reset", ['pupil' => 'Ms Teacher'])->assertSessionHasErrors('pupil');
        $other = $this->verifiedPlayer('Mr Other');
        $this->actingAs($other)->get("/account/classes/$group")->assertNotFound();
        $this->assertNull(Players::resetPupilPassword($other, 'Orbit 1'));

        // A pupil sees their group, and can't make one
        $this->actingAs(\App\User::where('login', 'Orbit 1')->first())->get('/account')->assertOk()
            ->assertSee('Class group')->assertDontSee('Make the group');
    }

    public function testClassGroupLimits()
    {
        $teacher = $this->verifiedPlayer('Big Class');
        $this->expectProblem(fn () => Players::createClassGroup($teacher, 'Huge', 41), '1 to 40');
        $this->expectProblem(fn () => Players::createClassGroup($teacher, 'Empty', 0), '1 to 40');
        Players::create('Comet 2', 'long-enough', 'comet2@example.com');
        $this->expectProblem(fn () => Players::createClassGroup($teacher, 'Comet', 3), 'Comet 2');
        $this->assertFalse(DB::table('class_groups')->where('name', 'Comet')->exists(), 'nothing half made');
        $this->assertCount(40, Players::createClassGroup($teacher, 'Full', 40)['accounts']);
        $this->expectProblem(fn () => Players::createClassGroup($teacher, 'Full', 2), 'already have');
    }

    private function expectProblem(callable $f, string $needle, string $why = ''): void
    {
        try {
            $f();
            $this->fail("No problem raised ($needle) $why");
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString($needle, $e->getMessage(), $why);
        }
    }
}
