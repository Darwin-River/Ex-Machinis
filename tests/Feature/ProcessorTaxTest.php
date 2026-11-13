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

        DB::table('compute_clock')->where('id', 1)->update(['rate_now' => null, 'congestion' => null]);
        $this->assertSame(['instructions_day' => 3000000, 'tax_day' => 45, 'debt' => 10, 'throttled' => true, 'per_million' => 15,
            'base_per_million' => 15, 'congestion' => 1.0, 'broke_days' => null, 'dissolves_in' => null], ProcessorTax::of($company));
        // What it owes counts against its net wealth
        $this->assertSame($before - 10, Leaderboard::wealth()[$company]['wealth']);
        $this->getJson('/api/v1/companies/' . $company)->assertOk()->assertJsonPath('compute.tax_day', 45)
            ->assertJsonPath('compute.throttled', true);
        $this->get('/companies/' . $company)->assertOk()->assertSee('Processor tax: 45 credits')
            ->assertSee('It owes 10');
    }

    public function testABrokeCompanyIsWarnedBeforeItDissolves()
    {
        // #547: broke for 50 game days straight, a company dissolves
        $user = User::create(['name' => 'Broke', 'email' => uniqid() . '@example.com', 'password' => bcrypt('secret-password')]);
        $agent = Release::release($user, 'Down To Zero', 'function launched() end');
        $company = (int) $agent->company_id;
        DB::table('house_rules')->updateOrInsert(['id' => 1], ['tax_period' => 0, 'tax_grace' => 0, 'tax_flat' => 0,
            'tax_permille' => 0, 'plunder_share' => 50, 'dissolve_days' => 50]);
        DB::table('companies')->where('company_id', $company)->update(['credits' => 0,
            'broke_since' => World::gameNow() - 20 * 86400]);
        $this->assertSame(['broke_days' => 20.0, 'dissolves_in' => 30.0], ProcessorTax::broke($company));
        $h = \App\Services\CompanyHealth::of($company);
        $this->assertTrue($h['at_risk']);
        $this->assertStringContainsString('dissolves in 30', $h['at_risk_reason']);
        $this->get('/companies/' . $company)->assertOk()->assertSee('Broke')->assertSee('it dissolves in 30 game days');

        // Dissolved: its drones are wrecks, and their post-mortem says why
        DB::table('companies')->where('company_id', $company)->update(['eliminated_at' => World::gameNow(),
            'end_cause' => 'dissolved', 'broke_since' => null]);
        DB::table('agents')->where('agent_id', $agent->agent_id)->update(['wrecked_at' => World::gameNow(),
            'wreck_cause' => 'dissolved']);
        $this->get('/spacecraft/' . $agent->agent_id)->assertOk()
            ->assertSee('had no credits for 50 game days straight, so it dissolved');
    }

    /**
     * #620: while the clock runs slowed, the processor tax rises by how many
     * times slower, up to the cap; the rate billed now shows on the company
     * page, in the API's compute and clock, and to drones (query 651)
     */
    public function testTheCongestionTaxShows()
    {
        $user = User::create(['name' => 'Fleet', 'email' => uniqid() . '@example.com', 'password' => bcrypt('secret-password')]);
        $company = (int) Release::release($user, 'Big Fleet', 'function launched() end')->company_id;
        DB::table('house_rules')->updateOrInsert(['id' => 1], ['tax_period' => 0, 'tax_grace' => 0, 'tax_flat' => 0,
            'tax_permille' => 0, 'plunder_share' => 50, 'compute_tax_per_million' => 15, 'congestion_cap' => 10,
            'congestion_full_at' => 5000000]);
        DB::table('compute_clock')->updateOrInsert(['id' => 1], ['billed_t' => 0, 'rate_now' => 150, 'congestion' => 10]);

        $c = ProcessorTax::congestion();
        $this->assertSame(['rate' => 150, 'base' => 15, 'congestion' => 10.0, 'cap' => 10.0, 'full_at' => 5000000], $c);
        $this->assertSame(150, ProcessorTax::rate(), 'the rate a busy company pays now');
        // A company that thought little pays little of it: 0.3 million a day of 5 million is 6% of the rise
        $this->assertEqualsWithDelta(1.54, ProcessorTax::companyCongestion(10, 300000, 5000000), 1e-9);
        $this->assertSame(10.0, (float) ProcessorTax::companyCongestion(10, 27000000, 5000000));
        $this->assertSame(1.0, ProcessorTax::companyCongestion(1, 27000000, 5000000));
        DB::table('compute_bills')->insert(['company_id' => $company, 'charged_t' => World::gameNow() - 3600,
            'instructions' => 300000, 'amount' => 5, 'paid' => 5, 'debt_after' => 0]);
        $of = ProcessorTax::of($company);
        $this->assertSame([23, 15, 1.54], [$of['per_million'], $of['base_per_million'], $of['congestion']], 'a lone drone: about 23');
        // A busy fleet pays all of it
        DB::table('compute_bills')->where('company_id', $company)->update(['instructions' => 27000000]);
        $of = ProcessorTax::of($company);
        $this->assertSame([150, 15, 10.0], [$of['per_million'], $of['base_per_million'], $of['congestion']]);

        $this->get('/companies/' . $company)->assertOk()
            ->assertSee('150 credits a million now: 10 times the usual 15 while the clock runs slowed');
        $this->getJson('/api/v1/companies/' . $company)->assertOk()
            ->assertJsonPath('compute.per_million', 150)->assertJsonPath('compute.congestion', 10);
        // (the clock shows the rate for a busy company)
        $this->getJson('/api/v1/clock')->assertOk()
            ->assertJsonPath('processor_tax.per_million', 150)->assertJsonPath('processor_tax.base_per_million', 15);

        // Drones read it too: query 651's rate is the one billed now
        $sql = DB::table('queries')->where('id', 651)->value('script');
        if ($sql !== null) {
            $this->assertStringContainsString('compute_clock', $sql);
        }

        // Back at the target pace: the usual rate, and no note
        DB::table('compute_clock')->where('id', 1)->update(['rate_now' => 15, 'congestion' => 1]);
        $this->assertSame(15, ProcessorTax::rate());
        $this->get('/companies/' . $company)->assertOk()->assertSee('(15 credits a million)', false)
            ->assertDontSee('while the clock runs slowed');
        // Before the first bill since #620: the base rate
        DB::table('compute_clock')->where('id', 1)->update(['rate_now' => null, 'congestion' => null]);
        $this->assertSame(15, ProcessorTax::rate());
    }
}
