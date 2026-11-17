<?php

namespace Tests\Feature;

use App\Services\Players;
use App\Services\Seedlings;
use App\Services\SinceLooked;
use App\Services\World;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** "Since you last looked" and the personal feed (#771) */
class SinceLookedTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['forge.new_rules' => true, 'forge.seedling_max' => 5]);
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

    public function testTheCardShowsSalesTheWorstFailureAndClearsOnGotIt()
    {
        $p = $this->player('Looker');
        $id = Seedlings::start($p, 'Looker Co', 'function launched() wait(60) end')['company'];
        $drone = (int) DB::table('agents')->where('company_id', $id)->value('agent_id');
        DB::table('users')->where('user_id', $p->user_id)->update(['last_seen_t' => World::gameNow() - 3 * 86400, 'last_seen_trade' => null]);
        $house = (int) DB::table('agents')->where('agent_id', '<>', $drone)->value('agent_id');
        DB::table('trades')->insert(['resource' => 2011, 'units' => 5, 'credits' => 100, 'tax' => 5, 'seller' => $drone, 'buyer' => $house,
            'location' => 3, 'game_t' => World::gameNow() - 3600]);
        // A make that keeps failing for want of an input
        foreach ([1, 2, 3, 4] as $h) {
            $a = DB::table('actions')->insertGetId(['drone' => $drone, 'protocol' => 3, 'multiplier' => 1, 'aborted' => 0, 'outcome' => -2]);
            DB::table('events')->insert(['event_type' => 1, 'action' => $a, 'logged' => 1, 'outcome' => -2, 'drone' => $drone,
                'resource' => 2011, 'installed' => 0, 'locked' => 0, 'new_quantity' => 0, 'game_t' => World::gameNow() - $h * 3600]);
        }
        $card = SinceLooked::for($p);
        $this->assertSame(95, $card['companies'][0]['sales']);
        $this->assertSame(4, $card['failing']['times']);
        $this->assertStringContainsString('missing', $card['failing']['hint']);
        $this->actingAs($p)->get('/')->assertOk()->assertSee('id="since-looked"', false)->assertSee('To fix:')
            ->assertSee('id="my-feed"', false)->assertSee('sold 5');
        $this->actingAs($p)->get('/companies/' . $id)->assertOk()->assertSee('id="since-looked"', false);

        // Got it: the card goes until something new happens
        $this->actingAs($p)->post('/account/seen')->assertRedirect();
        $this->assertSame(0, SinceLooked::for($p)['companies'][0]['sales'] ?? 0);
    }

    public function testNothingForVisitors()
    {
        $this->get('/')->assertOk()->assertDontSee('id="since-looked"', false)->assertDontSee('id="my-feed"', false);
    }
}
