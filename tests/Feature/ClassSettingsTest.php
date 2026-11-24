<?php

namespace Tests\Feature;

use App\Services\ClassSettings;
use App\Services\Loans;
use App\Services\Ownership;
use App\Services\Players;
use App\Services\Startups;
use App\Services\World;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Class settings (#1003): takeovers within the class and loans only within
 * the class, each a teacher's choice (both on by default), and a read-only
 * log of what passed between the pupils.
 */
class ClassSettingsTest extends TestCase
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

    private function pupil(string $name): User
    {
        return User::where('login', $name)->first();
    }

    /** A pupil's matured company, its shares all theirs */
    private function matured(User $owner, string $name): int
    {
        $id = Startups::start($owner, $name, 'function launched() wait(60) end')['company'];
        DB::table('companies')->where('company_id', $id)->update(['credits' => 1000 + Startups::grant()]);
        $this->assertTrue(Startups::mature($owner, $id)['ok']);
        return $id;
    }

    private function request(int $company, int $amount): int
    {
        return (int) DB::table('loan_requests')->insertGetId(['company_id' => $company,
            'drone' => (int) DB::table('agents')->where('company_id', $company)->value('agent_id'), 'amount' => $amount,
            'rate_bp' => 500, 'requested_t' => World::gameNow(), 'due_t' => World::gameNow() + 20 * 86400]);
    }

    public function testTheTeacherSetsTakeoversAndLoansAndReadsTheLog()
    {
        $teacher = $this->player('Class Teacher');
        $group = Players::createClassGroup($teacher, 'Vega', 3)['id'];
        [$a, $b, $c] = [$this->pupil('Vega 1'), $this->pupil('Vega 2'), $this->pupil('Vega 3')];
        $outsider = $this->player('Class Outsider');
        $this->assertSame(['takeovers' => true, 'loans_within' => true], ClassSettings::of($group));

        // A's company: B holds some, and could buy the rest (takeovers on)
        $ac = $this->matured($a, 'Vega Works');
        DB::table('player_shares')->where('company_id', $ac)->where('user_id', $a->user_id)->update(['units' => 60]);
        DB::table('player_shares')->insert(['user_id' => $b->user_id, 'company_id' => $ac, 'units' => 40]);
        $this->assertTrue(Ownership::wouldOwn($b->user_id, $ac, 60));
        $this->assertNull(Ownership::buyProblem($b->user_id, $ac, 60));

        // The teacher turns takeovers and loans-within off, then loans-within on again
        $this->actingAs($teacher)->post('/account/classes/' . $group . '/settings', ['loans_within' => '1'])
            ->assertRedirect('/account/classes/' . $group . '#class-settings');
        $this->assertSame(['takeovers' => false, 'loans_within' => true], ClassSettings::of($group));
        $this->assertStringContainsString('doesn\'t allow takeovers', (string) Ownership::buyProblem($b->user_id, $ac, 60));
        $this->assertNull(Ownership::buyProblem($b->user_id, $ac, 10), 'a part is fine');
        $this->assertNull(Ownership::buyProblem($a->user_id, $ac, 40), 'its founder may own it outright');
        // Another teacher can't change it
        $this->actingAs($outsider)->post('/account/classes/' . $group . '/settings', [])->assertNotFound();

        // Loans only within the class: B backs A's public company; an outsider can't, nor lend B's credits out
        DB::table('player_shares')->where('company_id', $ac)->where('user_id', $a->user_id)->update(['units' => 50]);
        DB::table('player_shares')->insert(['user_id' => $c->user_id, 'company_id' => $ac, 'units' => 10]);
        $req = $this->request($ac, 3000);
        DB::table('users')->whereIn('user_id', [$b->user_id, $outsider->user_id])->update(['credits' => 9000]);
        $this->assertTrue(Loans::back($b, $req, 1000)['ok']);
        $this->assertStringContainsString('borrows only from its own pupils', Loans::back($outsider, $req, 1000)['error']);
        $theirs = $this->matured($outsider, 'Outside Works');
        DB::table('player_shares')->where('company_id', $theirs)->update(['units' => 90]);
        DB::table('player_shares')->insert(['user_id' => $c->user_id, 'company_id' => $theirs, 'units' => 10]);
        $this->assertStringContainsString('backs only loans its own pupils', Loans::back($b, $this->request($theirs, 500), 100)['error']);
        // With loans-within off, both may
        ClassSettings::save($teacher, $group, false, false);
        $this->assertTrue(Loans::back($outsider, $req, 500)['ok']);

        // The log: a share trade between pupils, the classmate's loan, a dividend to a classmate
        DB::table('player_share_trades')->insert(['company_id' => $ac, 'seller_id' => $a->user_id, 'buyer_id' => $b->user_id,
            'units' => 5, 'price' => 77, 'game_t' => World::gameNow()]);
        DB::table('player_dividends')->insert(['company_id' => $ac, 'user_id' => $b->user_id, 'day' => \App\Services\MarketHistory::today(),
            'units' => 40, 'amount' => 123]);
        $log = ClassSettings::log($group);
        $this->assertSame([['Vega 1', 'Vega 2', 77]], array_map(fn ($t) => [$t['seller'], $t['buyer'], $t['price']], $log['trades']));
        $this->assertSame(['Vega 2'], array_column($log['loans'], 'lender'), 'the outsider\'s loan is not between pupils');
        $this->assertSame([['Vega 2', 123]], array_map(fn ($d) => [$d['pupil'], $d['total']], $log['dividends']));
        $this->actingAs($teacher)->get('/account/classes/' . $group)->assertOk()->assertSee('id="class-settings"', false)
            ->assertSee('Vega 1 sold Vega 2 5 Vega Works shares at 77')->assertSee("Vega 2 lent Vega Works (Vega 1's) 1,000", false)
            ->assertSee('Vega Works paid Vega 2 123 in dividends');
    }
}
