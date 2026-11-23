<?php

namespace Tests\Feature;

use App\Services\Pipeline;
use App\Services\Players;
use App\Services\Startups;
use App\Services\World;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The path through the game (#987): make an account, code drones, invest,
 * lend; the same four steps on the home page, Getting started, sign-up and
 * the account page, each marked done from the player's own records.
 */
class PipelineTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['forge.new_rules' => true, 'forge.startup_hull_value' => 2000, 'forge.startup_fee' => 500,
            'forge.starting_credits' => 3000, 'forge.startup_max' => 5, 'forge.startup_mature_margin' => 0,
            'forge.lend_min_credits' => 5000]);
        Mail::fake();
    }

    private function player(string $name): User
    {
        $email = strtolower(str_replace(' ', '.', $name)) . '@example.com';
        $u = Players::create($name, 'long-enough', $email);
        Players::verify($u->user_id, sha1($email));
        return User::where('user_id', $u->user_id)->first();
    }

    private function state(?User $user): array
    {
        $p = Pipeline::for($user);
        return [array_map(fn ($s) => $s['done'], array_column($p['steps'], null, 'key')), $p['next']];
    }

    public function testAVisitorSeesTheFourStepsAndTheAccountComesFirst()
    {
        $this->assertSame([['account' => false, 'code' => false, 'invest' => false, 'lend' => false], 'account'], $this->state(null));
        $home = $this->get('/')->assertOk()->assertSee('id="pipeline"', false)->getContent();
        // In order, each with its way in
        $at = fn ($t) => strpos($home, $t);
        $this->assertTrue($at('id="pipeline-account"') < $at('id="pipeline-code"') && $at('id="pipeline-code"') < $at('id="pipeline-invest"')
            && $at('id="pipeline-invest"') < $at('id="pipeline-lend"'));
        foreach (['signup', 'getting-started', 'market/shares', 'market/loans'] as $page) {
            $this->assertStringContainsString('href="' . url($page) . '"', $home);
        }
        $this->get('/getting-started')->assertOk()->assertSee('id="pipeline"', false)->assertSee('you are here')
            ->assertSee('Step 3: Invest')->assertSee('Step 4: Lend');
        $this->get('/signup')->assertOk()->assertSee('id="pipeline"', false);
        // The Play menu follows the path
        $menu = substr($home, strpos($home, 'aria-labelledby="nav-play"'));
        $menu = substr($menu, 0, strpos($menu, '</li>'));
        $this->assertTrue(strpos($menu, 'signup') < strpos($menu, 'getting-started')
            && strpos($menu, 'getting-started') < strpos($menu, 'market/shares') && strpos($menu, 'market/shares') < strpos($menu, 'market/loans'));
    }

    public function testEachStepIsMarkedDoneFromThePlayersRecords()
    {
        $me = $this->player('Path Walker');
        $other = $this->player('Path Founder');
        $this->assertSame([['account' => true, 'code' => false, 'invest' => false, 'lend' => false], 'code'], $this->state($me));
        $this->actingAs($me)->get('/')->assertOk()->assertSee('Next: Your first startup');

        // A startup on its way: its credits toward maturing
        $mine = Startups::start($me, 'Path Co', 'function launched() wait(60) end')['company'];
        $code = Pipeline::for($me)['steps'][1];
        $this->assertSame(['now' => 3000, 'of' => 5500], array_intersect_key($code['progress'], ['now' => 0, 'of' => 0]));
        DB::table('companies')->where('company_id', $mine)->update(['credits' => 6000]);
        $this->assertTrue(Startups::mature($me, $mine)['ok']);
        $this->assertSame([['account' => true, 'code' => true, 'invest' => false, 'lend' => false], 'invest'], $this->state($me));

        // Its own shares don't count as investing; another's do
        $theirs = Startups::start($other, 'Other Co', 'function launched() wait(60) end')['company'];
        DB::table('companies')->where('company_id', $theirs)->update(['credits' => 6000]);
        $this->assertTrue(Startups::mature($other, $theirs)['ok']);
        $this->assertSame('invest', $this->state($me)[1]);
        DB::table('player_shares')->insert(['user_id' => $me->user_id, 'company_id' => $theirs, 'units' => 1]);
        $this->assertSame('lend', $this->state($me)[1]);

        // Lending: credits toward the minimum, then a loan
        DB::table('users')->where('user_id', $me->user_id)->update(['credits' => 1250]);
        $lend = Pipeline::for($me)['steps'][3];
        $this->assertSame([1250, 5000], [$lend['progress']['now'], $lend['progress']['of']]);
        $this->actingAs($me)->get('/account')->assertOk()->assertSee('id="pipeline"', false)
            ->assertSee('Your credits: 1,250 of 5,000')->assertSee('aria-valuenow="1250"', false);
        DB::table('loans')->insert(['request_id' => 1, 'company_id' => $theirs, 'user_id' => $me->user_id, 'principal' => 1000,
            'rate_bp' => 2000, 'lent_t' => World::gameNow(), 'due_t' => World::gameNow() + 86400, 'owed' => 1000]);
        $this->assertSame([['account' => true, 'code' => true, 'invest' => true, 'lend' => true], null], $this->state($me));
        $this->actingAs($me)->get('/')->assertOk()->assertSee('You have made 1 loan.');
    }
}
