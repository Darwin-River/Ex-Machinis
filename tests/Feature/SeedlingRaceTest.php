<?php

namespace Tests\Feature;

use App\Services\Players;
use App\Services\SeedlingRace;
use App\Services\Seedlings;
use App\Services\World;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** The seedling race (#776) */
class SeedlingRaceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['forge.new_rules' => true, 'forge.seedling_max' => 5]);
        Mail::fake();
        Cache::flush();
    }

    public function testRecentSeedlingsRaceTheLineAndTheStarters()
    {
        $email = 'racer@example.com';
        $made = Players::create('Racer', 'long-enough', $email);
        Players::verify($made->user_id, sha1($email));
        $p = User::where('user_id', $made->user_id)->first();
        $id = Seedlings::start($p, 'Racer Co', 'function launched() wait(60) end')['company'];
        $now = World::gameNow();
        $epoch = (int) DB::table('game_clock')->where('id', 1)->value('epoch');
        $born = $now - 3 * 86400 - 3600;
        DB::table('companies')->where('company_id', $id)->update(['founded_t' => $born, 'credits' => 3600]);
        $day0 = intdiv($born - $epoch, 86400);
        foreach ([0 => 2900, 1 => 3100, 2 => 3400] as $age => $credits) {
            DB::table('company_days')->insert(['company_id' => $id, 'day' => $day0 + $age, 'credits' => $credits, 'net_wealth' => 0]);
        }

        $race = SeedlingRace::build();
        $this->assertSame(Seedlings::grant() + Seedlings::matureMargin(), $race['line']);
        $this->assertSame(['Miner', 'Refiner', 'Maker'], array_column($race['starters'], 'name'));
        // Each usual path runs from what is left after its kit (#833) to the line, at the middle of its card's range
        $miner = $race['starters'][0];
        $this->assertSame(50, $miner['path'][1]['age']);
        $this->assertSame((int) config('forge.starting_credits') - SeedlingRace::kitPrice('Mining Kit'), $miner['path'][0]['credits']);
        $this->assertSame($miner['after_kit'], $miner['path'][0]['credits']);
        $this->assertLessThan((int) config('forge.starting_credits'), $miner['after_kit']);
        $mine = collect($race['seedlings'])->firstWhere('company_id', $id);
        $this->assertSame('racing', $mine['state']);
        $this->assertSame([[0, 2900], [1, 3100], [2, 3400], [3, 3600]],
            array_map(fn ($q) => [$q['age'], $q['credits']], $mine['points']));

        // A matured seedling ends on the line, not at its credits after repaying (#833)
        $done = Seedlings::start($p, 'Racer Two', 'function launched() wait(60) end')['company'];
        $maturedT = $epoch + ($day0 + 2) * 86400 + 3600;   // on its third day's row, which is after repaying
        DB::table('companies')->where('company_id', $done)->update(['founded_t' => $born, 'seedling' => 0, 'matured_t' => $maturedT]);
        foreach ([0 => 3000, 1 => 4000, 2 => 4800] as $age => $credits) {
            DB::table('company_days')->insert(['company_id' => $done, 'day' => $day0 + $age, 'credits' => $credits, 'net_wealth' => 0]);
        }
        \Illuminate\Support\Facades\Cache::forget('seedling-race');
        $matured = collect(SeedlingRace::build()['seedlings'])->firstWhere('company_id', $done);
        $this->assertSame('matured', $matured['state']);
        $this->assertSame([[0, 3000], [1, 4000], [round(($maturedT - $born) / 86400, 1), $race['line']]],
            array_map(fn ($q) => [$q['age'], $q['credits']], $matured['points']));

        $this->actingAs($p)->get('/seedlings/race')->assertOk()->assertSee('id="race"', false)->assertSee('Racer Co')
            ->assertSee('class="table-active"', false);
        $this->getJson('/api/v1/seedlings/race')->assertOk()->assertJsonPath('line', $race['line']);
    }
}
