<?php

namespace Tests\Feature;

use App\Services\Players;
use App\Services\PlayerShares;
use App\Services\Seedlings;
use App\Services\TradeScripts;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The account trading script (#768): saved on the account, run in the
 * lua-trade sandbox, its actions under the player's own rules.
 */
class TradeScriptsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['forge.new_rules' => true, 'forge.seedling_hull_value' => 2000, 'forge.seedling_fee' => 500,
            'forge.starting_credits' => 3000, 'forge.player_share_credits' => 5000, 'forge.bank_pay_days' => 30,
            'forge.share_range_low' => 0.8, 'forge.share_range_high' => 1.25, 'forge.hull_life_days' => 365,
            'forge.dividend_permille' => 500, 'forge.seedling_max' => 5, 'forge.seedling_mature_margin' => 0]);
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

    private function matured(User $owner, string $name, int $credits): int
    {
        $id = Seedlings::start($owner, $name, "function launched() wait(60) end")['company'];
        DB::table('companies')->where('company_id', $id)->update(['credits' => $credits + Seedlings::grant()]);
        $this->assertTrue(Seedlings::mature($owner, $id)['ok']);
        return $id;
    }

    public function testAScriptTradesUnderThePlayersRules()
    {
        $owner = $this->player('Script Owner');
        $id = $this->matured($owner, 'Script Co', 100000);
        $trader = $this->player('Script Trader');
        DB::table('users')->where('user_id', $trader->user_id)->update(['credits' => 50000]);

        $code = <<<LUA
function trade(market)
  memory.runs = (memory.runs or 0) + 1
  for _, c in ipairs(market.companies) do
    if c.company == $id then
      buy_pegged(c.company, 3, "buys_at", -1)
      bank_buy(c.company, 1)
      sell(c.company, 1000, c.buys_at)
    end
  end
  log("run", memory.runs, "credits", market.me.credits)
end
LUA;
        $this->assertTrue(TradeScripts::save($trader, $code, true)['ok']);
        $out = TradeScripts::run($trader);
        $this->assertStringContainsString('buy 3 of Script Co at buys_at -1.00%: traded 0, 3 stand', implode("\n", $out));
        $this->assertStringContainsString('buy 1 of Script Co from the bank: refused', implode("\n", $out), 'the bank holds none');
        $this->assertStringContainsString('sell 1000 of Script Co', implode("\n", $out));
        $this->assertStringContainsString('refused, you don', implode("\n", $out));
        $this->assertStringContainsString('says: run 1 credits 50000', implode("\n", $out));
        $order = DB::table('player_share_orders')->where('user_id', $trader->user_id)->first();
        $this->assertSame(['buy', 3, 'buys_at'], [$order->side, (int) $order->units, $order->peg_ref]);

        // Its memory is there next run, and the log keeps both runs
        $this->assertStringContainsString('says: run 2', implode("\n", TradeScripts::run($trader)));
        $this->assertCount(2, TradeScripts::of($trader)['log']);

        // The owner sells into its bid: a trade between players
        $this->assertSame(3, PlayerShares::order($owner, $id, 'sell', 3, (int) $order->price)['traded']);
    }

    public function testBadScriptsAreRefusedOrStopped()
    {
        $p = $this->player('Bad Scripter');
        $this->assertSame(422, TradeScripts::save($p, 'function trade(', true)['status'] ?? null, 'it doesn\'t compile');
        TradeScripts::save($p, 'function trade() while true do end end', true);
        $this->assertStringContainsString('stopped: ', implode(' ', TradeScripts::run($p)));
        $this->assertStringContainsString('million instructions', implode(' ', TradeScripts::run($p)));
        TradeScripts::save($p, 'function trade() os.exit() end', true);
        $this->assertStringContainsString("global 'os'", implode(' ', TradeScripts::run($p)));
        TradeScripts::save($p, 'x = 1', true);
        $this->assertStringContainsString('no trade(market) function', implode(' ', TradeScripts::run($p)));
        // At most 20 actions a run
        TradeScripts::save($p, 'function trade() for i = 1, 30 do cancel(i) end end', true);
        $this->assertCount(20, TradeScripts::run($p));
        // Removing it
        $this->assertTrue(TradeScripts::save($p, '', false)['removed']);
        $this->assertNull(TradeScripts::of($p));
    }

    public function testTheKeeperRunsSwitchedOnScriptsWhenDue()
    {
        $on = $this->player('Keeper On');
        $off = $this->player('Keeper Off');
        TradeScripts::save($on, 'function trade() log("hi") end', true);
        TradeScripts::save($off, 'function trade() log("hi") end', false);
        $this->assertSame(1, TradeScripts::runAll());
        $this->assertSame(0, TradeScripts::runAll(), 'not due again yet');
        $this->assertNull(TradeScripts::of($off)['last_run']);
    }

    public function testTheApiAndTheAccountPage()
    {
        $p = $this->player('Api Scripter');
        $h = ['Authorization' => 'Bearer ' . Players::issueToken($p)];
        $this->putJson('/api/v1/account/trade-script', ['code' => 'function trade() log("api") end', 'enabled' => true], $h)->assertOk();
        $this->getJson('/api/v1/account/trade-script', $h)->assertOk()->assertJsonPath('enabled', true);
        $this->postJson('/api/v1/account/trade-script/run', [], $h)->assertOk()->assertJsonPath('lines.0', 'says: api');
        $this->actingAs($p)->get('/account')->assertOk()->assertSee('id="trade-script"', false)->assertSee('says: api');
    }

    /** The starter runs on a real market without stopping */
    public function testTheValueTraderStarterRuns()
    {
        $owner = $this->player('Value Owner');
        $id = $this->matured($owner, 'Value Co', 100000);
        $p = $this->player('Value Trader');
        DB::table('users')->where('user_id', $p->user_id)->update(['credits' => 100000]);
        $this->assertTrue(TradeScripts::save($p, file_get_contents(base_path('examples/traders/value.lua')), true)['ok']);
        $out = implode("\n", TradeScripts::run($p));
        $this->assertStringNotContainsString('stopped', $out);
        $this->assertSame(1, TradeScripts::of($p)['runs'], 'it ran, and did what it found worth doing');
    }

    public function testMarketDataAsLua()
    {
        $this->assertSame('{1,2,"a\\"b"}', TradeScripts::lua([1, 2, 'a"b']));
        $this->assertSame('{["x"]=1.5,["y"]=true}', TradeScripts::lua(['x' => 1.5, 'y' => true, 'z' => null]));
        $this->assertSame('"line\\010two"', TradeScripts::lua("line\ntwo"));
    }
}
