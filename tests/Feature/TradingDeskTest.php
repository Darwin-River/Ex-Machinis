<?php

namespace Tests\Feature;

use App\Services\Players;
use App\Services\PlayerShares;
use App\Services\Seedlings;
use App\Services\TradeScripts;
use App\Services\TradingDesk;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** The Trading desk, scripted orders tagged, and the payback starter (#779) */
class TradingDeskTest extends TestCase
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
        $drone = (int) DB::table('agents')->where('company_id', $id)->value('agent_id');
        DB::table('holdings')->updateOrInsert(['drone' => $drone, 'resource' => \App\Services\Release::FUEL, 'installed' => 0, 'locked' => 0],
            ['units' => 100, 'event_id' => 0]);
        $this->assertTrue(Seedlings::mature($owner, $id)['ok']);
        return $id;
    }

    public function testTheDeskShowsTheWatchListLadderTicketSharesAndScript()
    {
        $owner = $this->player('Desk Owner');
        $id = $this->matured($owner, 'Desk Co', 100000);
        $p = PlayerShares::prices($id);
        $buyer = $this->player('Desk Buyer');
        DB::table('users')->where('user_id', $buyer->user_id)->update(['credits' => 1000000]);
        PlayerShares::order($owner, $id, 'sell', 10, $p['sells_at']);
        PlayerShares::order($buyer, $id, 'buy', 4, $p['sells_at']);
        PlayerShares::order($owner, $id, 'sell', 3, $p['high']);
        DB::table('player_dividends')->insert(['company_id' => $id, 'user_id' => $buyer->user_id, 'day' => 1, 'units' => 4, 'amount' => 40]);
        Cache::flush();

        // Anyone sees the watch list and the ladder; the ticket asks them to log in
        $this->get('/market/trade')->assertOk()->assertSee('id="watch-list"', false)->assertSee('Desk Co')
            ->assertSee('id="ladder"', false)->assertSee('The bank sells')->assertSee('Top of the band')
            ->assertSee('Log in</a> to trade', false)->assertSee('Trading desk');

        // A player sees their ticket, shares with gain including dividends, and their script
        $r = $this->actingAs($buyer)->get('/market/trade?company=' . $id)->assertOk()->assertSee('id="t-say"', false)
            ->assertSee('id="your-shares"', false)->assertSee('id="your-script"', false)->assertSee('payback.lua');
        $s = TradingDesk::gains((int) $buyer->user_id, PlayerShares::of($buyer)['holdings'])[0];
        $this->assertSame([4, 4 * $p['sells_at'], 40], [$s['units'], $s['cost'], $s['dividends']]);
        $this->assertSame($s['value'] - $s['cost'] + 40, $s['gain']);
        // The owner's shares from maturing cost nothing
        $o = TradingDesk::gains((int) $owner->user_id, PlayerShares::of($owner)['holdings'])[0];
        $this->assertSame([0, 96], [$o['cost'], $o['founder_units']]);
        // The guide, the menu and the market tabs lead here
        $this->get('/getting-started')->assertSee(url('market/trade'), false);
        $this->get('/market')->assertSee('Trading desk');
    }

    public function testScriptedOrdersAreTagged()
    {
        $owner = $this->player('Tag Owner');
        $id = $this->matured($owner, 'Tag Co', 100000);
        $p = PlayerShares::prices($id);
        $bot = $this->player('Tag Bot');
        DB::table('users')->where('user_id', $bot->user_id)->update(['credits' => 1000000]);
        PlayerShares::order($bot, $id, 'buy', 2, $p['low'], null, null, true);
        PlayerShares::order($owner, $id, 'sell', 2, $p['high']);
        $book = PlayerShares::book($id);
        $this->assertTrue($book['buy'][0]['script']);
        $this->assertFalse($book['sell'][0]['script']);
        $this->get('/companies/' . $id)->assertOk()->assertSee('(script)');
        Cache::flush();
        $this->get('/market/trade?company=' . $id)->assertOk()->assertSee('(script)');
    }

    /** The payback starter runs on a real market without stopping, and its orders are tagged */
    public function testThePaybackStarterRuns()
    {
        $owner = $this->player('Pay Owner');
        $id = $this->matured($owner, 'Pay Co', 100000);
        // It has paid dividends (a share's cash-out over 30 game days), so its shares pay back in about 30 days
        $today = \App\Services\MarketHistory::today();
        DB::table('player_dividends')->insert(['company_id' => $id, 'user_id' => $owner->user_id, 'day' => $today - 15,
            'units' => 100, 'amount' => 100 * PlayerShares::prices($id)['buys_at']]);
        $p = PlayerShares::prices($id);
        PlayerShares::order($owner, $id, 'sell', 5, $p['low']);
        Cache::flush();
        $t = $this->player('Pay Trader');
        DB::table('users')->where('user_id', $t->user_id)->update(['credits' => 100000]);
        $this->assertTrue(TradeScripts::save($t, file_get_contents(base_path('examples/traders/payback.lua')), true)['ok']);
        $out = TradeScripts::run($t);
        if ($out === null) {
            $this->markTestSkipped('lua-trade is not built here');
        }
        $out = implode("\n", $out);
        $this->assertStringNotContainsString('stopped', $out);
        $this->assertStringContainsString('says: buying', $out);
        $this->assertGreaterThan(0, PlayerShares::held((int) $t->user_id, $id));
    }
}
