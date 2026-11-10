<?php

namespace Tests\Feature;

use App\Agent;
use App\Command;
use App\Company;
use App\Services\Release;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
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
            $this->get('/release?starter=' . $name)->assertOk()
                ->assertSee('function launched()', false);
        }
        $this->assertNotNull(Release::starter('miner.lua'), 'a starter may be named with its extension');
        $this->assertNull(Release::starter('../../.env'), 'only the listed starters can be read');
    }

    public function testAnyoneMayRelease()
    {
        // No account needed (#367 step 3)
        $this->get('/release')->assertOk()->assertSee('no account needed');
    }

    public function testReleasingAScriptStartsACompanyWithADroneThatRunsIt()
    {
        $script = "function greet() print(\"hello\") end\nfunction launched() greet() end";

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
    }

    public function testAReleaseStartsWithCreditsAndNoKit()
    {
        // #506: credits instead of a kit; the script equips itself
        $this->get('/release')->assertOk()->assertDontSee('name="kit"', false)->assertSee('equip(mining_kit)');
        $this->post('/release', ['company_name' => 'Free Hands', 'script' => 'function launched() end', 'kit' => 'factory']);
        $company = Company::where('name', 'Free Hands')->first();
        $drone = Agent::where('company_id', $company->company_id)->first();
        $this->assertSame(3000, (int) $company->credits);
        $this->assertNull($company->start_kit);
        $this->assertSame(0, DB::table('events')->where('drone', $drone->agent_id)
            ->whereIn('resource', array_values(Release::KITS))->count());
        // A fresh release is worth 0: its credits and fuel are its starting point
        $this->assertSame(0, \App\Services\Leaderboard::wealth()[$company->company_id]['wealth']);
    }

    public function testScriptMustDefineLaunched()
    {
        $this->from('/release')
            ->post('/release', ['company_name' => 'No Entry', 'script' => 'function go() return 3 end'])
            ->assertRedirect('/release')->assertSessionHasErrors('script');
        $this->assertNull(Company::where('name', 'No Entry')->first());
    }

    public function testAForthScriptIsRefused()
    {
        // FORTH was retired after round 38 (#380): new scripts are Lua
        $this->from('/release')
            ->post('/release', ['company_name' => 'Old Hands', 'script' => ": launched 60 wait drop ;"])
            ->assertRedirect('/release')->assertSessionHasErrors('script');
        $this->assertNull(Company::where('name', 'Old Hands')->first());
        $this->assertStringContainsString('FORTH has been retired', Release::scriptProblem(': launched ;'));
        $this->assertFalse(Release::definesLaunched(': launched ;'));
    }

    public function testALuaScriptIsReleasedAsLua()
    {
        // #380: a script that defines the function launched (and no FORTH
        // word launched) is Lua; its drone runs it in Lua
        $this->assertTrue(Release::isLua("function launched()\n  wait(60)\nend\n"));
        $this->assertTrue(Release::isLua("local x = 1\nlaunched = function() end"));
        $this->assertFalse(Release::isLua(": launched 60 wait drop ;"));
        $this->assertFalse(Release::isLua("\\ function launched()\n: launched ;"));
        $this->assertTrue(Release::definesLaunched(file_get_contents(base_path('examples/starters/maker.lua'))));
        $this->assertNull(Release::redefinedWarning("function launched() wait(60) end"));

        $agent = Release::release(Release::owner(), 'Lua Co', file_get_contents(base_path('examples/starters/maker.lua')));
        $this->assertEquals('lua', DB::table('agents')->where('agent_id', $agent->agent_id)->value('language'));
        $this->assertEquals('<launched>', Command::where('agent_id', $agent->agent_id)->orderBy('id', 'desc')->value('code'));

    }

    public function testALuaScriptThatReusesAGameNameIsWarned()
    {
        // #380 round 35: best_bid = ... hid the library function
        $script = "best_bid = 30\nfunction sell_all(r) end\nmy_price, fuel = 1, 2\nfunction launched() end\n";
        $this->assertEquals(['best_bid', 'sell_all', 'fuel'], Release::luaRedefinedWords($script));
        $this->assertStringContainsString('best_bid, sell_all, fuel', Release::redefinedWarning($script));
        foreach (array_merge(glob(base_path('examples/starters/*.lua')), glob(base_path('examples/companies/*.lua'))) as $file) {
            $this->assertEquals([], Release::luaRedefinedWords(file_get_contents($file)), basename($file));
        }
    }

    public function testALuaScriptThatUsesAnUndefinedNameIsWarned()
    {
        // #380 round 35: a misspelt game word failed only once the drone ran it
        if (!is_executable(config('forge.lua_check'))) {
            $this->markTestSkipped('lua-check is built with the engine (make -C game-engine/src)');
        }
        $script = "low = 40\nfunction launched()\n  if crowed(phobos) < low then wiat(60) end\nend\n";
        $this->assertEquals([['line' => 3, 'name' => 'crowed'], ['line' => 3, 'name' => 'wiat']],
            Release::luaUndefinedNames($script));
        $this->assertStringContainsString('crowed (line 3), wiat (line 3)', Release::redefinedWarning($script));
        foreach (array_merge(glob(base_path('examples/starters/*.lua')), glob(base_path('examples/companies/*.lua'))) as $file) {
            $this->assertEquals([], Release::luaUndefinedNames(file_get_contents($file)), basename($file));
        }
    }

    public function testALuaScriptThatDoesntCompileIsRefused()
    {
        if (!is_executable(config('forge.lua_check'))) {
            $this->markTestSkipped('lua-check is built with the engine (make -C game-engine/src)');
        }
        $this->assertNull(Release::scriptProblem(file_get_contents(base_path('examples/starters/maker.lua'))));
        $this->assertEquals("Line 2: unexpected symbol near '='",
            Release::scriptProblem("function launched()\n  x = = 1\nend\n"));
        $this->postJson('/api/v1/check', ['script' => "function launched()\n  x = = 1\nend\n"])
            ->assertOk()->assertJson(['ok' => false, 'language' => 'lua']);
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
}
