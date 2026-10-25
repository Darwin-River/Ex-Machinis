<?php

namespace Tests\Feature;

use App\Agent;
use App\Command;
use App\Company;
use App\Services\Release;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The Release page and invite-only accounts (#324 step 6), with email
 * switched off. Runs inside a transaction, so nothing is left behind.
 */
class ReleasePageTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config(['forge.email' => false]);
    }

    private function player(): User
    {
        return User::create(['name' => 'Forge Player', 'email' => 'forge@example.com', 'password' => bcrypt('secret-password')]);
    }

    public function testThereAreNoAccounts()
    {
        // Public Forge has no accounts (#367 step 4): no sign-up or login
        $this->get('/register')->assertNotFound();
        $this->get('/login')->assertNotFound();
        $this->post('/login', ['email' => 'a@example.com', 'password' => 'x'])->assertNotFound();
        $this->assertArrayNotHasKey('forge:invite', \Illuminate\Support\Facades\Artisan::all());
    }

    public function testStarterScriptsFillTheForm()
    {
        $this->get('/release')->assertOk()
            ->assertSee('release?starter=miner', false)->assertSee('release?starter=trader', false);
        foreach (array_keys(Release::STARTERS) as $name) {
            $script = Release::starter($name);
            $this->assertNotNull($script, "starter $name is missing");
            $this->assertTrue(Release::definesLaunched($script), "starter $name defines launched");
            $this->get('/release?starter=' . $name)->assertOk()->assertSee(': launched', false);
        }
        $this->assertNull(Release::starter('../../.env'), 'only the listed starters can be read');
    }

    public function testAnyoneMayRelease()
    {
        // No account needed (#367 step 3)
        $this->get('/release')->assertOk()->assertSee('no account needed');
    }

    public function testReleasingAScriptStartsACompanyWithADroneThatRunsIt()
    {
        $script = ": greet  .\" hello\" cr ;\n: launched  greet ;";

        $response = $this->post('/release', ['company_name' => 'Forge Works', 'script' => $script]);

        $company = Company::where('name', 'Forge Works')->first();
        $this->assertNotNull($company);
        // Owned by the built-in account (#367 step 3: no player accounts)
        $this->assertEquals(Release::owner()->user_id, $company->user_id);
        $this->assertEquals(config('forge.starting_credits'), $company->credits);

        $drones = Agent::where('company_id', $company->company_id)->get();
        $this->assertCount(1, $drones);
        $this->assertEquals(Company::EARTH_OBJECT_ID, $drones[0]->object_id);
        // The new drone's page is where to follow it
        $response->assertRedirect('/spacecraft/' . $drones[0]->agent_id);
        $this->get('/spacecraft/' . $drones[0]->agent_id)->assertOk()->assertSee('Released!');

        // The script, then <launched>, in order
        $codes = Command::where('agent_id', $drones[0]->agent_id)->orderBy('id')->pluck('code')->all();
        $this->assertEquals([$script, '<launched>'], $codes);
        Mail::assertNothingSent();
    }

    public function testScriptMustDefineLaunched()
    {
        $this->from('/release')
            ->post('/release', ['company_name' => 'No Entry', 'script' => ': go 1 2 + ;'])
            ->assertRedirect('/release')->assertSessionHasErrors('script');
        $this->assertNull(Company::where('name', 'No Entry')->first());
    }

    public function testLaunchedIsFoundOnlyAsADefinition()
    {
        $this->assertTrue(Release::definesLaunched(": launched ;"));
        $this->assertTrue(Release::definesLaunched("variable x\n:   launched\n  x @ . ;"));
        $this->assertFalse(Release::definesLaunched(": Launched ;"));
        $this->assertFalse(Release::definesLaunched(": launched-later ;"));
        $this->assertFalse(Release::definesLaunched("launched"));
    }

    public function testLongScriptsAreSplitAtLineEnds()
    {
        $line = str_repeat('1 drop ', 100); // 700 characters
        $script = implode("\n", array_fill(0, 7, $line));
        $chunks = Release::chunks($script);

        $this->assertGreaterThan(1, count($chunks));
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(Command::MAX_CODE_LENGTH, mb_strlen($chunk));
        }
        $this->assertEquals($script, implode("\n", $chunks));
    }

    public function testDronePageShowsRepliesThatHaveReachedEarth()
    {
        $agent = Release::release(Release::owner(), 'Reply Co', ": launched ;");
        $now = \App\Http\Controllers\ReleaseController::gameNow();
        DB::table('drone_messages')->insert([
            ['agent_id' => $agent->agent_id, 'game_t' => $now - 5, 'subject' => 'Release', 'body' => 'arrived-reply'],
            ['agent_id' => $agent->agent_id, 'game_t' => $now + 100000, 'subject' => 'Release', 'body' => 'still-in-transit'],
        ]);

        // Public, like its code (#367 step 3)
        $this->get('/spacecraft/' . $agent->agent_id)->assertOk()
            ->assertSee('What it said')->assertSee('arrived-reply')->assertDontSee('still-in-transit');
    }

    public function testMailIsNotReadWhenEmailIsOff()
    {
        config(['app.cron_key' => 'k']);
        $this->get('/get-mails/k')->assertOk()->assertSee('Email is switched off');
    }
}
