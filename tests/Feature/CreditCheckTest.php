<?php

namespace Tests\Feature;

use App\Services\CreditCheck;
use App\Services\Players;
use App\Services\PlayerShares;
use App\Services\Startups;
use App\Services\WindUp;
use App\Services\World;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** #937: credits change only by what is created and destroyed, and a check says so once a game day */
class CreditCheckTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['forge.new_rules' => true, 'forge.startup_max' => 5, 'forge.startup_mature_margin' => 0,
            'forge.player_share_credits' => 5000]);   // (none by default since #928; the grant's flow is still checked)
        Mail::fake();
    }

    private function player(string $name): User
    {
        $email = strtolower(str_replace(' ', '.', $name)) . '@example.com';
        $user = Players::create($name, 'long-enough', $email);
        $this->canInvest((int) $user->user_id);   // buyers here are investors (#1040)
        Players::verify($user->user_id, sha1($email));
        return User::where('user_id', $user->user_id)->first();
    }

    /** A sale as the events engine settles it: the buyer pays, the seller gets it less the tax, which leaves the game */
    private function sale(int $seller, int $buyer, int $credits, int $tax): void
    {
        DB::transaction(function () use ($seller, $buyer, $credits, $tax) {
            DB::table('companies')->where('company_id', $buyer)->update(['credits' => DB::raw('credits - ' . $credits)]);
            DB::table('companies')->where('company_id', $seller)->update(['credits' => DB::raw('credits + ' . ($credits - $tax))]);
            DB::table('company_taxes')->insert(['company_id' => $seller, 'charged_t' => World::gameNow(), 'amount' => $tax,
                'credits_after' => (int) DB::table('companies')->where('company_id', $seller)->value('credits'), 'eliminated' => 0]);
        });
    }

    public function testCreditsBalanceThroughEveryKindOfFlow()
    {
        CreditCheck::restart();   // whatever other tests left behind
        $first = CreditCheck::check();
        $this->assertNull($first['difference'], 'the first check is a baseline');

        $owner = $this->player('Ledger Owner');      // verified: starting credits for shares
        $holder = $this->player('Ledger Holder');
        $mine = Startups::start($owner, 'Ledger Co', 'function launched() wait(60) end')['company'];
        $theirs = Startups::start($holder, 'Ledger Two', 'function launched() wait(60) end')['company'];

        // Ledger Co sells to Ledger Two and pays sales tax, until it holds its grant
        $this->sale($mine, $theirs, 1400, 70);
        // Processor tax and a startup's skim over its cap, as the events engine writes them
        DB::transaction(function () use ($theirs) {
            DB::table('companies')->where('company_id', $theirs)->update(['credits' => DB::raw('credits - 30')]);
            DB::table('compute_bills')->insert(['company_id' => $theirs, 'charged_t' => World::gameNow(), 'instructions' => 2000000,
                'amount' => 30, 'paid' => 30, 'debt_after' => 0]);
            DB::table('companies')->where('company_id', $theirs)->update(['credits' => DB::raw('credits - 5')]);
            DB::table('startup_skims')->insert(['company_id' => $theirs, 'game_t' => World::gameNow(), 'amount' => 5]);
        });
        $this->sale($mine, $theirs, 1, 0);
        // ...and a last sale at a price that brings it to its grant
        $credits = (int) DB::table('companies')->where('company_id', $mine)->value('credits');
        $this->sale($mine, $theirs, max(0, Startups::grant() - $credits), 0);
        $this->assertTrue(Startups::mature($owner, $mine)['ok']);

        // The bank buys shares and pays later, sells shares, and takes its dividends
        $sold = PlayerShares::sellToBank($owner, $mine, 20);
        $this->assertTrue($sold['ok'] ?? false, json_encode($sold));
        DB::table('bank_payments')->where('user_id', $owner->user_id)->update(['due_t' => World::gameNow() - 1]);
        $this->assertSame(1, PlayerShares::settleBankPayments());
        $bought = PlayerShares::buyFromBank($holder, $mine, 5);
        $this->assertTrue($bought['ok'] ?? false, json_encode($bought));
        $epoch = (int) DB::table('game_clock')->where('id', 1)->value('epoch');
        $day = intdiv(World::gameNow() - $epoch, 86400) + 1;
        DB::table('companies')->where('company_id', $mine)->update(['player_dividend_day' => $day - 1]);
        DB::table('company_profits')->insert(['company' => $mine, 'day' => $day, 'sales' => 400, 'purchases' => 0, 'profit' => 400]);
        $this->sale($mine, $theirs, 400, 0);
        $this->assertGreaterThan(0, PlayerShares::payDividends());

        // A startup closed, a company wound up and an account deleted, each with credits
        $this->assertTrue(Startups::close($holder, $theirs)['ok']);
        $this->assertNotNull(WindUp::windUp($mine));
        $gone = $this->player('Ledger Leaver');
        DB::table('notice_settings')->where('user_id', $gone->user_id)->delete();
        DB::table('users')->where('user_id', $gone->user_id)->delete();

        $c = CreditCheck::check();
        $this->assertSame(0, $c['difference'], 'credits balance: ' . json_encode($c));
        $this->assertGreaterThan(0, $c['created']);
        $this->assertGreaterThan(0, $c['destroyed']);
        foreach (['company_founded', 'sales_tax', 'processor_tax', 'startup_skim', 'startup_matured', 'bank_paid',
                  'bank_sold_shares', 'dividend_to_bank', 'share_credits_granted', 'ended_closed', 'ended_wound_up',
                  'account_deleted'] as $kind) {
            $this->assertArrayHasKey($kind, $c['kinds'], $kind . ' is recorded');
        }
        $this->assertTrue(CreditCheck::status()['balanced']);
    }

    public function testCreditsChangedWithoutAFlowAreCaught()
    {
        CreditCheck::restart();
        CreditCheck::check();
        $company = (int) DB::table('companies')->where('house', 0)->value('company_id');
        DB::table('companies')->where('company_id', $company)->update(['credits' => DB::raw('credits + 500')]);

        $c = CreditCheck::check();
        $this->assertSame(500, $c['difference']);
        $status = CreditCheck::status();
        $this->assertFalse($status['balanced']);
        $this->assertSame(1, $status['mismatches']);
        $this->assertSame(500, $status['difference']);
        $this->assertSame($c['day'], $status['last_mismatch_day']);

        // The clock API says so, and the command fails while it doesn't balance
        $this->getJson('/api/v1/clock')->assertOk()->assertJsonPath('credits.balanced', false)
            ->assertJsonPath('credits.difference', 500);
        DB::table('companies')->where('company_id', $company)->update(['credits' => DB::raw('credits - 1')]);
        // The economy dashboard says so too
        Cache::flush();
        $this->get('/dashboard')->assertOk()->assertSee('Do credits balance?')->assertSee('id="dash-credits-bad"', false);

        DB::table('companies')->where('company_id', $company)->update(['credits' => DB::raw('credits - 1')]);
        $this->artisan('forge:check-credits')->assertExitCode(1);
        $this->artisan('forge:check-credits')->assertExitCode(0);
    }

    public function testOnceAGameDayAndAFreshStartAfterAReset()
    {
        CreditCheck::restart();
        $this->assertNotNull(CreditCheck::checkIfDue(), 'the first check of the day runs');
        $this->assertNull(CreditCheck::checkIfDue(), 'only one a game day');
        $this->assertSame(1, DB::table('credit_checks')->count());

        CreditCheck::restart();
        $this->assertNull(CreditCheck::check()['difference'], 'after a reset the next check is a baseline');
    }
}
