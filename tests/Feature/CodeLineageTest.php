<?php

namespace Tests\Feature;

use App\Services\CodeLineage;
use App\Services\Release;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Code lineage (#616): each release and push gets parents, the earlier
 * revisions whose code it carries, compared function by function with
 * names normalized; a revision can have several, edges only run forward in
 * time, and what nothing earlier explains is new code.
 */
class CodeLineageTest extends TestCase
{
    use DatabaseTransactions;

    private const ALPHA = <<<'LUA'
function alpha(x)
  local y = x * 2
  if y > 10 then return y - 1 end
  for i = 1, y do x = x + i end
  return x + y
end
LUA;
    private const BETA = <<<'LUA'
function beta(s)
  local t = {}
  for k, v in pairs(s) do t[#t + 1] = v * k end
  table.sort(t)
  return t[1] or 0
end
LUA;
    private const GAMMA = <<<'LUA'
function gamma(q)
  local r = 0
  while q > 0 do r = r + q % 7; q = q // 7 end
  return r, q, r * q
end
LUA;

    private function hashes(string $src): array
    {
        return array_column(CodeLineage::genes($src), 'hash', 'name');
    }

    public function testGenesIgnoreNamesCommentsOrderAndNumbers()
    {
        $src = "grow_at = 7000 -- settings\nhold_cap = 16\n" . self::ALPHA . "\n" . self::BETA . "\nfunction launched() alpha(grow_at) end\n";
        $genes = CodeLineage::genes($src);
        $this->assertSame(['alpha', 'beta', 'launched', '(main)'], array_column($genes, 'name'));

        $renamed = preg_replace(['/\balpha\b/', '/\bgrow_at\b/', '/\bx\b/', '/\by\b/'], ['first', 'grow_when', 'n', 'm'], $src);
        $this->assertNotSame($src, $renamed);
        $this->assertSame(array_values($this->hashes($src)), array_values($this->hashes($renamed)), 'renaming changes nothing');

        $moved = "hold_cap = 16\n--[[ a long\ncomment ]]\n" . self::BETA . "\n" . self::ALPHA . "\ngrow_at = 6500\nfunction launched() alpha(grow_at) end\n";
        $this->assertEqualsCanonicalizing(array_values($this->hashes($src)), array_values($this->hashes($moved)),
            'comments, the order of functions and statements, and numbers change nothing');

        // A library name or a field keeps its name: calling buy_here isn't calling sell_here
        $this->assertNotSame($this->hashes("function f() buy_here(fuel, 3) end")['f'], $this->hashes("function f() sell_here(fuel, 3) end")['f']);
        $this->assertNotSame($this->hashes("function f(c) return c.credits end")['f'], $this->hashes("function f(c) return c.wealth end")['f']);
    }

    public function testSimilarity()
    {
        $g = fn ($src) => CodeLineage::genes($src)[0];
        $alpha = $g(self::ALPHA);
        $this->assertSame(1.0, CodeLineage::similarity($alpha, $alpha));
        $edited = $g(str_replace('return x + y', "if x > 3 then x = 3 end\n  return x + y", self::ALPHA));
        $s = CodeLineage::similarity($edited, $alpha);
        $this->assertGreaterThan(0.6, $s, 'an edited function is a close variant');
        $this->assertLessThan(1.0, $s);
        $this->assertSame(0.0, CodeLineage::similarity($g(self::GAMMA), $alpha), 'an unrelated function explains nothing');
    }

    public function testParentsRecombineAndRunForwardInTime()
    {
        $a = CodeLineage::genes(self::ALPHA);
        $b = CodeLineage::genes(self::BETA);
        [$parents, $new] = CodeLineage::parents(CodeLineage::genes(self::ALPHA . "\n" . self::BETA), ['a' => $a, 'b' => $b]);
        $this->assertEqualsCanonicalizing(['a', 'b'], array_column($parents, 0), 'two parents');
        $this->assertLessThan(0.05, $new);

        [$parents] = CodeLineage::parents($a, ['first' => $a, 'copy' => $a]);
        $this->assertSame(['first'], array_column($parents, 0), 'ties go to the earliest');

        [$parents, $new] = CodeLineage::parents(CodeLineage::genes(self::ALPHA . "\n" . self::GAMMA), ['a' => $a]);
        $this->assertSame(['a'], array_column($parents, 0));
        $this->assertGreaterThan(0.3, $new, 'gamma is new');
    }

    public function testCatchUpStoresTheLineageAndThePagesShowIt()
    {
        DB::table('code_parents')->delete();
        DB::table('code_genes')->delete();
        DB::table('code_revisions')->delete();
        $player = User::create(['name' => 'Lin', 'email' => 'lin@example.com', 'password' => bcrypt('secret-password')]);
        $now = \App\Services\World::gameNow();
        $release = function (string $name, string $src, int $daysAgo) use ($player, $now) {
            $co = Release::release($player, $name, $src)->company_id;
            DB::table('companies')->where('company_id', $co)->update(['founded_t' => $now - $daysAgo * 86400]);
            return $co;
        };
        $a = $release('Alpha Co', self::ALPHA, 30);
        $b = $release('Beta Co', self::BETA, 20);
        $ab = $release('Both Co', self::ALPHA . "\n" . self::BETA, 10);
        $starter = $release('Starter Co', (string) file_get_contents(base_path('examples/starters/miner.lua')), 5);
        DB::table('code_pushes')->insert(['company_id' => $a, 'number' => 1, 'game_t' => $now - 2 * 86400, 'credits_before' => 0,
            'permille' => 50, 'fee' => 0, 'drones' => 1, 'script' => self::ALPHA . "\n" . self::GAMMA]);

        while (CodeLineage::catchUp() > 0) {
        }
        $both = CodeLineage::ofCompany($ab);
        $this->assertEqualsCanonicalizing(['Alpha Co', 'Beta Co'], array_column($both['revisions'][0]['parents'], 'label'));
        $this->assertLessThan(0.05, $both['revisions'][0]['new_share']);

        $alpha = CodeLineage::ofCompany($a);
        $this->assertSame([], $alpha['revisions'][0]['parents'], 'the oldest has no earlier code');
        $this->assertSame('Push 1', $alpha['revisions'][1]['label']);
        $this->assertSame('Alpha Co', $alpha['revisions'][1]['parents'][0]['label'], 'a push carries its own release');
        $this->assertGreaterThan(0.3, $alpha['revisions'][1]['new_share']);
        $this->assertSame(['Both Co'], array_column($alpha['children'], 'label'), 'its own push is not a child');

        $miner = CodeLineage::ofCompany($starter);
        $this->assertSame('the miner starter', $miner['revisions'][0]['parents'][0]['label']);
        $this->assertSame(1.0, $miner['revisions'][0]['parents'][0]['share']);
        $this->assertStringEndsWith('examples/starters/miner.lua', $miner['revisions'][0]['parents'][0]['url']);

        // Every edge runs from an older revision to a newer one
        foreach (DB::table('code_parents as p')->join('code_revisions as c', 'c.id', '=', 'p.revision_id')
                     ->join('code_revisions as q', 'q.id', '=', 'p.parent_id')->get(['c.game_t as child', 'q.game_t as parent']) as $e) {
            $this->assertTrue($e->parent === null || $e->parent < $e->child);
        }

        $this->get('/companies/' . $ab)->assertOk()->assertSee('Lineage')
            ->assertSee('href="' . url('/companies/' . $a) . '#code"', false)->assertSeeInOrder(['Release', 'Beta Co', 'Alpha Co', '0%']);
        $this->get('/companies/' . $a)->assertOk()->assertSee('Carried on by')->assertSee('Both Co');
        $this->getJson('/api/v1/companies/' . $ab . '/lineage')->assertOk()
            ->assertJsonPath('revisions.0.label', 'Release')->assertJsonFragment(['label' => 'Beta Co']);
        $this->getJson('/api/v1/companies/999999/lineage')->assertNotFound();
    }

    public function testANewReleaseWaitsForItsParents()
    {
        DB::table('code_parents')->delete();
        DB::table('code_genes')->delete();
        DB::table('code_revisions')->delete();
        $co = Release::release(User::create(['name' => 'Wait', 'email' => 'wait@example.com', 'password' => bcrypt('secret-password')]),
            'Waiting Co', self::ALPHA)->company_id;
        $this->get('/companies/' . $co)->assertOk()->assertDontSee('id="lineage"', false);
        CodeLineage::catchUp();
        $this->assertNotNull(CodeLineage::ofCompany($co)['revisions'][0]['new_share']);
    }

    /**
     * #618: the lineage map's graph: nodes with their parents, lanes with
     * each family after the code it came from, and each company's net
     * wealth rank at the map's ages
     */
    public function testTheMapsGraphAndPage()
    {
        DB::table('code_parents')->delete();
        DB::table('code_genes')->delete();
        DB::table('code_revisions')->delete();
        $now = time();
        DB::table('game_clock')->delete();
        DB::table('game_clock')->insert(['id' => 1, 'real_anchor' => $now, 'game_anchor' => $now, 'scale' => 1, 'epoch' => $now - 1000 * 86400]);
        $player = User::create(['name' => 'Map', 'email' => 'map@example.com', 'password' => bcrypt('secret-password')]);
        $release = function (string $name, string $src, int $daysAgo) use ($player, $now) {
            $co = Release::release($player, $name, $src)->company_id;
            DB::table('companies')->where('company_id', $co)->update(['founded_t' => $now - $daysAgo * 86400]);
            return $co;
        };
        $a = $release('Alpha Co', self::ALPHA, 40);
        $g = $release('Gamma Co', self::GAMMA, 35);
        $b = $release('Beta Co', self::BETA, 30);
        $ab = $release('Both Co', self::ALPHA . "\n" . self::BETA, 20);
        DB::table('companies')->where('company_id', $ab)->increment('credits', 900);
        while (CodeLineage::catchUp() > 0) {
        }

        $graph = CodeLineage::graph();
        $node = fn ($co) => collect($graph['nodes'])->firstWhere('company_id', $co);
        $this->assertCount(2, $node($ab)['parents'], 'Both Co has two parents');
        $this->assertEqualsCanonicalizing([$node($a)['id'], $node($b)['id']], array_column($node($ab)['parents'], 0));
        $this->assertEqualsWithDelta(980.0, $node($ab)['day'], 0.01, 'game day 980: founded 20 days before day 1000');
        $lane = fn ($co) => array_search($co, array_column($graph['lanes'], 'company_id'), true);
        $main = collect($graph['nodes'])->firstWhere('id', $node($ab)['parents'][0][0])['company_id'];
        $this->assertSame($b, $main, 'Beta explains more of it than Alpha');
        $this->assertSame($lane($b) + 1, $lane($ab), 'Both Co comes after the family it mainly came from');
        $this->assertLessThan($lane($g), $lane($a), 'roots in time order');
        $this->assertSame(url('/companies/' . $a), $graph['lanes'][$lane($a)]['url']);
        $this->assertNotEmpty(array_filter($graph['lanes'], fn ($l) => $l['example'] === 'starters/miner'), 'the examples are lanes too');
        $this->assertSame(CodeLineage::MAP_AGES, $graph['ages']);
        $i = array_search(10, $graph['ages'], true);
        $this->assertNotNull($graph['companies'][$ab]['wealth'][$i], 'Both Co reached 10 days');
        $this->assertNull($graph['companies'][$ab]['wealth'][array_search(30, $graph['ages'], true)], 'but not 30');
        $this->assertSame($graph['companies'][$ab]['rank'][$i][1], collect($graph['companies'])->filter(fn ($c) => $c['rank'][$i] !== null)->count());

        $this->get('/lineage')->assertOk()->assertSee('id="plate"', false)->assertSee('"label":"Both Co"', false)
            ->assertSee('href="' . url('lineage') . '"', false);
        $this->getJson('/api/v1/lineage')->assertOk()->assertJsonPath('ages', CodeLineage::MAP_AGES)
            ->assertJsonFragment(['label' => 'Both Co']);
        $this->get('/companies/' . $ab)->assertSee('lineage?company=' . $ab, false);
    }
}
