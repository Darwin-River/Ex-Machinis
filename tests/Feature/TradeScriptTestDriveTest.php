<?php

namespace Tests\Feature;

use App\Services\Players;
use App\Services\Startups;
use App\Services\TradeScripts;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * A test drive for account trading scripts (#1005): the draft or the saved
 * script, run once against the market now, says what it would do and
 * changes nothing: no orders, no trades, no memory, no log.
 */
class TradeScriptTestDriveTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['forge.new_rules' => true, 'forge.startup_hull_value' => 2000, 'forge.startup_fee' => 500,
            'forge.starting_credits' => 3000, 'forge.dividend_permille' => 500, 'forge.startup_max' => 5,
            'forge.startup_mature_margin' => 0]);
        Mail::fake();
        Cache::flush();
        if (!is_executable((string) config('forge.lua_trade'))) {
            $this->markTestSkipped('lua-trade is not built (make -C game-engine/src bin/lua-trade)');
        }
    }

    private function player(string $name): User
    {
        $email = strtolower(str_replace(' ', '.', $name)) . '@example.com';
        $user = Players::create($name, 'long-enough', $email);
        Players::verify($user->user_id, sha1($email));
        return User::where('user_id', $user->user_id)->first();
    }

    public function testATestDriveSaysWhatItWouldDoAndChangesNothing()
    {
        $owner = $this->player('Drive Owner');
        $id = Startups::start($owner, 'Drive Co', 'function launched() wait(60) end')['company'];
        DB::table('companies')->where('company_id', $id)->update(['credits' => 100000 + Startups::grant()]);
        $this->assertTrue(Startups::mature($owner, $id)['ok']);
        $trader = $this->player('Drive Trader');
        DB::table('users')->where('user_id', $trader->user_id)->update(['credits' => 50000]);
        $saved = "function trade(market)\n  memory.runs = (memory.runs or 0) + 1\n  log('saved run', memory.runs)\nend\n";
        $this->assertTrue(TradeScripts::save($trader, $saved, false)['ok']);
        $draft = <<<LUA
function trade(market)
  memory.runs = (memory.runs or 0) + 1
  for _, c in ipairs(market.companies) do
    if c.company == $id then
      buy(c.company, 5, 77)
      bank_buy(c.company, 1)
      buy_pegged(c.company, 3, "buys_at", -1)
    end
  end
  log("draft run", memory.runs)
end
LUA;
        $out = TradeScripts::testDrive($trader, $draft);
        $this->assertSame(['would buy 5 of Drive Co at 77', 'would buy 1 of Drive Co from the bank',
            'would buy 3 of Drive Co at buys_at -1.00%', 'says: draft run 1'], $out);
        // Nothing changed: no order, no trade, the same credits, memory and log
        $this->assertSame(0, DB::table('player_share_orders')->where('user_id', $trader->user_id)->count());
        $this->assertSame(50000, (int) DB::table('users')->where('user_id', $trader->user_id)->value('credits'));
        $row = DB::table('player_trade_scripts')->where('user_id', $trader->user_id)->first();
        $this->assertSame([$saved, 0], [$row->code, (int) $row->runs]);
        $this->assertSame([], TradeScripts::of($trader)['log']);
        // Without a draft, the saved script
        $this->assertSame(['says: saved run 1'], TradeScripts::testDrive($trader));

        // On the account page, the draft stays in the editor; and through the API
        $this->actingAs($trader)->post('/account/trade-script/test-drive', ['code' => $draft])->assertRedirect('/account#trade-script');
        $this->actingAs($trader)->get('/account')->assertOk()->assertSee('id="trade-test-drive"', false)
            ->assertSee('would buy 5 of Drive Co at 77');
        $token = \App\Services\Players::issueToken($trader);
        $this->withHeader('Authorization', 'Bearer ' . $token)->postJson('/api/v1/account/trade-script/test-drive', ['code' => $draft])
            ->assertOk()->assertJsonPath('applied', false)->assertJsonPath('lines.0', 'would buy 5 of Drive Co at 77');
        $this->assertSame(0, DB::table('player_share_orders')->where('user_id', $trader->user_id)->count());
    }
}
