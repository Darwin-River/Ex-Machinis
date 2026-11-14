<?php

namespace Tests\Feature;

use App\Services\PlayerBoards;
use App\Services\Players;
use App\Services\Seedlings;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Player boards and the digest (#640): wealth and the monthly season, class
 * groups apart, and "while you were away".
 */
class PlayerBoardsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['forge.new_rules' => true, 'forge.player_share_credits' => 5000, 'forge.season_floor' => 5000,
            'forge.dividend_permille' => 500]);
        Mail::fake();
        // Only this test's accounts
        DB::table('users')->whereNotNull('login')->update(['login' => null]);
    }

    private function player(string $name, int $credits = 0): User
    {
        $email = strtolower(str_replace(' ', '.', $name)) . '@example.com';
        $user = Players::create($name, 'long-enough', $email);
        Players::verify($user->user_id, sha1($email));
        DB::table('users')->where('user_id', $user->user_id)->update(['credits' => $credits]);
        return User::where('user_id', $user->user_id)->first();
    }

    public function testWealthAndSeasonBoards()
    {
        $rich = $this->player('Rich One', 20000);      // 25,000 in all
        $grower = $this->player('Grower One', 0);      // 5,000
        DB::table('player_wealth_months')->delete();
        PlayerBoards::startMonth();
        $this->assertSame(25000, (int) DB::table('player_wealth_months')->where('user_id', $rich->user_id)->value('start_wealth'));
        PlayerBoards::startMonth();
        $this->assertSame(2, DB::table('player_wealth_months')->count(), 'once a month');

        // Shares count at cash-out value
        $owner = $grower;
        $co = Seedlings::start($owner, 'Grower Co', 'function launched() wait(60) end')['company'];
        DB::table('companies')->where('company_id', $co)->update(['credits' => 3000000 + Seedlings::grant()]);
        Seedlings::mature($owner, $co);
        $b = PlayerBoards::boards();
        $this->assertSame('Grower One', $b['wealth'][0]['name'], 'its 1,000 shares are worth about 3,000,000');
        $this->assertGreaterThanOrEqual(3000000, $b['wealth'][0]['shares']);
        $this->assertSame('Grower One', $b['season'][0]['name']);
        $this->assertSame(0.0, (float) collect($b['season'])->firstWhere('name', 'Rich One')['growth_percent']);

        $this->get('/players')->assertOk()->assertSee('Grower One')->assertSee('This month');
        $this->getJson('/api/v1/players')->assertOk()->assertJsonPath('wealth.0.name', 'Grower One')->assertJsonPath('class_group', null);
    }

    public function testAClassGroupHasItsOwnBoards()
    {
        $teacher = $this->player('Board Teacher');
        $public = $this->player('Public Player', 1000);
        Players::createClassGroup($teacher, 'Comet', 2);
        $group = (int) DB::table('class_groups')->where('teacher_id', $teacher->user_id)->value('id');
        $pupil = User::where('login', 'Comet 1')->first();

        $this->assertSame(['Comet 1', 'Comet 2'], collect(PlayerBoards::boards($group)['wealth'])->pluck('name')->sort()->values()->all());
        $this->assertNotContains('Comet 1', collect(PlayerBoards::boards()['wealth'])->pluck('name')->all());

        $this->actingAs($pupil)->get('/players')->assertOk()->assertSee('Comet 2')->assertDontSee('Public Player');
        $this->actingAs($teacher)->get('/players?class=' . $group)->assertOk()->assertSee('Comet 1');
        $this->actingAs($public)->get('/players?class=' . $group)->assertNotFound();
        $this->getJson('/api/v1/players?class=' . $group)->assertNotFound();
        $this->getJson('/api/v1/players?class=' . $group, ['Authorization' => 'Bearer ' . Players::issueToken($teacher)])
            ->assertOk()->assertJsonPath('class_group.name', 'Comet');
    }

    public function testWhileYouWereAway()
    {
        $p = $this->player('Away Player');
        $co = Seedlings::start($p, 'Away Co', 'function launched() wait(60) end')['company'];
        DB::table('player_dividends')->insert(['company_id' => $co, 'user_id' => $p->user_id, 'day' => 999999, 'units' => 10, 'amount' => 321]);
        $d = PlayerBoards::digest($p, false);
        $this->assertSame('Away Co', $d['companies'][0]['name']);
        $this->assertTrue($d['companies'][0]['seedling']);
        $this->assertSame(321, $d['dividends']);

        // Logging in shows it, once
        $this->post('/login', ['name' => 'Away Player', 'password' => 'long-enough'])->assertRedirect('/account');
        $this->get('/account')->assertOk()->assertSee('While you were away')->assertSee('321');
        $this->get('/account')->assertDontSee('While you were away');
        $this->assertNotNull(DB::table('users')->where('user_id', $p->user_id)->value('last_seen_t'));

        // Seen only when shown (#655): logging in alone leaves it for later
        $seen = DB::table('users')->where('user_id', $p->user_id)->value('last_seen_t');
        DB::table('users')->where('user_id', $p->user_id)->update(['last_seen_t' => $seen - 3600]);
        $this->post('/logout');
        $this->post('/login', ['name' => 'Away Player', 'password' => 'long-enough']);
        $this->assertEquals($seen - 3600, DB::table('users')->where('user_id', $p->user_id)->value('last_seen_t'));

        // A trade recorded after the last look counts, whatever its game time;
        // a closed seedling stays in until it has been seen (#655)
        $drone = (int) DB::table('agents')->where('company_id', $co)->value('agent_id');
        $mark = (int) DB::table('trades')->max('id');
        DB::table('users')->where('user_id', $p->user_id)->update(['last_seen_trade' => $mark]);
        DB::table('trades')->insert(['resource' => 1, 'units' => 1, 'credits' => 53, 'seller' => 1, 'buyer' => $drone,
            'game_t' => 0, 'tax' => 0]);
        Seedlings::close($p, $co);
        $d = PlayerBoards::digest($p, false);
        $this->assertSame(53, $d['companies'][0]['purchases']);
        $this->assertSame('closed', $d['companies'][0]['ended']);

        $h = ['Authorization' => 'Bearer ' . Players::issueToken($p)];
        $this->getJson('/api/v1/account/digest?peek=1', $h)->assertOk()->assertJsonStructure(['since', 'companies', 'dividends',
            'bank_paid', 'same_owner_deals', 'news']);
    }

    public function testHiddenWhileTheNewRulesAreOff()
    {
        config(['forge.new_rules' => false]);
        $this->get('/players')->assertNotFound();
        $this->getJson('/api/v1/players')->assertNotFound();
    }
}
