<?php

namespace Tests\Feature;

use App\Services\Players;
use App\Services\StartupRace;
use App\Services\Startups;
use App\Services\World;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** The startup race (#776) */
class StartupRaceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['forge.new_rules' => true, 'forge.startup_max' => 5]);
        Mail::fake();
        Cache::flush();
    }

    public function testRecentStartupsRaceTheLineAndTheStarters()
    {
        $email = 'racer@example.com';
        $made = Players::create('Racer', 'long-enough', $email);
        Players::verify($made->user_id, sha1($email));
        $p = User::where('user_id', $made->user_id)->first();
        $id = Startups::start($p, 'Racer Co', 'function launched() wait(60) end')['company'];
        $now = World::gameNow();
        $epoch = (int) DB::table('game_clock')->where('id', 1)->value('epoch');
        $born = $now - 3 * 86400 - 3600;
        DB::table('companies')->where('company_id', $id)->update(['founded_t' => $born, 'credits' => 3600]);
        $day0 = intdiv($born - $epoch, 86400);
        foreach ([0 => 2900, 1 => 3100, 2 => 3400] as $age => $credits) {
            DB::table('company_days')->insert(['company_id' => $id, 'day' => $day0 + $age, 'credits' => $credits, 'net_wealth' => 0]);
        }
        // Its kit, bought in its first game hour: the race starts there (#863)
        $other = (int) DB::table('agents')->where('company_id', '<>', $id)->value('agent_id');
        $kit = fn (int $company, int $t) => DB::table('trades')->insert(['resource' => \App\Services\Release::KITS['mining'], 'units' => 1,
            'credits' => 2000, 'tax' => 0, 'seller' => $other, 'buyer' => DB::table('agents')->where('company_id', $company)->value('agent_id'),
            'location' => 3, 'game_t' => $t]);
        $kit($id, $born + 600);
        // One that never bought a kit isn't racing (#863: Mei 77-4 sat at 2,893, far ahead of everyone)
        $kitless = Startups::start($p, 'Racer Kitless', 'function launched() wait(60) end')['company'];
        DB::table('companies')->where('company_id', $kitless)->update(['founded_t' => $born]);

        $race = StartupRace::build();
        $this->assertSame(Startups::grant() + Startups::matureMargin(), $race['line']);
        $this->assertSame(['Miner', 'Refiner', 'Maker'], array_column($race['starters'], 'name'));
        // Each usual path runs from what is left after its kit (#833) to the line, at the middle of its card's range
        $miner = $race['starters'][0];
        $card = \App\Services\Release::STARTER_CARDS['miner']['days'];
        $this->assertSame(intdiv($card[0] + $card[1], 2), $miner['path'][1]['age']);
        $this->assertSame(62, $miner['path'][1]['age']);
        $this->assertSame((int) config('forge.starting_credits') - StartupRace::kitPrice('Mining Kit'), $miner['path'][0]['credits']);
        $this->assertSame($miner['after_kit'], $miner['path'][0]['credits']);
        $this->assertLessThan((int) config('forge.starting_credits'), $miner['after_kit']);
        // Each starter's band, from the fast end of its card to the slow end (#865)
        $this->assertSame([$card[0], $card[1]], [$miner['fast'][1]['age'], $miner['slow'][1]['age']]);
        $this->assertNull(collect($race['startups'])->firstWhere('company_id', $kitless));
        $mine = collect($race['startups'])->firstWhere('company_id', $id);
        $this->assertSame('racing', $mine['state']);
        $this->assertSame('custom', $mine['starter'], 'its code is no starter');
        $this->assertSame([[0, 2900], [1, 3100], [2, 3400], [3, 3600]],
            array_map(fn ($q) => [$q['age'], $q['credits']], $mine['points']));

        // A matured startup ends on the line, not at its credits after repaying (#833)
        $done = Startups::start($p, 'Racer Two', 'function launched() wait(60) end')['company'];
        $maturedT = $epoch + ($day0 + 2) * 86400 + 3600;   // on its third day's row, which is after repaying
        DB::table('companies')->where('company_id', $done)->update(['founded_t' => $born, 'startup' => 0, 'matured_t' => $maturedT]);
        $kit($done, $born + 600);
        foreach ([0 => 3000, 1 => 4000, 2 => 4800] as $age => $credits) {
            DB::table('company_days')->insert(['company_id' => $done, 'day' => $day0 + $age, 'credits' => $credits, 'net_wealth' => 0]);
        }
        \Illuminate\Support\Facades\Cache::forget('startup-race');
        $matured = collect(StartupRace::build()['startups'])->firstWhere('company_id', $done);
        $this->assertSame('matured', $matured['state']);
        $this->assertSame([[0, 3000], [1, 4000], [round(($maturedT - $born) / 86400, 1), $race['line']]],
            array_map(fn ($q) => [$q['age'], $q['credits']], $matured['points']));

        $this->actingAs($p)->get('/startups/race')->assertOk()->assertSee('id="race"', false)->assertSee('Racer Co')
            ->assertSee('class="table-active"', false);
        $this->getJson('/api/v1/startups/race')->assertOk()->assertJsonPath('line', $race['line']);
    }
}
