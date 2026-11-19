<?php

namespace Tests\Feature;

use App\Services\Players;
use App\Services\Startups;
use App\Services\World;
use App\Services\WorldEvents;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Investing shown as progress (#778): equipment bought marked on a company's charts, with its payback */
class InvestMarksTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['forge.new_rules' => true, 'forge.startup_max' => 5]);
        Mail::fake();
        Cache::flush();
    }

    public function testEquipmentBoughtIsMarkedWithItsPayback()
    {
        $email = 'investor-mark@example.com';
        $made = Players::create('Investor Mark', 'long-enough', $email);
        Players::verify($made->user_id, sha1($email));
        $p = User::where('user_id', $made->user_id)->first();
        $id = Startups::start($p, 'Invest Co', 'function launched() wait(60) end')['company'];
        $drone = (int) DB::table('agents')->where('company_id', $id)->value('agent_id');
        $other = (int) DB::table('agents')->where('agent_id', '<>', $drone)->value('agent_id');
        $now = World::gameNow();
        DB::table('companies')->where('company_id', $id)->update(['founded_t' => $now - 10 * 86400]);
        $trade = fn ($s, $b, $r, $c, $t) => DB::table('trades')->insert(['resource' => $r, 'units' => 1, 'credits' => $c, 'tax' => 0,
            'seller' => $s, 'buyer' => $b, 'location' => 3, 'game_t' => $t]);
        // It sells 700 a game day for a week, and bought a kit for 2,000 three days ago
        for ($d = 0; $d < 8; $d++) {
            $trade($drone, $other, 2011, 700, $now - $d * 86400 - 600);
        }
        $trade($other, $drone, 5001, 2000, $now - 3 * 86400);

        $marks = collect(WorldEvents::company($id))->where('kind', 'invest')->values();
        $this->assertCount(1, $marks);
        $this->assertSame('Invested 2,000', $marks[0]['label']);
        $this->assertStringContainsString('It invested 2,000 credits', $marks[0]['text']);
        $perDrone = WorldEvents::profitPerDrone($id);
        $this->assertGreaterThan(0, $perDrone);
        $this->assertStringContainsString('pays back in about ' . (int) ceil(2000 / $perDrone) . ' game days', $marks[0]['text']);
        $this->getJson("/api/v1/companies/$id/events")->assertOk()->assertJsonFragment(['label' => 'Invested 2,000']);
    }
}
