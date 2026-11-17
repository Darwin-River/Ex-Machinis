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
        $this->assertSame(60, $race['starters'][0]['path'][1]['age']);
        $mine = collect($race['seedlings'])->firstWhere('company_id', $id);
        $this->assertSame('racing', $mine['state']);
        $this->assertSame([[0, 2900], [1, 3100], [2, 3400], [3, 3600]],
            array_map(fn ($q) => [$q['age'], $q['credits']], $mine['points']));

        $this->actingAs($p)->get('/seedlings/race')->assertOk()->assertSee('id="race"', false)->assertSee('Racer Co')
            ->assertSee('class="table-active"', false);
        $this->getJson('/api/v1/seedlings/race')->assertOk()->assertJsonPath('line', $race['line']);
    }
}
