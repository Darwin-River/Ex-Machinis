<?php

namespace Tests\Feature;

use App\Services\PlayerBoards;
use App\Services\Players;
use App\Services\Seedlings;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** A seedling at its credit cap (#749): ready to mature, said on the account page, the digest, its Road and the API */
class CreditCapNoticeTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['forge.new_rules' => true, 'forge.seedling_max' => 5]);
        Mail::fake();
        Cache::flush();
    }

    public function testASeedlingAtItsCapIsReadyToMature()
    {
        DB::table('house_rules')->updateOrInsert(['id' => 1], ['tax_period' => 0, 'tax_grace' => 0, 'tax_flat' => 0,
            'tax_permille' => 0, 'plunder_share' => 50, 'compute_tax_per_million' => 15, 'seedling_credit_cap_permille' => 2000]);
        $email = 'capper@example.com';
        $made = Players::create('Capper', 'long-enough', $email);
        Players::verify($made->user_id, sha1($email));
        $p = User::where('user_id', $made->user_id)->first();
        $id = Seedlings::start($p, 'Capper Co', 'function launched() wait(60) end')['company'];
        $c = DB::table('companies')->where('company_id', $id)->first();
        $cap = Seedlings::creditCap($c);
        $this->assertNotNull($cap);

        // Below its cap: nothing said
        $this->assertFalse(collect(PlayerBoards::digest($p, false)['companies'])->firstWhere('company_id', $id)['at_cap']);
        $this->getJson("/api/v1/companies/$id")->assertJsonPath('credit_cap', $cap)->assertJsonPath('at_cap', false);

        DB::table('companies')->where('company_id', $id)->update(['credits' => $cap]);
        $row = collect(PlayerBoards::digest($p, false)['companies'])->firstWhere('company_id', $id);
        $this->assertTrue($row['at_cap']);
        $this->assertSame($cap, $row['credit_cap']);
        $this->getJson("/api/v1/companies/$id")->assertJsonPath('at_cap', true);
        $this->actingAs($p)->get('/account')->assertOk()->assertSee('Capper Co is ready to mature:')
            ->assertSee('what it earns above ' . number_format($cap) . ' now goes to the bank');
        $this->actingAs($p)->get("/companies/$id")->assertOk()->assertSee('id="at-cap"', false);
    }
}
