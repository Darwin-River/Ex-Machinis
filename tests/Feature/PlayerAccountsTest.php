<?php

namespace Tests\Feature;

use App\Services\Players;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
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
    }

    public function testEverythingIsHiddenWhileTheNewRulesAreOff()
    {
        config(['forge.new_rules' => false]);
        $this->get('/signup')->assertNotFound();
        $this->get('/login')->assertNotFound();
        $this->get('/account')->assertNotFound();
        $this->postJson('/api/v1/accounts', ['name' => 'Nobody', 'password' => 'long-enough'])->assertNotFound();
        $this->get('/')->assertOk()->assertDontSee('href="' . url('login') . '"', false);
    }

    public function testSignUpLogInAndOut()
    {
        $this->get('/signup')->assertOk()->assertSee("Don't use your real name", false);
        $this->post('/signup', ['name' => 'Star Hauler', 'password' => 'long-enough', 'password_confirmation' => 'long-enough'])
            ->assertRedirect('/account');
        $this->assertAuthenticated();
        $this->get('/account')->assertOk()->assertSee('Star Hauler')->assertSee('5,000');
        $row = DB::table('users')->where('login', 'Star Hauler')->first();
        $this->assertNull($row->email, 'no e-mail');
        $this->assertSame(5000, (int) $row->share_credits);
        $this->assertSame(0, (int) $row->credits);
        $this->assertNotSame('long-enough', $row->password, 'kept as a hash');

        $this->post('/logout')->assertRedirect('/');
        $this->assertGuest();
        $this->post('/login', ['name' => 'star hauler', 'password' => 'wrong-one'])->assertSessionHasErrors('name');
        $this->assertGuest();
        $this->post('/login', ['name' => 'star hauler', 'password' => 'long-enough'])->assertRedirect('/account');
        $this->assertAuthenticated();
        $this->get('/')->assertSee('href="' . url('account') . '"', false);
    }

    public function testNamesAndPasswords()
    {
        Players::create('Taken Name', 'long-enough');
        $this->expectProblem(fn () => Players::create('taken name', 'long-enough'), 'taken', 'names ignore case');
        $this->expectProblem(fn () => Players::create('ab', 'long-enough'), '3 to 32');
        $this->expectProblem(fn () => Players::create('<script>', 'long-enough'), '3 to 32');
        $this->expectProblem(fn () => Players::create('Fine Name', 'short'), 'at least 8');
        $this->post('/signup', ['name' => 'Mismatch', 'password' => 'long-enough', 'password_confirmation' => 'other-one'])
            ->assertSessionHasErrors('password');
        // The game's own owners have no login and can't sign in
        $this->assertNull(Players::authenticate('Public Forge', 'anything'));
    }

    public function testOneAddressMakesOnlySoManyAccountsAnHour()
    {
        foreach (['First One', 'Second One', 'Third One'] as $name) {
            $this->postJson('/api/v1/accounts', ['name' => $name, 'password' => 'long-enough'])->assertCreated();
        }
        $this->postJson('/api/v1/accounts', ['name' => 'Fourth One', 'password' => 'long-enough'])->assertStatus(429);
        $this->post('/signup', ['name' => 'Fifth One', 'password' => 'long-enough', 'password_confirmation' => 'long-enough'])
            ->assertSessionHasErrors('name');
        $this->assertFalse(DB::table('users')->whereIn('login', ['Fourth One', 'Fifth One'])->exists());
    }

    public function testTheApiToken()
    {
        $made = $this->postJson('/api/v1/accounts', ['name' => 'Bot Owner', 'password' => 'long-enough'])->assertCreated()
            ->assertJsonPath('name', 'Bot Owner')->assertJsonPath('share_credits', 5000);
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
    }

    public function testATeachersClassGroup()
    {
        $teacher = Players::create('Ms Teacher', 'long-enough');
        $this->actingAs($teacher);
        $page = $this->post('/account/classes', ['group_name' => 'Orbit', 'pupils' => 3])->assertOk()
            ->assertSee('Orbit 1')->assertSee('Orbit 3')->assertSee("won't be shown again", false);
        $group = (int) DB::table('class_groups')->where('teacher_id', $teacher->user_id)->value('id');
        $pupils = DB::table('users')->where('class_group_id', $group)->orderBy('user_id')->get();
        $this->assertSame(['Orbit 1', 'Orbit 2', 'Orbit 3'], $pupils->pluck('login')->all());
        $this->assertTrue($pupils->every(fn ($p) => $p->email === null && (int) $p->share_credits === 5000));

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
        $other = Players::create('Mr Other', 'long-enough');
        $this->actingAs($other)->get("/account/classes/$group")->assertNotFound();
        $this->assertNull(Players::resetPupilPassword($other, 'Orbit 1'));

        // A pupil sees their group, and can't make one
        $this->actingAs(\App\User::where('login', 'Orbit 1')->first())->get('/account')->assertOk()
            ->assertSee('Class group')->assertDontSee('Make the group');
    }

    public function testClassGroupLimits()
    {
        $teacher = Players::create('Big Class', 'long-enough');
        $this->expectProblem(fn () => Players::createClassGroup($teacher, 'Huge', 41), '1 to 40');
        $this->expectProblem(fn () => Players::createClassGroup($teacher, 'Empty', 0), '1 to 40');
        Players::create('Comet 2', 'long-enough');
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
