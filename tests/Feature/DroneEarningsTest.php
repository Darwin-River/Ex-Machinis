<?php

namespace Tests\Feature;

use App\Services\DroneEarnings;
use App\Services\Players;
use App\Services\Seedlings;
use App\Services\World;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** What each drone earned, and a company's drones ranked by it (#774) */
class DroneEarningsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['forge.new_rules' => true, 'forge.seedling_max' => 5]);
        Mail::fake();
        Cache::flush();
    }

    public function testEarningsRunningTotalAndRanking()
    {
        $email = 'earner@example.com';
        $made = Players::create('Earner', 'long-enough', $email);
        Players::verify($made->user_id, sha1($email));
        $p = User::where('user_id', $made->user_id)->first();
        $id = Seedlings::start($p, 'Earner Co', 'function launched() wait(60) end')['company'];
        $first = (int) DB::table('agents')->where('company_id', $id)->value('agent_id');
        $user = (int) DB::table('agents')->where('agent_id', $first)->value('user_id');
        $copy = DB::table('agents')->insertGetId(['user_id' => $user, 'company_id' => $id, 'name' => 'Earner Copy',
            'object_id' => 3, 'hull_type' => 1, 'language' => 'lua', 'parent_id' => $first]);
        $other = (int) DB::table('agents')->whereNotIn('agent_id', [$first, $copy])->value('agent_id');
        $now = World::gameNow();
        DB::table('companies')->where('company_id', $id)->update(['founded_t' => $now - 4 * 86400]);
        $trade = fn ($s, $b, $c, $tax, $t) => DB::table('trades')->insert(['resource' => 2011, 'units' => 1, 'credits' => $c, 'tax' => $tax,
            'seller' => $s, 'buyer' => $b, 'location' => 3, 'game_t' => $t]);
        // The first drone: bought 100, sold 500 (tax 20): 380 over 4 game days
        $trade($other, $first, 100, 0, $now - 3 * 86400);
        $trade($first, $other, 500, 20, $now - 2 * 86400);
        // The copy: sold 1,000 over its 1 game day
        $trade($copy, $other, 1000, 0, $now - 86400);

        // Its kit is invested, not spent (#833): it doesn't count against it, in net or in the running total
        DB::table('trades')->insert(['resource' => 5901, 'units' => 1, 'credits' => 2000, 'tax' => 0, 'seller' => $other, 'buyer' => $first,
            'location' => 3, 'game_t' => $now - 4 * 86400 + 600]);

        $e = DroneEarnings::of($first, true);
        $this->assertSame([480, 100, 380, 95], [$e['sales'], $e['bought'], $e['net'], $e['per_day']]);
        $this->assertSame(2000, $e['invested']);
        $this->assertSame(0, $e['before']);
        $this->assertSame([-100, 380], array_column($e['series'], 'net'));

        $ranked = DroneEarnings::ranked($id);
        $this->assertSame([$copy, $first], array_column($ranked, 'agent_id'));
        $this->assertSame(1000, $ranked[0]['per_day']);

        $this->get("/companies/$id")->assertOk()->assertSee('id="drone-earnings"', false)->assertSee('Earner Copy');
        $this->get("/spacecraft/$first")->assertOk()->assertSee('id="drone-earned"', false)
            ->assertSee('380 over its life, 95 a game day')->assertSee('id="da-earned"', false);
        $this->getJson("/api/v1/drones/$first")->assertJsonPath('earnings.net', 380);
    }
}
