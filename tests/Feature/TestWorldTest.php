<?php

namespace Tests\Feature;

use App\Company;
use App\Services\TestWorld;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Test runs on the Release page (#324 step 7): the Test button runs a script
 * in the test world's own database and leaves the live world alone. Runs in
 * transactions on both databases, so nothing is left behind.
 */
class TestWorldTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'testworld'];

    private const SCRIPT = ": launched  .\" test-run-ran\" cr ;";

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config(['forge.email' => false, 'forge.test_world' => true]);
    }

    private function player(): User
    {
        return User::create(['name' => 'Tester', 'email' => 'tester@example.com', 'password' => bcrypt('secret-password')]);
    }

    private function test()
    {
        return DB::connection('testworld');
    }

    public function testTestRunGoesToTheTestWorldOnly()
    {
        $user = $this->player();
        $liveCompanies = Company::count();

        $this->actingAs($user)->post('/release/test', ['script' => self::SCRIPT])
            ->assertRedirect('/release#test-runs');

        $this->assertEquals($liveCompanies, Company::count(), 'a test run must not touch the live world');

        $company = $this->test()->table('companies')->where('user_id', $user->user_id)->first();
        $this->assertNotNull($company, 'no test company');
        $drone = $this->test()->table('agents')->where('company_id', $company->company_id)->first();
        $this->assertNotNull($drone, 'no test drone');
        $this->assertEquals(3, $drone->object_id);
        $this->assertEquals([self::SCRIPT, '<launched>'],
            $this->test()->table('commands')->where('agent_id', $drone->agent_id)->orderBy('id')->pluck('code')->all());
        $this->assertNotNull($this->test()->table('users')->where('user_id', $user->user_id)->first(),
            'the test world needs the player as the owner');
        $this->assertNotNull($this->test()->table('events')->where('drone', $drone->agent_id)->where('resource', 5901)->first(),
            'the test drone gets the same starter kit');
    }

    public function testTestRunNeedsNoCompanyNameButALaunchedWord()
    {
        $this->actingAs($this->player())->from('/release')
            ->post('/release/test', ['script' => ': go ;'])
            ->assertRedirect('/release')->assertSessionHasErrors('script');
    }

    public function testOnlyTheLatestTestRunsAreKept()
    {
        $user = $this->player();
        $kept = config('forge.test_runs_kept');
        for ($i = 0; $i < $kept + 2; $i++) {
            TestWorld::run($user, self::SCRIPT);
        }

        $companies = $this->test()->table('companies')->where('user_id', $user->user_id)->pluck('company_id');
        $this->assertCount($kept, $companies);
        $this->assertEquals($kept, $this->test()->table('agents')->whereIn('company_id', $companies)->count());
    }

    public function testReleasePageShowsTestRunResults()
    {
        $user = $this->player();
        $drone = TestWorld::run($user, self::SCRIPT);
        $this->test()->table('drone_messages')->insert(
            ['agent_id' => $drone->agent_id, 'game_t' => 0, 'subject' => 'Release', 'body' => 'test-run-ran']);

        $this->actingAs($user)->get('/release')->assertOk()
            ->assertSee('Your test runs')->assertSee($drone->name)->assertSee('test-run-ran')
            ->assertSee('Increment inventory to'); // the starter kit's event
    }

    public function testNoTestButtonWhenTheTestWorldIsOff()
    {
        config(['forge.test_world' => false]);
        $user = $this->player();

        $this->actingAs($user)->get('/release')->assertOk()->assertDontSee('release/test');
        $this->actingAs($user)->from('/release')->post('/release/test', ['script' => self::SCRIPT])
            ->assertRedirect('/release')->assertSessionHasErrors('script');
    }
}
