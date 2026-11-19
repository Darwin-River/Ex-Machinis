<?php

namespace Tests\Feature;

use App\Services\Players;
use App\Services\PostMortem;
use App\Services\Startups;
use App\Services\World;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** The failure card (#773): a post-mortem when a company ends, and a fixed copy a click away */
class PostMortemTest extends TestCase
{
    use DatabaseTransactions;

    private const SCRIPT = "function launched()\n  wait(60)\n  sell_here(\"Iron\", nil)\nend";

    protected function setUp(): void
    {
        parent::setUp();
        config(['forge.new_rules' => true, 'forge.startup_max' => 5]);
        Mail::fake();
        Cache::flush();
    }

    public function testAnEndedStartupShowsWhatWentWrongAndAFixedCopy()
    {
        $email = 'mortem@example.com';
        $made = Players::create('Mortem', 'long-enough', $email);
        Players::verify($made->user_id, sha1($email));
        $p = User::where('user_id', $made->user_id)->first();
        $id = Startups::start($p, 'Mortem Co', self::SCRIPT)['company'];
        $drone = (int) DB::table('agents')->where('company_id', $id)->value('agent_id');
        $this->assertNull(PostMortem::of($id), 'no card while it runs');

        $now = World::gameNow();
        // Its sells kept failing, it said so, and its script crashed on line 3
        foreach ([1, 2, 3] as $h) {
            $a = DB::table('actions')->insertGetId(['drone' => $drone, 'protocol' => 3, 'multiplier' => 1, 'aborted' => 0, 'outcome' => -2]);
            DB::table('events')->insert(['event_type' => 1, 'action' => $a, 'logged' => 1, 'outcome' => -2, 'drone' => $drone,
                'resource' => 2011, 'installed' => 0, 'locked' => 0, 'new_quantity' => 0, 'game_t' => $now - $h * 3600]);
        }
        DB::table('agents')->where('agent_id', $drone)->update(['script_status' => 'no buyer for iron', 'script_status_t' => $now - 600]);
        DB::table('drone_messages')->insert(['agent_id' => $drone, 'game_t' => $now - 300, 'subject' => 'Crash',
            'body' => "error: script:3: bad argument #2 to 'sell_here' (number expected, got nil)\n"]);
        DB::table('companies')->where('company_id', $id)->update(['eliminated_at' => $now, 'end_cause' => 'closed']);

        $pm = PostMortem::of($id);
        $this->assertStringStartsWith('Its player closed it on', $pm['lines'][0]);
        $this->assertStringContainsString('failed 3 of 3 times', implode(' ', $pm['lines']));
        $this->assertStringContainsString('last said: "no buyer for iron"', implode(' ', $pm['lines']));
        $this->assertSame(3, $pm['line']['number']);
        $this->assertSame('  sell_here("Iron", nil)', $pm['line']['code']);
        $this->assertSame('release?copy=' . $drone . '&fix=' . $id, $pm['copy']);

        $this->get("/companies/$id")->assertOk()->assertSee('id="post-mortem"', false)->assertSee('Start a fixed copy')
            ->assertSee('sell_here(&quot;Iron&quot;, nil)', false);
        $this->getJson("/api/v1/companies/$id")->assertJsonPath('post_mortem.line.number', 3);
        // The fixed copy: its code in the editor, and what went wrong above it
        $this->actingAs($p)->get('/' . $pm['copy'])->assertOk()->assertSee('id="fixing"', false)
            ->assertSee('Fixing Mortem Co.')->assertSee('wait(60)');
    }
}
