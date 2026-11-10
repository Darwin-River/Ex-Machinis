<?php

namespace Tests\Feature;

use App\Services\Leaderboard;
use App\Services\ProcessorTax;
use App\Services\Release;
use App\Services\World;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The processor tax (#544): a company pays credits for its drones' thinking
 * instead of fuel; what it can't pay it owes, and its drones run throttled.
 */
class ProcessorTaxTest extends TestCase
{
    use DatabaseTransactions;

    public function testACompanySeesItsProcessorTaxAndDebt()
    {
        $user = User::create(['name' => 'Thinker', 'email' => uniqid() . '@example.com', 'password' => bcrypt('secret-password')]);
        $agent = Release::release($user, 'Deep Thought', 'function launched() end');
        $company = (int) $agent->company_id;
        DB::table('house_rules')->updateOrInsert(['id' => 1], ['tax_period' => 0, 'tax_grace' => 0, 'tax_flat' => 0,
            'tax_permille' => 0, 'plunder_share' => 50, 'compute_tax_per_million' => 15]);
        DB::table('compute_bills')->insert([
            ['company_id' => $company, 'charged_t' => World::gameNow() - 7200, 'instructions' => 2000000, 'amount' => 30,
             'paid' => 30, 'debt_after' => 0],
            ['company_id' => $company, 'charged_t' => World::gameNow() - 3600, 'instructions' => 1000000, 'amount' => 15,
             'paid' => 5, 'debt_after' => 10],
            // over a game day ago: not counted
            ['company_id' => $company, 'charged_t' => World::gameNow() - 2 * 86400, 'instructions' => 9000000, 'amount' => 135,
             'paid' => 135, 'debt_after' => 0],
        ]);
        $before = Leaderboard::wealth()[$company]['wealth'];
        DB::table('companies')->where('company_id', $company)->update(['compute_debt' => 10]);

        $this->assertSame(['instructions_day' => 3000000, 'tax_day' => 45, 'debt' => 10, 'throttled' => true, 'per_million' => 15],
            ProcessorTax::of($company));
        // What it owes counts against its net wealth
        $this->assertSame($before - 10, Leaderboard::wealth()[$company]['wealth']);
        $this->getJson('/api/v1/companies/' . $company)->assertOk()->assertJsonPath('compute.tax_day', 45)
            ->assertJsonPath('compute.throttled', true);
        $this->get('/companies/' . $company)->assertOk()->assertSee('Processor tax: 45 credits')
            ->assertSee('It owes 10');
    }
}
