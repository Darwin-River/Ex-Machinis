<?php

namespace Tests\Feature;

use App\Services\CodePush;
use App\Services\Release;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Code pushes (#553): a company released with a password can push a new
 * script to its working drones, for a share of its credits that grows with
 * each push. The password is kept only as a hash.
 */
class CodePushTest extends TestCase
{
    use DatabaseTransactions;

    private const SCRIPT = "function launched()\n  print(\"v1\")\nend";
    private const NEW = "function launched()\n  print(\"v2\")\nend";

    private function release(string $name, ?string $password): int
    {
        $this->postJson('/api/v1/release', array_filter(['company_name' => $name, 'script' => self::SCRIPT,
            'password' => $password]))->assertCreated();
        return (int) DB::table('companies')->where('name', $name)->value('company_id');
    }

    public function testThePasswordIsKeptOnlyAsAHash()
    {
        $id = $this->release('Hashed Co', 'secret-pass');
        $stored = DB::table('companies')->where('company_id', $id)->value('push_password');
        $this->assertNotNull($stored);
        $this->assertStringNotContainsString('secret-pass', $stored);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('secret-pass', $stored));
        $this->assertNull(DB::table('companies')->where('company_id', $this->release('Open Co', null))->value('push_password'));
        $this->postJson('/api/v1/release', ['company_name' => 'Short Co', 'script' => self::SCRIPT, 'password' => 'abc'])
            ->assertStatus(422);
    }

    public function testAPushGoesToEveryWorkingDroneAndTheFeeGrows()
    {
        $id = $this->release('Pushed Co', 'secret-pass');
        $drone = (int) DB::table('agents')->where('company_id', $id)->value('agent_id');
        $user = (int) DB::table('agents')->where('agent_id', $drone)->value('user_id');
        $copy = DB::table('agents')->insertGetId(['user_id' => $user, 'company_id' => $id, 'name' => 'Pushed Copy',
            'object_id' => 3, 'hull_type' => 1, 'language' => 'lua']);
        $wreck = DB::table('agents')->insertGetId(['user_id' => $user, 'company_id' => $id, 'name' => 'Pushed Wreck',
            'object_id' => 3, 'hull_type' => 1, 'language' => 'lua', 'wrecked_at' => 1, 'wreck_cause' => 'crashes']);
        DB::table('companies')->where('company_id', $id)->update(['credits' => 10000]);

        $this->postJson("/api/v1/companies/$id/push", ['password' => 'wrong-pass', 'script' => self::NEW])->assertStatus(403);
        $this->postJson("/api/v1/companies/$id/push", ['password' => 'secret-pass', 'script' => 'print(1)'])->assertStatus(422);
        $this->assertSame(10000, (int) DB::table('companies')->where('company_id', $id)->value('credits'), 'refusals are free');

        $this->postJson("/api/v1/companies/$id/push", ['password' => 'secret-pass', 'script' => self::NEW])->assertStatus(202)
            ->assertJsonPath('fee', 500)->assertJsonPath('fee_percent', 5)->assertJsonPath('next_fee_percent', 10)
            ->assertJsonPath('drones', [$drone, $copy]);
        $this->assertSame(9500, (int) DB::table('companies')->where('company_id', $id)->value('credits'));
        foreach ([$drone, $copy] as $d) {
            $codes = DB::table('commands')->where('agent_id', $d)->where('subject', 'Push')->orderBy('id')->pluck('code')->all();
            $this->assertSame(['<reset>', self::NEW, '<launched>'], $codes);
        }
        $this->assertFalse(DB::table('commands')->where('agent_id', $wreck)->exists(), 'a wreck gets nothing');

        // The second push costs 10% of what it has now
        $this->postJson("/api/v1/companies/$id/push", ['password' => 'secret-pass', 'script' => self::NEW])
            ->assertJsonPath('fee', 950)->assertJsonPath('fee_percent', 10);
        $this->getJson("/api/v1/companies/$id")->assertJsonPath('pushes.count', 2)
            ->assertJsonPath('pushes.next_fee_percent', 15)->assertJsonPath('pushes.log.0.fee', 950);
        $this->get("/companies/$id")->assertOk()->assertSee('Code pushes')->assertSee('Pushed 2 times');
    }

    public function testNoCreditsNoFeeAndTheCapHolds()
    {
        $id = $this->release('Broke Co', 'secret-pass');
        DB::table('companies')->where('company_id', $id)->update(['credits' => -50]);
        $r = CodePush::push($id, 'secret-pass', self::NEW);
        $this->assertTrue($r['ok']);
        $this->assertSame(0, $r['fee']);
        $this->assertSame(-50, (int) DB::table('companies')->where('company_id', $id)->value('credits'));
        $this->assertSame(500, CodePush::permille(20), 'never more than half');
    }

    public function testACompanyWithoutAPasswordOrDronesCantBePushedTo()
    {
        $open = $this->release('Unlocked Co', null);
        $this->postJson("/api/v1/companies/$open/push", ['password' => 'anything', 'script' => self::NEW])->assertStatus(403);
        $ended = $this->release('Ended Co', 'secret-pass');
        DB::table('companies')->where('company_id', $ended)->update(['eliminated_at' => 1]);
        $this->postJson("/api/v1/companies/$ended/push", ['password' => 'secret-pass', 'script' => self::NEW])->assertStatus(409);
    }

    public function testTheWebsiteFormPushes()
    {
        $id = $this->release('Form Co', 'secret-pass');
        $this->post("/companies/$id/push", ['password' => 'secret-pass', 'script' => self::NEW])
            ->assertRedirect("/companies/$id#pushes");
        $this->assertSame(1, (int) DB::table('companies')->where('company_id', $id)->value('pushes'));
        $this->post("/companies/$id/push", ['password' => 'bad-pass', 'script' => self::NEW])->assertSessionHasErrors('push');
    }
}
