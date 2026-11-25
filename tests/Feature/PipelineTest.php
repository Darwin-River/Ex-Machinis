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
 * The path through the game (#987), in eight steps since the Player Ladder
 * (#1043): watch, try, keep (an account), found, fix, own, invest, lend; on
 * the home page and the account page, and as a thin line on the step pages,
 * each marked done from the player's own records.
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

    private const KEYS = ['watch', 'try', 'account', 'found', 'fix', 'own', 'invest', 'lend'];

    private function done(array $keys): array
    {
        return array_combine(self::KEYS, array_map(fn ($k) => in_array($k, $keys, true), self::KEYS));
    }

    public function testAVisitorSeesTheEightStepsAndWatchingComesFirst()
    {
        $this->assertSame([$this->done([]), 'watch'], $this->state(null));
        // The home page is the first step: watching it counts, and trying comes next
        $home = $this->get('/')->assertOk()->assertSee('id="pipeline"', false)->getContent();
        $this->assertSame([$this->done(['watch']), 'try'], $this->state(null));
        // In order, each with its way in
        $at = fn ($t) => strpos($home, $t);
        $last = 0;
        foreach (self::KEYS as $key) {
            $this->assertGreaterThan($last, $at('id="pipeline-' . $key . '"'), "$key in order");
            $last = $at('id="pipeline-' . $key . '"');
        }
        foreach (['try', 'signup', 'release', 'getting-started', 'market/shares/first', 'market/loans/first'] as $page) {
            $this->assertStringContainsString('href="' . url($page) . '"', $home);
        }
        // The step pages show the thin line, with where they are
        $this->get('/getting-started')->assertOk()->assertSee('id="ladder"', false)->assertSee('Step 4 of 8');
        $this->get('/signup')->assertOk()->assertSee('id="ladder"', false)->assertSee('Step 3 of 8');
        $this->get('/try')->assertOk()->assertSee('Step 2 of 8');
    }

    public function testEachStepIsMarkedDoneFromThePlayersRecords()
    {
        $me = $this->player('Path Walker');
        $other = $this->player('Path Founder');
        $this->assertSame([$this->done(['watch', 'try', 'account']), 'found'], $this->state($me));
        $this->actingAs($me)->get('/')->assertOk()->assertSee('Next: Found a startup');

        // A startup on its way: its credits toward maturing
        $mine = Startups::start($me, 'Path Co', 'function launched() wait(60) end')['company'];
        $this->assertSame('fix', $this->state($me)[1]);
        DB::table('code_pushes')->insert(['company_id' => $mine, 'number' => 1, 'game_t' => World::gameNow(), 'credits_before' => 3000,
            'permille' => 0, 'fee' => 0, 'drones' => 1, 'script' => 'function launched() wait(5) end']);
        $this->assertSame('own', $this->state($me)[1]);
        $own = Pipeline::step(Pipeline::for($me), 'own');
        $this->assertSame(['now' => 3000, 'of' => 5500], array_intersect_key($own['progress'], ['now' => 0, 'of' => 0]));
        DB::table('companies')->where('company_id', $mine)->update(['credits' => 6000]);
        $this->assertTrue(Startups::mature($me, $mine)['ok']);
        $this->assertSame([$this->done(['watch', 'try', 'account', 'found', 'fix', 'own']), 'invest'], $this->state($me));

        // Its own shares don't count as investing; another's do
        $theirs = Startups::start($other, 'Other Co', 'function launched() wait(60) end')['company'];
        DB::table('companies')->where('company_id', $theirs)->update(['credits' => 6000]);
        $this->assertTrue(Startups::mature($other, $theirs)['ok']);
        $this->assertSame('invest', $this->state($me)[1]);
        DB::table('player_shares')->insert(['user_id' => $me->user_id, 'company_id' => $theirs, 'units' => 1]);
        $this->assertSame('lend', $this->state($me)[1]);

        // Lending: credits toward the minimum, then a loan
        DB::table('users')->where('user_id', $me->user_id)->update(['credits' => 1250]);
        $lend = Pipeline::step(Pipeline::for($me), 'lend');
        $this->assertSame([1250, 5000], [$lend['progress']['now'], $lend['progress']['of']]);
        $this->actingAs($me)->get('/account')->assertOk()->assertSee('id="pipeline"', false)
            ->assertSee('Your credits: 1,250 of 5,000')->assertSee('aria-valuenow="1250"', false);
        DB::table('loans')->insert(['request_id' => 1, 'company_id' => $theirs, 'user_id' => $me->user_id, 'principal' => 1000,
            'rate_bp' => 2000, 'lent_t' => World::gameNow(), 'due_t' => World::gameNow() + 86400, 'owed' => 1000]);
        $this->assertSame([$this->done(self::KEYS), null], $this->state($me));
        $this->actingAs($me)->get('/')->assertOk()->assertSee('You have made 1 loan.');
    }
}
