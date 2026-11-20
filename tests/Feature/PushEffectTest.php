<?php

namespace Tests\Feature;

use App\Services\CodePush;
use App\Services\Players;
use App\Services\Startups;
use App\Services\World;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** "This change did X" (#772): each push against the code before it */
class PushEffectTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['forge.new_rules' => true, 'forge.startup_max' => 5]);
        Mail::fake();
        Cache::flush();
    }

    private function startup(): array
    {
        $email = 'pusher@example.com';
        $user = Players::create('Pusher', 'long-enough', $email);
        Players::verify($user->user_id, sha1($email));
        $p = User::where('user_id', $user->user_id)->first();
        $id = Startups::start($p, 'Pusher Co', 'function launched() wait(60) end')['company'];
        $drone = (int) DB::table('agents')->where('company_id', $id)->value('agent_id');
        $other = (int) DB::table('agents')->where('agent_id', '<>', $drone)->value('agent_id');
        return [$p, $id, $drone, $other];
    }

    private function failAt(int $drone, int $t): void
    {
        $a = DB::table('actions')->insertGetId(['drone' => $drone, 'protocol' => 3, 'multiplier' => 1, 'aborted' => 0, 'outcome' => -2]);
        DB::table('events')->insert(['event_type' => 1, 'action' => $a, 'logged' => 1, 'outcome' => -2, 'drone' => $drone,
            'resource' => 2011, 'installed' => 0, 'locked' => 0, 'new_quantity' => 0, 'game_t' => $t]);
    }

    public function testAPushIsComparedWithTheSameSpanBefore()
    {
        [$p, $id, $drone, $other] = $this->startup();
        $now = World::gameNow();
        $push = $now - 2 * 86400;
        DB::table('companies')->where('company_id', $id)->update(['founded_t' => $now - 5 * 86400]);
        DB::table('code_pushes')->insert(['company_id' => $id, 'number' => 1, 'game_t' => $push, 'credits_before' => 1000,
            'permille' => 0, 'fee' => 0, 'drones' => 1, 'script' => 'x']);
        $trade = fn (int $seller, int $buyer, int $credits, int $t) => DB::table('trades')->insert(['resource' => 2011, 'units' => 1,
            'credits' => $credits, 'tax' => 0, 'seller' => $seller, 'buyer' => $buyer, 'location' => 3, 'game_t' => $t]);
        // Before: sold 200, bought 100, failed 4 times; after: sold 1,000, failed none
        $trade($drone, $other, 200, $push - 3600);
        $trade($other, $drone, 100, $push - 7200);
        $trade($drone, $other, 1000, $push + 3600);
        $trade($drone, $other, 5000, $push - 3 * 86400); // outside the span
        // A kit bought after the push is invested, not a trading loss (#879)
        DB::table('trades')->insert(['resource' => \App\Services\Release::KITS['mining'], 'units' => 1, 'credits' => 2000,
            'tax' => 0, 'seller' => $other, 'buyer' => $drone, 'location' => 3, 'game_t' => $push + 7200]);
        foreach ([1, 2, 3, 4] as $h) {
            $this->failAt($drone, $push - $h * 3600);
        }

        $e = CodePush::effect($id, $push);
        $this->assertTrue($e['ready']);
        $this->assertEquals(2, $e['span_days']);
        $this->assertSame([50, 100, 2.0], [$e['before']['net'], $e['before']['sales'], $e['before']['failed']]);
        $this->assertSame([500, 500, 0.0], [$e['after']['net'], $e['after']['sales'], $e['after']['failed']]);
        $this->assertSame(900, $e['net_change_percent']);
        $this->assertStringContainsString('earns more from trading (50 → 500 a game day)', $e['verdict']);
        $this->assertStringContainsString('fails less', $e['verdict']);

        $this->actingAs($p)->get("/companies/$id")->assertOk()->assertSee('id="last-push-effect"', false)
            ->assertSee('id="push-effects"', false)->assertSee('earns more from trading');
        $this->getJson("/api/v1/companies/$id")->assertJsonPath('pushes.log.0.effect.after.net', 500);
    }

    public function testTooEarlyAndNothingBefore()
    {
        [, $id] = $this->startup();
        $now = World::gameNow();
        DB::table('companies')->where('company_id', $id)->update(['founded_t' => $now - 5 * 86400]);
        $this->assertFalse(CodePush::effect($id, $now - 3600)['ready']);
        $this->assertStringContainsString('too early', CodePush::effect($id, $now - 3600)['note']);
        // A push an hour after the one before has nothing to compare with
        DB::table('code_pushes')->insert(['company_id' => $id, 'number' => 1, 'game_t' => $now - 2 * 86400, 'credits_before' => 0,
            'permille' => 0, 'fee' => 0, 'drones' => 1, 'script' => 'x']);
        $this->assertStringContainsString('too little before', CodePush::effect($id, $now - 2 * 86400 + 3600)['note']);
    }
}
