<?php

namespace Tests\Feature;

use App\Services\Emotes;
use App\Services\Players;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The menu bar's emote button (#1062): a smile until the player says how
 * they feel, then their last emote; each emote is logged with the player's
 * name (if logged in), the comment and the game time. No account needed.
 * Players, AI players among them, emote through the API too, and the
 * operator reads the log without names for the feedback review (#1068).
 */
class EmoteButtonTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
    }

    public function testEveryPageHasTheButtonAndItsPopUp()
    {
        foreach (['/', '/market', '/manual'] as $page) {
            $this->get($page)->assertOk()
                ->assertSee('id="nav-emote"', false)
                ->assertSee('id="emote-dialog"', false)
                ->assertSee('🙂');
        }
        // Every choice is offered
        $html = $this->get('/')->getContent();
        foreach (array_keys(Emotes::CHOICES) as $key) {
            $this->assertStringContainsString('value="' . $key . '"', $html);
        }
    }

    public function testAVisitorsEmoteIsLoggedWithTheGameTime()
    {
        DB::table('game_clock')->delete();
        DB::insert('INSERT INTO game_clock (id, real_anchor, game_anchor, scale) VALUES (1, UNIX_TIMESTAMP(), 5000, 1)');

        $this->postJson('/emote', ['emote' => 'confused', 'comment' => '  Where do I sell ore? ', 'visitor' => 'abc12345-visitor'])
            ->assertOk()->assertJson(['emote' => 'confused', 'emoji' => '😕']);

        $row = DB::table('player_emotes')->orderByDesc('id')->first();
        $this->assertNull($row->user_id);
        $this->assertNull($row->player_name);
        $this->assertSame('abc12345-visitor', $row->visitor);
        $this->assertSame('confused', $row->emote);
        $this->assertSame('😕', $row->emoji);
        $this->assertSame('Where do I sell ore?', $row->comment);
        $this->assertEqualsWithDelta(5000, (int) $row->game_t, 5);
    }

    public function testAPlayersEmoteIsLoggedWithTheirNameAndShowsOnTheButton()
    {
        config(['forge.new_rules' => true, 'forge.trial_auto_verify' => true]);
        $player = Players::create('Happy Miner', 'long-enough', 'happy-miner@example.com');
        $this->actingAs($player);

        $this->get('/market')->assertSee('data-emote=""', false);
        // The comment is optional
        $this->postJson('/emote', ['emote' => 'excited'])->assertOk();

        $row = DB::table('player_emotes')->where('user_id', $player->user_id)->first();
        $this->assertSame('Happy Miner', $row->player_name);
        $this->assertNull($row->comment);
        $this->assertNull($row->visitor);

        $this->get('/market')->assertSee('data-emote="excited"', false)->assertSee('🤩');
    }

    public function testOnlyTheOfferedEmotesAndShortCommentsAreTaken()
    {
        $before = DB::table('player_emotes')->count();
        $this->postJson('/emote', [])->assertStatus(422);
        $this->postJson('/emote', ['emote' => 'smug'])->assertStatus(422);
        $this->postJson('/emote', ['emote' => 'happy', 'comment' => str_repeat('x', Emotes::COMMENT_MAX + 1)])->assertStatus(422);
        $this->postJson('/emote', ['emote' => 'happy', 'visitor' => '<script>'])->assertStatus(422);
        $this->assertSame($before, DB::table('player_emotes')->count());
    }

    private function verified(string $name): \App\User
    {
        $email = strtolower(str_replace(' ', '.', $name)) . '@example.com';
        $user = Players::create($name, 'long-enough', $email);
        Players::verify($user->user_id, sha1($email));
        return \App\User::where('user_id', $user->user_id)->first();
    }

    public function testPlayersAndAIPlayersEmoteThroughTheAPI()
    {
        config(['forge.new_rules' => true, 'forge.trial_auto_verify' => false, 'forge.bot_max' => 3]);
        $lead = $this->verified('Emote Lead');
        config(['forge.operators' => ['Emote Lead']]);
        $key = \App\Services\Bots::makeKey($lead);
        $this->withHeader('X-Bot-Key', $key)->postJson('/api/v1/bots', ['name' => 'Iris Mott'])->assertStatus(201);
        $this->flushHeaders();

        // Without a player: refused
        $this->postJson('/api/v1/emote', ['emote' => 'happy'])->assertStatus(401);
        // An AI player, through its operator's key; a bad emote is a JSON 422 naming the choices
        $as = ['X-Bot-Key' => $key, 'X-Bot-Player' => 'Iris Mott'];
        $this->withHeaders($as)->post('/api/v1/emote', ['emote' => 'smug'])->assertStatus(422)->assertJsonFragment(['choices' => array_keys(Emotes::CHOICES)]);
        $this->withHeaders($as)->postJson('/api/v1/emote', ['emote' => 'frustrated', 'comment' => 'Loan board is hard to read'])
            ->assertStatus(201)->assertJson(['emote' => 'frustrated', 'emoji' => '😠']);
        $this->flushHeaders();
        $row = DB::table('player_emotes')->orderByDesc('id')->first();
        $this->assertSame('Iris Mott', $row->player_name);
        $this->assertSame('Loan board is hard to read', $row->comment);

        // A person, with a token
        $person = $this->verified('Real Person');
        $token = Players::issueToken($person);
        $this->withToken($token)->postJson('/api/v1/emote', ['emote' => 'happy'])->assertStatus(201);
        $this->flushHeaders();

        $this->getJson('/api/v1/emotes/choices')->assertOk()->assertJsonPath('choices.happy.emoji', '😄');
    }

    public function testTheOperatorReadsTheLogWithoutNames()
    {
        config(['forge.new_rules' => true, 'forge.trial_auto_verify' => false, 'forge.bot_max' => 3]);
        $lead = $this->verified('Reading Lead');
        $plain = $this->verified('Plain Reader');
        config(['forge.operators' => ['Reading Lead']]);
        $key = \App\Services\Bots::makeKey($lead);
        $this->withHeader('X-Bot-Key', $key)->postJson('/api/v1/bots', ['name' => 'Juno Hale'])->assertStatus(201);
        $this->withHeader('X-Bot-Key', $key)->putJson('/api/v1/bots/Juno Hale', ['profile' => "# C-07\nA careful trader."])->assertOk();
        $this->flushHeaders();

        $juno = \App\User::where('login', 'Juno Hale')->first();
        DB::table('player_emotes')->delete();
        Emotes::log('excited', 'The tech map is great', $juno, null);
        Emotes::log('confused', 'How do loans work?', $plain, null);
        Emotes::log('confused', null, $plain, null);
        Emotes::log('bored', null, null, 'visitor-token-1');

        // Only with the operator's key
        $this->getJson('/api/v1/emotes')->assertStatus(401);
        $r = $this->withHeader('X-Bot-Key', $key)->getJson('/api/v1/emotes')->assertOk();
        $this->assertSame(4, $r->json('summary.all.emotes'));
        $this->assertSame(2, $r->json('summary.people.by_emote.confused'));
        $this->assertSame(1, $r->json('summary.people.who'));
        $this->assertSame(1, $r->json('summary.people.with_comment'));
        $this->assertSame(1, $r->json('summary.ai.by_emote.excited'));
        $this->assertSame(1, $r->json('summary.visitors.emotes'));
        // No names anywhere: an AI player is its persona, a person an opaque ID that stays the same
        $body = $r->getContent();
        foreach (['Juno Hale', 'Plain Reader', 'visitor-token-1'] as $name) {
            $this->assertStringNotContainsString($name, $body);
        }
        $entries = collect($r->json('entries'));
        $this->assertSame('C-07', $entries->firstWhere('source', 'ai')['who']);
        $this->assertCount(1, $entries->where('source', 'people')->pluck('who')->unique());

        // Filters
        $this->withHeader('X-Bot-Key', $key)->getJson('/api/v1/emotes?source=ai')->assertOk()->assertJsonCount(1, 'entries');
        $this->withHeader('X-Bot-Key', $key)->getJson('/api/v1/emotes?since=2000-01-01&until=2000-01-08')->assertOk()
            ->assertJsonPath('summary.all.emotes', 0);
        $this->withHeader('X-Bot-Key', $key)->getJson('/api/v1/emotes?since=yesterday')->assertStatus(422);
        $this->withHeader('X-Bot-Key', $key)->getJson('/api/v1/emotes?source=robots')->assertStatus(422);
    }
}
