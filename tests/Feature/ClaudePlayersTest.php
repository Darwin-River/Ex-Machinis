<?php

namespace Tests\Feature;

use App\Services\Bots;
use App\Services\Players;
use App\Services\Startups;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Claude players (#1025): an operator makes a key on their account page;
 * the key makes verified Claude players through the API. Only the database
 * marks them: nothing a player can read tells them from anyone else. Rate
 * limits count per account (#1017).
 */
class ClaudePlayersTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['forge.new_rules' => true, 'forge.trial_auto_verify' => false, 'forge.bot_max' => 2,
            'forge.startup_hull_value' => 2000, 'forge.startup_fee' => 500, 'forge.starting_credits' => 3000,
            'forge.startup_max' => 5, 'forge.player_share_credits' => 0]);
        Mail::fake();
        Cache::flush();
    }

    private function player(string $name): User
    {
        $email = strtolower(str_replace(' ', '.', $name)) . '@example.com';
        $user = Players::create($name, 'long-enough', $email);
        Players::verify($user->user_id, sha1($email));
        return User::where('user_id', $user->user_id)->first();
    }

    public function testAnOperatorsKeyMakesVerifiedClaudePlayers()
    {
        $lead = $this->player('Lead Operator');
        $other = $this->player('Plain Player');
        config(['forge.operators' => ['lead operator']]);   // names match in any case

        // Only an operator sees the section and makes a key
        $this->actingAs($other)->get('/account')->assertOk()->assertDontSee('id="claude-players"', false);
        $this->actingAs($other)->post('/account/bot-keys')->assertRedirect('/account');
        $this->assertSame(0, DB::table('bot_keys')->count());
        $this->actingAs($lead)->post('/account/bot-keys')->assertRedirect('/account#claude-players');
        $key = session('bot_key');
        $this->assertStringStartsWith('exm_', $key);
        $this->assertSame(hash('sha256', $key), DB::table('bot_keys')->value('key_hash'));

        // Without the key, or with a wrong one: refused
        $this->postJson('/api/v1/bots', ['name' => 'Mei'])->assertStatus(401);
        $this->withHeader('X-Bot-Key', 'exm_wrong')->postJson('/api/v1/bots', ['name' => 'Mei'])->assertStatus(401);

        // With it: a verified account with an ordinary name, no address, credits or class
        $made = $this->withHeader('X-Bot-Key', $key)->postJson('/api/v1/bots', ['name' => 'Mei Tanaka'])->assertStatus(201)
            ->assertJsonPath('name', 'Mei Tanaka')->json();
        $mei = User::where('login', 'Mei Tanaka')->first();
        $this->assertTrue(Players::verified($mei));
        $row = DB::table('users')->where('user_id', $mei->user_id)->first();
        $this->assertSame([null, 0, (int) $lead->user_id], [$row->email, (int) $row->credits, (int) $row->bot_operator_id]);
        Mail::assertNothingSent();

        // Its token plays: it founds a startup, as anyone may
        $this->flushHeaders();
        $this->withHeader('Authorization', 'Bearer ' . $made['token'])->getJson('/api/v1/account')
            ->assertOk()->assertJsonPath('verified', true)->assertJsonMissingPath('bot');
        $this->assertTrue(Startups::start($mei, 'Mei Works', 'function launched() wait(60) end')['ok']);
        // Its password logs in too
        $this->postJson('/api/v1/login', ['name' => 'Mei Tanaka', 'password' => $made['password']])->assertOk();

        // A fresh token for a new session; the old one stops
        $fresh = $this->withHeader('X-Bot-Key', $key)->postJson('/api/v1/bots/Mei Tanaka/token')->assertOk()->json('token');
        $this->assertNotSame($made['token'], $fresh);
        $this->assertNull(Players::byToken($made['token']));
        $this->withHeader('X-Bot-Key', $key)->postJson('/api/v1/bots/Plain Player/token')->assertStatus(404);

        // Listed; at most bot_max an operator
        $this->withHeader('X-Bot-Key', $key)->getJson('/api/v1/bots')->assertOk()
            ->assertJsonPath('operator', 'Lead Operator')->assertJsonPath('players.0.name', 'Mei Tanaka');
        $this->withHeader('X-Bot-Key', $key)->postJson('/api/v1/bots', ['name' => 'Kai'])->assertStatus(201)
            ->assertJsonPath('name', 'Kai');
        $this->withHeader('X-Bot-Key', $key)->postJson('/api/v1/bots', ['name' => 'Rosa'])->assertStatus(422);

        // Nothing a player can read shows the marker: its profile page and API
        // answer as a person's do
        $this->flushHeaders();
        $this->get('/players/' . rawurlencode('Mei Tanaka'))->assertOk()->assertDontSee('Claude')->assertDontSee('bot_operator');
        $this->getJson('/api/v1/players/' . rawurlencode('Mei Tanaka'))->assertOk()->assertJsonMissingPath('bot');
        $this->assertSame((int) $lead->user_id, (int) DB::table('users')->where('login', 'Mei Tanaka')->value('bot_operator_id'));

        // A revoked key, or an operator no longer named, makes nothing
        $id = (int) DB::table('bot_keys')->value('id');
        $this->actingAs($lead)->post("/account/bot-keys/$id/revoke")->assertRedirect('/account#claude-players');
        $this->assertNull(Bots::operatorFor($key));
        $this->actingAs($lead)->post('/account/bot-keys');
        $second = session('bot_key');
        $this->assertNotNull(Bots::operatorFor($second));
        config(['forge.operators' => []]);
        $this->assertNull(Bots::operatorFor($second));
    }

    public function testProfilesAndNotesAreReadOnlyWithTheKey()
    {
        // DV-04 (#1027): a Claude player's profile and notes, kept in the
        // database, read and written only with its operator's key
        $lead = $this->player('Profile Lead');
        $rival = $this->player('Rival Lead');
        config(['forge.operators' => ['Profile Lead', 'Rival Lead']]);
        $key = Bots::makeKey($lead);
        $rivalKey = Bots::makeKey($rival);
        $made = $this->withHeader('X-Bot-Key', $key)->postJson('/api/v1/bots', ['name' => 'Juno Park'])->assertStatus(201)->json();

        $this->withHeader('X-Bot-Key', $key)->putJson('/api/v1/bots/Juno Park', ['profile' => "Persona: a retired surveyor\nRisk: low",
            'notes' => 'Day 1: founded a Miner.'])->assertOk()->assertJsonPath('profile', "Persona: a retired surveyor\nRisk: low");
        // Notes alone change; the profile stays
        $this->withHeader('X-Bot-Key', $key)->putJson('/api/v1/bots/Juno Park', ['notes' => 'Day 2: it matured.'])
            ->assertOk()->assertJsonPath('notes', 'Day 2: it matured.')->assertJsonPath('profile', "Persona: a retired surveyor\nRisk: low");
        $this->withHeader('X-Bot-Key', $key)->getJson('/api/v1/bots/Juno Park')->assertOk()->assertJsonPath('notes', 'Day 2: it matured.');
        $this->withHeader('X-Bot-Key', $key)->putJson('/api/v1/bots/Juno Park', ['notes' => str_repeat('x', Bots::TEXT_MAX + 1)])->assertStatus(422);

        // Another operator's key, no key, or the player's own token: nothing
        $this->withHeader('X-Bot-Key', $rivalKey)->getJson('/api/v1/bots/Juno Park')->assertStatus(404);
        $this->withHeader('X-Bot-Key', $rivalKey)->putJson('/api/v1/bots/Juno Park', ['notes' => 'x'])->assertStatus(404);
        $this->flushHeaders();
        $this->getJson('/api/v1/bots/Juno Park')->assertStatus(401);
        $this->withHeader('Authorization', 'Bearer ' . $made['token'])->getJson('/api/v1/account')->assertOk()
            ->assertDontSee('surveyor')->assertJsonMissingPath('profile');
        $this->assertArrayNotHasKey('bot_profile', User::where('login', 'Juno Park')->first()->toArray());
    }

    public function testWriteLimitsCountPerAccount()
    {
        // Ten writes a minute: an account with a good token has its own count,
        // so two players behind one address don't share it (#1017)
        $a = $this->player('Limit One');
        $b = $this->player('Limit Two');
        $ta = Players::issueToken($a);
        $tb = Players::issueToken($b);
        RateLimiter::clear('write|user:' . $a->user_id);
        for ($i = 0; $i < 10; $i++) {
            $this->withHeader('Authorization', 'Bearer ' . $ta)->postJson('/api/v1/account/verify');
        }
        $this->withHeader('Authorization', 'Bearer ' . $ta)->postJson('/api/v1/account/verify')->assertStatus(429);
        $this->withHeader('Authorization', 'Bearer ' . $tb)->postJson('/api/v1/account/verify')->assertStatus(409);
        // A made-up token counts against the address
        $this->assertSame('ip:127.0.0.1', \App\Providers\RouteServiceProvider::caller(
            \Illuminate\Http\Request::create('/', 'POST', [], [], [], ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_AUTHORIZATION' => 'Bearer made-up'])));
    }
}
