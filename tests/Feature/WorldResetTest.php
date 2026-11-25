<?php

namespace Tests\Feature;

use App\Services\Players;
use App\Services\PlayerShares;
use App\Services\Release;
use App\Services\Startups;
use App\Services\WorldReset;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The reset for the new rules (#642): the old world ends but stays
 * browsable, its drones become wrecks nobody can plunder with nothing
 * aboard, accounts get a fresh start, and the bank opens with matured
 * companies whose shares it sells.
 */
class WorldResetTest extends TestCase
{
    use DatabaseTransactions;

    private const SCRIPT = "function launched() wait(60) end";

    protected function setUp(): void
    {
        parent::setUp();
        config(['forge.new_rules' => true, 'forge.player_share_credits' => 5000, 'forge.trial_auto_verify' => false]);
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

    public function testTheResetEndsTheOldWorldAndOpensANewOne()
    {
        $old = Release::release(Release::owner(), 'Old World Co', self::SCRIPT);
        $p = $this->player('Reset Player');
        $seed = Startups::start($p, 'Reset Startup', self::SCRIPT)['company'];
        $seedDrone = (int) DB::table('agents')->where('company_id', $seed)->value('agent_id');
        DB::table('holdings')->updateOrInsert(['drone' => $old->agent_id, 'resource' => 2061, 'installed' => 0, 'locked' => 0],
            ['units' => 50, 'event_id' => 0]);
        DB::table('users')->where('user_id', $p->user_id)->update(['credits' => 777, 'share_credits' => 12]);
        $house = DB::table('companies')->where('house', 1)->pluck('company_id')->all();

        $this->assertGreaterThanOrEqual(2, WorldReset::plan()['companies']);
        $this->artisan('forge:reset')->assertSuccessful();   // a dry run changes nothing
        $this->assertNull(DB::table('companies')->where('company_id', $old->company_id)->value('eliminated_at'));

        $this->artisan('forge:reset', ['--force' => true, '--bank' => 3])->assertSuccessful();

        // The old world: ended, kept, its drones wrecks with nothing aboard
        foreach ([(int) $old->company_id, $seed] as $id) {
            $c = DB::table('companies')->where('company_id', $id)->first();
            $this->assertNotNull($c, 'kept');
            $this->assertNotNull($c->eliminated_at);
            $this->assertSame('reset', $c->end_cause);
        }
        foreach ([(int) $old->agent_id, $seedDrone] as $drone) {
            $a = DB::table('agents')->where('agent_id', $drone)->first();
            $this->assertSame('reset', $a->wreck_cause);
            $this->assertNotNull($a->plundered_at, 'nobody can plunder it');
            $this->assertNull($a->plundered_by);
            $this->assertSame(0, DB::table('holdings')->where('drone', $drone)->count());
        }
        $this->get('/companies/' . $old->company_id)->assertOk();
        // The House is untouched
        foreach ($house as $id) {
            $this->assertNull(DB::table('companies')->where('company_id', $id)->value('eliminated_at'));
        }

        // The account starts afresh
        $u = DB::table('users')->where('user_id', $p->user_id)->first();
        $this->assertSame(0, (int) $u->credits);
        $this->assertSame(5000, (int) $u->share_credits);
        $this->assertSame(0, DB::table('companies')->where('owner_id', $p->user_id)->whereNull('eliminated_at')->count());

        // The bank's companies: matured, owing nothing, their shares the bank's
        $bank = DB::table('companies')->where('name', 'like', 'Bank %')->whereNull('eliminated_at')->get();
        $this->assertCount(3, $bank);
        foreach ($bank as $c) {
            $this->assertSame(0, (int) $c->startup);
            $this->assertNotNull($c->matured_t);
            $this->assertSame(0, (int) $c->debt);
            $this->assertTrue(PlayerShares::tradable((int) $c->company_id));
            $this->assertSame(Startups::SHARES, PlayerShares::prices((int) $c->company_id)['bank_holds']);
        }
        // and a player can buy them
        $this->assertTrue(PlayerShares::buyFromBank(User::where('user_id', $p->user_id)->first(), (int) $bank[0]->company_id, 1)['ok']);
    }

    public function testDeletingTheAccounts()
    {
        $p = $this->player('Gone Player');
        $this->artisan('forge:reset', ['--force' => true, '--bank' => 0, '--delete-accounts' => true])->assertSuccessful();
        $this->assertFalse(DB::table('users')->where('user_id', $p->user_id)->exists());
        $this->assertTrue(DB::table('users')->where('user_id', Release::owner()->user_id)->exists(), 'the game\'s owner stays');
    }
}
