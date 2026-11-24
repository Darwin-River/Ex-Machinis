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
 * the key makes verified Claude players through the API, named "Claude …";
 * people may not take such names. Rate limits count per account (#1017).
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

        // With it: a verified account named "Claude Mei", with no address, credits or class
        $made = $this->withHeader('X-Bot-Key', $key)->postJson('/api/v1/bots', ['name' => 'Mei'])->assertStatus(201)
            ->assertJsonPath('name', 'Claude Mei')->assertJsonPath('bot', true)->json();
        $mei = User::where('login', 'Claude Mei')->first();
        $this->assertTrue(Players::verified($mei));
        $row = DB::table('users')->where('user_id', $mei->user_id)->first();
        $this->assertSame([null, 0, (int) $lead->user_id], [$row->email, (int) $row->credits, (int) $row->bot_operator_id]);
        Mail::assertNothingSent();

        // Its token plays: it founds a startup, as anyone may
        $this->flushHeaders();
        $this->withHeader('Authorization', 'Bearer ' . $made['token'])->getJson('/api/v1/account')
            ->assertOk()->assertJsonPath('bot', true)->assertJsonPath('verified', true);
        $this->assertTrue(Startups::start($mei, 'Claude Works', 'function launched() wait(60) end')['ok']);
        // Its password logs in too
        $this->postJson('/api/v1/login', ['name' => 'Claude Mei', 'password' => $made['password']])->assertOk();

        // A fresh token for a new session; the old one stops
        $fresh = $this->withHeader('X-Bot-Key', $key)->postJson('/api/v1/bots/Claude Mei/token')->assertOk()->json('token');
        $this->assertNotSame($made['token'], $fresh);
        $this->assertNull(Players::byToken($made['token']));
        $this->withHeader('X-Bot-Key', $key)->postJson('/api/v1/bots/Plain Player/token')->assertStatus(404);

        // Listed; at most bot_max an operator
        $this->withHeader('X-Bot-Key', $key)->getJson('/api/v1/bots')->assertOk()
            ->assertJsonPath('operator', 'Lead Operator')->assertJsonPath('players.0.name', 'Claude Mei');
        $this->withHeader('X-Bot-Key', $key)->postJson('/api/v1/bots', ['name' => 'Claude Kai'])->assertStatus(201)
            ->assertJsonPath('name', 'Claude Kai');
        $this->withHeader('X-Bot-Key', $key)->postJson('/api/v1/bots', ['name' => 'Rosa'])->assertStatus(422);

        // The profile says so
        $this->flushHeaders();
        $this->get('/players/' . rawurlencode('Claude Mei'))->assertOk()->assertSee('A Claude player');

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

    public function testPeopleMayNotTakeAClaudeName()
    {
        foreach (['Claude', 'Claude Mei', 'claude-7', 'CLAUDE_x'] as $name) {
            $this->assertNotNull(Players::nameProblem($name), $name);
        }
        $this->assertNull(Players::nameProblem('Claudette'));
        $this->postJson('/api/v1/accounts', ['name' => 'Claude Fake', 'email' => 'fake@example.com', 'password' => 'long-enough'])
            ->assertStatus(422);
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
