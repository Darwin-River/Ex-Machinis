<?php

namespace Tests\Feature;

use App\Company;
use App\Services\TestWorld;
use App\Services\Release;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Test runs on the Release page (#324 step 7): the Test button runs a script
 * in the test world's own database and leaves the live world alone; anyone
 * may, and each run has its own page (#367 step 3). Runs in transactions on
 * both databases, so nothing is left behind.
 */
class TestWorldTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'testworld'];

    private const SCRIPT = ": launched  .\" test-run-ran\" cr ;";

    protected function setUp(): void
    {
        parent::setUp();

        config(['forge.test_world' => true]);
    }

    private function test()
    {
        return DB::connection('testworld');
    }

    public function testTestRunGoesToTheTestWorldOnly()
    {
        $liveCompanies = Company::count();

        $response = $this->post('/release/test', ['script' => self::SCRIPT]);

        $this->assertEquals($liveCompanies, Company::count(), 'a test run must not touch the live world');

        $company = $this->test()->table('companies')->where('name', 'like', 'Test %')->orderBy('company_id', 'desc')->first();
        $response->assertRedirect('/release/test/' . substr($company->name, 5));
        $this->assertNotNull($company, 'no test company');
        $drone = $this->test()->table('agents')->where('company_id', $company->company_id)->first();
        $this->assertNotNull($drone, 'no test drone');
        $this->assertEquals(3, $drone->object_id);
        $this->assertEquals([self::SCRIPT, '<launched>'],
            $this->test()->table('commands')->where('agent_id', $drone->agent_id)->orderBy('id')->pluck('code')->all());
        $this->assertEquals(Release::owner('testworld')->user_id, $company->user_id,
            'the test world\'s built-in owner owns it');
        $this->assertNotNull($this->test()->table('events')->where('drone', $drone->agent_id)->where('resource', 5901)->first(),
            'the test drone gets the same starter kit');

        // and its first fuel in the hold (#324 step 8), on top of the kit
        $fuel = $this->test()->table('events')->where('drone', $drone->agent_id)->where('resource', 2061)->first();
        $this->assertNotNull($fuel, 'a new drone starts with fuel');
        $this->assertEquals(config('forge.starting_fuel'), $fuel->new_quantity);
        $kitMass = $this->test()->table('resources')->where('id', 5901)->value('mass');
        $this->assertEquals($kitMass + config('forge.starting_fuel'), $fuel->new_cargo, 'the hold counts the kit and the fuel');
    }

    public function testTestRunNeedsNoCompanyNameButALaunchedWord()
    {
        $this->from('/release')
            ->post('/release/test', ['script' => ': go ;'])
            ->assertRedirect('/release')->assertSessionHasErrors('script');
    }

    public function testOnlyTheLatestTestRunsAreKept()
    {
        config(['forge.test_runs_kept' => 3]);
        $this->test()->table('companies')->where('name', 'like', 'Test %')->delete();
        for ($i = 0; $i < 5; $i++) {
            $last = TestWorld::run(self::SCRIPT);
        }
        $kept = 3;

        $companies = $this->test()->table('companies')->where('name', 'like', 'Test %')->pluck('company_id');
        $this->assertCount($kept, $companies);
        $this->assertEquals($kept, $this->test()->table('agents')->whereIn('company_id', $companies)->count());
        $this->assertNotNull(TestWorld::runOf($last), 'the latest run is kept');
    }

    public function testATestRunHasItsOwnPage()
    {
        $token = TestWorld::run(self::SCRIPT);
        $drone = TestWorld::runOf($token)->drone;
        $this->test()->table('drone_messages')->insert(
            ['agent_id' => $drone->agent_id, 'game_t' => 0, 'subject' => 'Release', 'body' => 'test-run-ran']);

        $this->get('/release/test/' . $token)->assertOk()
            ->assertSee($drone->name)->assertSee('test-run-ran')
            ->assertSee('Increment inventory to'); // the starter kit's event
        $this->get('/release/test/0123456789abcdef')->assertNotFound();
        $this->get('/release/test/not-a-token')->assertNotFound();
    }

    public function testNoTestButtonWhenTheTestWorldIsOff()
    {
        config(['forge.test_world' => false]);
        $this->get('/release')->assertOk()->assertDontSee('release/test');
        $this->from('/release')->post('/release/test', ['script' => self::SCRIPT])
            ->assertRedirect('/release')->assertSessionHasErrors('script');
    }
}
