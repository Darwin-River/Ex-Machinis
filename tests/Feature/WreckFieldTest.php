<?php

namespace Tests\Feature;

use App\Services\Players;
use App\Services\Startups;
use App\Services\World;
use App\Services\WreckField;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** #929: the wreck field on the Map: what is left to salvage, how far it has decayed, and who took from it */
class WreckFieldTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['forge.new_rules' => true, 'forge.startup_max' => 5]);
        Mail::fake();
    }

    private function drone(string $player, string $company): int
    {
        $email = strtolower(str_replace(' ', '.', $player)) . '@example.com';
        $user = \App\User::where('email', $email)->first();
        if (!$user) {
            $u = Players::create($player, 'long-enough', $email);
            Players::verify($u->user_id, sha1($email));
            $user = \App\User::where('user_id', $u->user_id)->first();
        }
        $id = Startups::start($user, $company, 'function launched() wait(60) end')['company'];
        return (int) DB::table('agents')->where('company_id', $id)->value('agent_id');
    }

    public function testAWreckDecaysTwoPercentOfWhatIsLeftADay()
    {
        $this->assertSame(1000, WreckField::condition(0));
        $this->assertSame(980, WreckField::condition(86400));
        $this->assertSame(960, WreckField::condition(2 * 86400 + 5));
        $this->assertGreaterThan(600, WreckField::condition(20 * 86400));
        $this->assertLessThan(700, WreckField::condition(20 * 86400));
    }

    public function testTheMapListsWrecksAndWhoTookFromThem()
    {
        $wreck = $this->drone('Wreck Owner', 'Sunk Co');
        $mate = $this->drone('Wreck Owner', 'Sister Salvage Co');
        $stranger = $this->drone('Wreck Stranger', 'Other Co');
        DB::table('companies')->whereIn('company_id', DB::table('agents')->whereIn('agent_id', [$wreck, $mate])->select('company_id'))
            ->update(['startup' => 0, 'matured_t' => World::gameNow()]);
        DB::table('agents')->where('agent_id', $wreck)->update(['wrecked_at' => World::gameNow() - 10 * 86400,
            'wreck_cause' => 'fuel', 'plundered_by' => $stranger, 'salvaged_by' => $mate, 'scrap_left' => 30]);
        DB::table('holdings')->updateOrInsert(['drone' => $wreck, 'resource' => 5001, 'installed' => 0, 'locked' => 0],
            ['units' => 2, 'event_id' => 0]);

        $row = collect(WreckField::list())->firstWhere('drone', $wreck);
        $this->assertNotNull($row);
        $this->assertSame(81.7, $row['condition'], 'ten game days: 0.98^10');
        $this->assertSame(2, $row['modules']);
        $this->assertSame(30, $row['scrap']);
        $this->assertSame([$stranger, $mate], [$row['plundered_by'], $row['salvaged_by']]);
        $this->assertTrue($row['same_player'], 'its own player\'s other company salvaged it');

        // Nothing left: off the list; a wound-up drone left the game with what it held
        DB::table('agents')->where('agent_id', $wreck)->update(['scrap_left' => 0]);
        $this->assertNull(collect(WreckField::list())->firstWhere('drone', $wreck));
        DB::table('agents')->where('agent_id', $wreck)->update(['scrap_left' => 5, 'wreck_cause' => 'wound_up']);
        $this->assertNull(collect(WreckField::list())->firstWhere('drone', $wreck));

        DB::table('agents')->where('agent_id', $wreck)->update(['wreck_cause' => 'fuel']);
        $this->get('/map')->assertOk()->assertSee('id="wreck-field"', false)->assertSee('same player');
    }
}
