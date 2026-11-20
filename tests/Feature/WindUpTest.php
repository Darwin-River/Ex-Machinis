<?php

namespace Tests\Feature;

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

/** #875: a matured company that sells nothing for 60 game days is wound up, its credits shared out */
class WindUpTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['forge.new_rules' => true, 'forge.startup_max' => 5, 'forge.startup_mature_margin' => 0]);
        Mail::fake();
        Cache::forget('wind-up-looked');
    }

    private function player(string $name): User
    {
        $email = strtolower(str_replace(' ', '.', $name)) . '@example.com';
        $user = Players::create($name, 'long-enough', $email);
        Players::verify($user->user_id, sha1($email));
        return User::where('user_id', $user->user_id)->first();
    }

    public function testAStalledCompanyIsWarnedThenWoundUp()
    {
        $owner = $this->player('Idle Owner');
        $holder = $this->player('Idle Holder');
        $id = Startups::start($owner, 'Idle Co', 'function launched() wait(60) end')['company'];
        DB::table('companies')->where('company_id', $id)->update(['credits' => Startups::grant()]);
        $this->assertTrue(Startups::mature($owner, $id)['ok']);
        // 100 shares: the owner keeps 80, a holder has 20; it holds 100,000 credits
        DB::table('player_shares')->where('user_id', $owner->user_id)->where('company_id', $id)->update(['units' => 80]);
        DB::table('player_shares')->insert(['user_id' => $holder->user_id, 'company_id' => $id, 'units' => 20]);
        DB::table('companies')->where('company_id', $id)->update(['credits' => 100000]);
        $now = World::gameNow();

        // Matured 40 game days ago, never sold: warned, 20 game days left
        DB::table('companies')->where('company_id', $id)->update(['matured_t' => $now - 40 * 86400]);
        $this->assertSame(20, WindUp::daysLeft($id));
        $this->actingAs($owner)->get('/companies/' . $id)->assertOk()->assertSee('id="wind-up-banner"', false)
            ->assertSee('Winding up in 20 game days');
        $this->assertSame(0, WindUp::runIfDue(), 'not yet');
        // A sale 10 game days ago starts the count again
        $drone = (int) DB::table('agents')->where('company_id', $id)->value('agent_id');
        $other = (int) DB::table('agents')->where('agent_id', '<>', $drone)->value('agent_id');
        $saleId = DB::table('trades')->insertGetId(['resource' => 2011, 'units' => 1, 'credits' => 10, 'tax' => 0, 'seller' => $drone,
            'buyer' => $other, 'location' => 3, 'game_t' => $now - 10 * 86400]);
        $this->assertNull(WindUp::daysLeft($id));
        DB::table('trades')->where('id', $saleId)->delete();

        // #837: game days the world stood still don't count. 70 game days, 30 of them a stall: 40, so 20 left
        DB::table('companies')->where('company_id', $id)->update(['matured_t' => $now - 70 * 86400]);
        $stall = DB::table('world_stalls')->insertGetId(['from_t' => $now - 50 * 86400, 'to_t' => $now - 20 * 86400]);
        $this->assertSame(20, WindUp::daysLeft($id));
        $this->assertSame([20], array_values(array_filter(WindUp::daysLeftMany([$id]))));
        Cache::forget('wind-up-looked');
        $this->assertSame(0, WindUp::runIfDue(), 'not wound up: 30 of its 70 days the world stood still');
        DB::table('world_stalls')->where('id', $stall)->delete();

        // 61 game days without a sale: wound up, the credits shared out by shares held
        DB::table('companies')->where('company_id', $id)->update(['matured_t' => $now - 61 * 86400]);
        $before = [(int) DB::table('users')->where('user_id', $owner->user_id)->value('credits'),
                   (int) DB::table('users')->where('user_id', $holder->user_id)->value('credits')];
        Cache::forget('wind-up-looked');
        $this->assertSame(1, WindUp::runIfDue());
        $c = DB::table('companies')->where('company_id', $id)->first();
        $this->assertNotNull($c->eliminated_at);
        $this->assertSame('wound_up', $c->end_cause);
        $this->assertSame(0, (int) $c->credits);
        $this->assertSame($before[0] + 80 * 1000, (int) DB::table('users')->where('user_id', $owner->user_id)->value('credits'));
        $this->assertSame($before[1] + 20 * 1000, (int) DB::table('users')->where('user_id', $holder->user_id)->value('credits'));
        $this->assertSame('wound_up', DB::table('agents')->where('agent_id', $drone)->value('wreck_cause'));
        $this->assertSame(1000, (int) DB::table('company_windups')->where('company_id', $id)->value('per_share'));
        $this->assertNull(WindUp::windUp($id), 'once');
        $this->get('/companies/' . $id)->assertOk()->assertSee('wound up');
    }
}
