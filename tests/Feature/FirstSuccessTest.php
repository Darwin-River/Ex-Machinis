<?php

namespace Tests\Feature;

use App\Services\FirstMission;
use App\Services\Players;
use App\Services\Release;
use App\Services\Startups;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Getting new players to a first success (#932): starters that mark the
 * three numbers to try first, a guided first mission on a startup's page,
 * and the strip under the menu with the next step and the rules so far.
 */
class FirstSuccessTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['forge.new_rules' => true, 'forge.startup_hull_value' => 2000, 'forge.startup_fee' => 500,
            'forge.starting_credits' => 3000, 'forge.startup_max' => 5, 'forge.startup_mature_margin' => 0,
            'forge.lend_min_credits' => 5000]);
        Mail::fake();
        Cache::flush();
    }

    private function player(string $name): User
    {
        $email = strtolower(str_replace(' ', '.', $name)) . '@example.com';
        $u = Players::create($name, 'long-enough', $email);
        Players::verify($u->user_id, sha1($email));
        return User::where('user_id', $u->user_id)->first();
    }

    public function testEachStarterMarksThreeNumbersToTryFirst()
    {
        foreach (['miner', 'refiner', 'maker'] as $name) {
            $code = Release::starter($name);
            $this->assertSame(3, preg_match_all('/^\w+ = \S+\s+-- TRY FIRST: /m', $code), "$name marks three");
            $this->assertStringContainsString('It matures as it is', $code);
            $try = FirstMission::tryFirst($code);
            $this->assertNotNull($try, $name);
            // The line it names is the line that sets it
            $this->assertStringStartsWith($try['name'] . ' = ' . $try['value'], explode("\n", $code)[$try['line'] - 1]);
            $this->assertSame($name, FirstMission::starter($code));
        }
        $this->assertNull(FirstMission::tryFirst('function launched() end'));
        $this->assertNull(FirstMission::starter('function launched() end'));
    }

    public function testTheFirstMissionGuidesAStartupsOwnerUntilTheyOwnAMaturedCompany()
    {
        $me = $this->player('Mission Pilot');
        $miner = Release::starter('miner');
        $id = Startups::start($me, 'Mission Co', $miner)['company'];
        $try = FirstMission::tryFirst($miner);
        $page = $this->actingAs($me)->get('/companies/' . $id)->assertOk()
            ->assertSee('id="first-mission"', false)->assertSee('Your first hour:')
            ->assertSee('change ' . $try['name'] . ' on line ' . $try['line'])->assertSee('id="mission-time"', false)
            ->assertSee('id="mission-line"', false);
        // Others don't see it
        auth()->logout();
        $this->get('/companies/' . $id)->assertOk()->assertDontSee('id="first-mission"', false);
        // After a push, step 2 is done
        DB::table('companies')->where('company_id', $id)->update(['pushes' => 1]);
        $this->actingAs($me)->get('/companies/' . $id)->assertOk()->assertSee('Done: you pushed new code.')->assertDontSee('id="mission-line"', false);
        // Once matured, the mission is over (on every startup of theirs)
        $other = Startups::start($me, 'Mission Two', $miner)['company'];
        DB::table('companies')->where('company_id', $id)->update(['credits' => 6000]);
        $this->assertTrue(Startups::mature($me, $id)['ok']);
        $this->actingAs($me)->get('/companies/' . $other)->assertOk()->assertDontSee('id="first-mission"', false);
    }

    public function testTheStripShowsTheNextStepAndTheRulesInLayers()
    {
        $me = $this->player('Strip Reader');
        // A visitor has no strip
        $this->get('/')->assertOk()->assertDontSee('id="next-strip"', false);
        // The basics only: 5 rules of 10, fuel and travel among them
        $html = $this->actingAs($me)->get('/market')->assertOk()->assertSee('id="next-strip"', false)
            ->assertSee('found your first startup')->assertSee('The rules so far (5 of 11)')
            ->assertSee('Fuel and travel')->assertSee('More rules appear here when you own a matured company')
            ->assertDontSee('Only public companies borrow')->getContent();
        // Owning a matured company opens the next layer
        $id = Startups::start($me, 'Strip Co', 'function launched() wait(60) end')['company'];
        DB::table('companies')->where('company_id', $id)->update(['credits' => 6000]);
        $this->assertTrue(Startups::mature($me, $id)['ok']);   // forgets the strip
        $this->actingAs($me)->get('/market')->assertOk()->assertSee('The rules so far (9 of 11)')
            ->assertSee('buy a share in another player')->assertSee('The first share you sell makes a company public');
        // The lending mark: a bar of credits, then the last layer
        $theirs = Startups::start($this->player('Strip Founder'), 'Their Co', 'function launched() wait(60) end')['company'];
        DB::table('player_shares')->insert(['user_id' => $me->user_id, 'company_id' => $theirs, 'units' => 1]);
        DB::table('users')->where('user_id', $me->user_id)->update(['credits' => 2500]);
        Cache::flush();
        $this->actingAs($me)->get('/market')->assertOk()->assertSee('back a company\'s loan request', false)
            ->assertSee('Your credits: 2,500 of 5,000')->assertSee('The rules so far (11 of 11)')->assertSee('Only public companies borrow');
    }
}
