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
}
