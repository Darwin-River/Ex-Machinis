<?php

namespace Tests\Feature;

use App\Agent;
use App\Command;
use App\Company;
use App\Invite;
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
        config(['forge.email' => false, 'forge.invite_only' => true]);
    }

    private function player(): User
    {
        return User::create(['name' => 'Forge Player', 'email' => 'forge@example.com', 'password' => bcrypt('secret-password')]);
    }

    public function testAccountNeedsAnUnusedInvitation()
    {
        $form = ['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'secret-password',
            'password_confirmation' => 'secret-password'];

        $this->from('/register')->post('/register', $form + ['invite_code' => 'NOPE'])
            ->assertRedirect('/register')->assertSessionHasErrors('invite_code');
        $this->assertNull(User::where('email', 'ada@example.com')->first());

        $invite = Invite::generate('test');
        $this->post('/register', $form + ['invite_code' => strtolower($invite->code)])->assertRedirect('/release');

        $user = User::where('email', 'ada@example.com')->first();
        $this->assertNotNull($user);
        $this->assertEquals($user->user_id, $invite->fresh()->used_by);
        $this->assertCount(0, Company::where('user_id', $user->user_id)->get(), 'an account starts with no company');
        Mail::assertNothingSent();

        // The code can't be used twice
        $this->post('/logout');
        $this->from('/register')->post('/register', ['email' => 'bob@example.com', 'name' => 'Bob'] + $form + ['invite_code' => $invite->code])
            ->assertSessionHasErrors('invite_code');
    }

    public function testSignUpPageShowsTheAccountForm()
    {
        $this->get('/register')->assertOk()->assertSee('Invitation code')->assertSee('Password');
    }

    public function testReleasePageNeedsLogin()
    {
        $this->get('/release')->assertRedirect('/login');
    }

    public function testReleasingAScriptStartsACompanyWithADroneThatRunsIt()
    {
        $user = $this->player();
        $script = ": greet  .\" hello\" cr ;\n: launched  greet ;";

        $this->actingAs($user)->post('/release', ['company_name' => 'Forge Works', 'script' => $script])
            ->assertRedirect('/release');

        $company = Company::where('name', 'Forge Works')->first();
        $this->assertNotNull($company);
        $this->assertEquals($user->user_id, $company->user_id);
        $this->assertEquals(config('forge.starting_credits'), $company->credits);

        $drones = Agent::where('company_id', $company->company_id)->get();
        $this->assertCount(1, $drones);
        $this->assertEquals(Company::EARTH_OBJECT_ID, $drones[0]->object_id);

        // The script, then <launched>, in order
        $codes = Command::where('agent_id', $drones[0]->agent_id)->orderBy('id')->pluck('code')->all();
        $this->assertEquals([$script, '<launched>'], $codes);
        Mail::assertNothingSent();
    }

    public function testScriptMustDefineLaunched()
    {
        $this->actingAs($this->player())->from('/release')
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

    public function testReleasePageShowsRepliesThatHaveReachedEarth()
    {
        $user = $this->player();
        $agent = Release::release($user, 'Reply Co', ": launched ;");
        $now = \App\Http\Controllers\ReleaseController::gameNow();
        DB::table('drone_messages')->insert([
            ['agent_id' => $agent->agent_id, 'game_t' => $now - 5, 'subject' => 'Release', 'body' => 'arrived-reply'],
            ['agent_id' => $agent->agent_id, 'game_t' => $now + 100000, 'subject' => 'Release', 'body' => 'still-in-transit'],
        ]);

        $this->actingAs($user)->get('/release')->assertOk()
            ->assertSee($agent->name)->assertSee('arrived-reply')->assertDontSee('still-in-transit');
    }

    public function testMailIsNotReadWhenEmailIsOff()
    {
        config(['app.cron_key' => 'k']);
        $this->get('/get-mails/k')->assertOk()->assertSee('Email is switched off');
    }
}
