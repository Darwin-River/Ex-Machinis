<?php

namespace Tests\Feature;

use App\Services\DroneTestDrive;
use App\Services\Players;
use App\Services\Startups;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * A test drive for drone scripts (#1007): a startup's draft runs read-only
 * in lua-drive against a snapshot of the world now, and says what its first
 * drone would do, writing nothing: no events, actions, commands or holdings.
 */
class DroneTestDriveTest extends TestCase
{
    use DatabaseTransactions;

    /** The tables the engine reads or writes for actions: a test drive leaves them as they were */
    private const UNTOUCHED = ['events', 'actions', 'commands', 'holdings', 'latest_orders', 'code_pushes', 'agents',
        'mining_runs', 'loan_requests', 'contracts', 'company_payouts'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['forge.new_rules' => true, 'forge.startup_hull_value' => 2000, 'forge.startup_fee' => 500,
            'forge.starting_credits' => 3000, 'forge.startup_max' => 5, 'forge.starting_fuel' => 150]);
        Mail::fake();
        Cache::flush();
        if (!is_executable((string) config('forge.lua_drive'))) {
            $this->markTestSkipped('lua-drive is not built (make -C game-engine/src bin/lua-drive)');
        }
        $this->loadGameData();
    }

    /**
     * The game data (protocols, their effects, resources, queries, deposits)
     * as the live world has it, inside this test's transaction: all but its
     * schema changes, which a test database already has
     */
    private function loadGameData(): void
    {
        $sql = file_get_contents(base_path('game-engine/sql/game_data.sql'));
        $sql = substr($sql, strpos($sql, 'START TRANSACTION;') + strlen('START TRANSACTION;'));
        $sql = preg_replace('/^COMMIT;\s*$/m', '', $sql);
        DB::unprepared($sql);
        // Where Earth and the Moon are, and the clock, for a flight's time (query 705)
        DB::table('objects')->where('object_id', 3)->update(['x_coord' => 0, 'y_coord' => 0, 'z_coord' => 0]);
        DB::table('objects')->where('object_id', 10)->update(['x_coord' => 384400, 'y_coord' => 0, 'z_coord' => 0]);
        $now = time();
        DB::table('game_clock')->updateOrInsert(['id' => 1], ['real_anchor' => $now, 'game_anchor' => $now, 'scale' => 1,
            'epoch' => $now - 100 * 86400, 'travel_acceleration' => 100]);
        DroneTestDrive::forget();
    }

    private function player(string $name): User
    {
        $email = strtolower(str_replace(' ', '.', $name)) . '@example.com';
        $user = Players::create($name, 'long-enough', $email);
        Players::verify($user->user_id, sha1($email));
        return User::where('user_id', $user->user_id)->first();
    }

    private function counts(): array
    {
        return array_combine(self::UNTOUCHED, array_map(fn ($t) => DB::table($t)->count(), self::UNTOUCHED));
    }

    /** A startup running the Miner starter, its drone with a Mining Kit aboard */
    private function minerStartup(User $owner): array
    {
        $company = (int) Startups::start($owner, 'Drive Miner Co', 'function launched() wait(60) end')['company'];
        $drone = (int) DB::table('agents')->where('company_id', $company)->value('agent_id');
        DB::table('holdings')->updateOrInsert(['drone' => $drone, 'resource' => 5901, 'installed' => 0, 'locked' => 0],
            ['units' => 1, 'event_id' => 0]);
        return [$company, $drone];
    }

    public function testTheMinerStarterSaysWhatItWouldDoAndNothingIsWritten()
    {
        $owner = $this->player('Drive Owner');
        [$company, $drone] = $this->minerStartup($owner);
        $credits = (int) DB::table('companies')->where('company_id', $company)->value('credits');
        $before = $this->counts();
        $miner = file_get_contents(base_path('examples/starters/miner.lua'));

        $drive = DroneTestDrive::run($company, $miner);

        $this->assertSame($drone, $drive['drone']);
        $this->assertFalse($drive['applied']);
        $this->assertNull($drive['error'], implode("\n", $drive['lines']));
        $this->assertContains($drive['stopped'], ['actions', 'day'], implode("\n", $drive['lines']));
        $texts = array_column($drive['actions'], 'text');
        $this->assertGreaterThan(5, count($texts));
        $this->assertStringStartsWith('would unpack', strtolower($texts[0]));
        // #1034: it names no place, so it goes only where the game says its ores
        // are found and how to get there. The test world lists no sites and no
        // trips: it flies nowhere blind, and says why it waits
        $this->assertEmpty(array_filter($texts, fn ($t) => str_contains($t, 'fly to')), implode("\n", $texts));
        $this->assertContains('dry here and nowhere better: resting', $drive['status'] ?? [], implode("\n", $drive['lines']));

        // Nothing written: the engine's tables, the company's credits and the drone's hold are as they were
        $this->assertSame($before, $this->counts());
        $this->assertSame($credits, (int) DB::table('companies')->where('company_id', $company)->value('credits'));
        $this->assertSame(1, (int) DB::table('holdings')->where('drone', $drone)->where('resource', 5901)->value('units'));
        $this->assertSame(0, (int) DB::table('holdings')->where('drone', $drone)->where('resource', 5001)->sum('units'));
        $this->assertSame(3, (int) DB::table('agents')->where('agent_id', $drone)->value('object_id'));

        // Run again: the same (math.random is seeded, the world unchanged)
        $this->assertSame($drive['lines'], DroneTestDrive::run($company, $miner)['lines']);
    }

    public function testAnErrorSaysItsLineAndWhatCameBefore()
    {
        $owner = $this->player('Drive Error');
        [$company] = $this->minerStartup($owner);
        $drive = DroneTestDrive::run($company, "function launched()\n  print('hello')\n  wait(30)\n  local x = nil\n  x.y = 1\nend\n");
        $this->assertSame('error', $drive['stopped']);
        $this->assertStringContainsString('script:5:', $drive['error']);
        $this->assertSame(['it would say: hello', '0:00 would wait 30 game minutes'], $drive['lines']);
    }

    public function testTheButtonOnTheStartupsPageAndOnlyItsOwner()
    {
        $owner = $this->player('Drive Button');
        [$company] = $this->minerStartup($owner);
        $script = "function launched()\n  set_status('testing')\n  equip(mining_kit)\n  go(moon)\nend\n";

        $this->actingAs($owner)->get('/companies/' . $company)->assertOk()->assertSee('Test drive (changes nothing)')
            ->assertSee('startups/' . $company . '/test-drive', false);
        $before = $this->counts();
        $this->actingAs($owner)->post('/startups/' . $company . '/test-drive', ['script' => $script])
            ->assertRedirect('/companies/' . $company . '#drone-test-drive');
        $this->actingAs($owner)->get('/companies/' . $company)->assertOk()->assertSee('id="drone-test-drive"', false)
            ->assertSee('its status would read: testing')->assertSee('would fly to the Moon');
        $this->assertSame($before, $this->counts());

        // Another player can't: the snapshot shows the drone's hold
        $other = $this->player('Drive Stranger');
        $this->actingAs($other)->post('/startups/' . $company . '/test-drive', ['script' => $script])
            ->assertRedirect('/account#startups')->assertSessionHas('error');
        $this->assertFalse(DroneTestDrive::mayDrive($other, $company));
        $this->assertTrue(DroneTestDrive::mayDrive($owner, $company));
    }

    public function testTheApiChecksAndDrivesForTheOwnerOnly()
    {
        $owner = $this->player('Drive Api');
        [$company, $drone] = $this->minerStartup($owner);
        $script = "function launched()\n  equip(mining_kit)\n  go(moon)\n  print(units(fuel))\nend\n";

        // Without a token, or another player's: refused
        $this->postJson('/api/v1/check', ['script' => $script, 'company' => $company, 'drive' => true])->assertStatus(403);
        $other = $this->player('Drive Api Stranger');
        $this->withHeader('Authorization', 'Bearer ' . Players::issueToken($other))
            ->postJson('/api/v1/check', ['script' => $script, 'company' => $company, 'drive' => true])->assertStatus(403);
        // A drive needs the company
        $token = Players::issueToken($owner);
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/check', ['script' => $script, 'drive' => true])->assertStatus(422);

        $before = $this->counts();
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/check', ['script' => $script, 'company' => $company, 'drone' => $drone, 'drive' => true])
            ->assertOk()->assertJsonPath('ok', true)->assertJsonPath('drive.drone', $drone)->assertJsonPath('drive.applied', false)
            ->assertJsonPath('drive.stopped', 'finished')->assertJsonPath('drive.said', ['145']);
        $this->assertSame($before, $this->counts());
        // A drone that isn't one of its working drones
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/check', ['script' => $script, 'company' => $company, 'drone' => $drone + 100000, 'drive' => true])
            ->assertStatus(404);
        // Without drive, the check is as it was: no drive in it
        $this->postJson('/api/v1/check', ['script' => $script])->assertOk()->assertJsonMissingPath('drive');
    }
}
