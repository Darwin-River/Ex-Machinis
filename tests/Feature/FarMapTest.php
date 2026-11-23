<?php

namespace Tests\Feature;

use App\Services\FarMap;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** #922: Metals (A3) come only from far away, from homes that move each season */
class FarMapTest extends TestCase
{
    use DatabaseTransactions;

    private const KUNSPAR = 1100;
    private const WOLFITE = 1130;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('far-map-ok');
        DB::table('far_seasons')->delete();
        DB::table('far_map')->delete();
        DB::table('far_minerals')->delete();
        DB::table('far_homes')->delete();
        // Kunspar is found on 2 of Jupiter's moons at a time; wolfite stays at the Moon
        foreach ([self::KUNSPAR => 'Kunspar (N)', self::WOLFITE => 'Wolfite (N)'] as $id => $name) {
            DB::table('resources')->insertOrIgnore(['id' => $id, 'name' => $name, 'description' => 'A mineral', 'mass' => 1]);
        }
        DB::table('far_minerals')->insert(['resource' => self::KUNSPAR, 'homes' => 2, 'multiplier' => 10]);
        foreach (range(13, 20) as $moon) {
            DB::table('far_homes')->insert(['resource' => self::KUNSPAR, 'object_id' => $moon]);
        }
        DB::table('abundancies')->insert(['location' => 10, 'resource' => self::WOLFITE, 'multiplier' => 5]);
    }

    private function homes(): array
    {
        return DB::table('abundancies')->where('resource', self::KUNSPAR)->orderBy('location')
            ->pluck('location')->map(fn ($l) => (int) $l)->all();
    }

    public function testTheFirstSeasonsHomesArePutInPlace()
    {
        $season = FarMap::apply();
        $this->assertSame(1, (int) $season->season);
        $homes = $this->homes();
        $this->assertCount(2, $homes);
        foreach ($homes as $h) {
            $this->assertGreaterThanOrEqual(13, $h, 'only far bodies');
        }
        $this->assertEqualsCanonicalizing(array_column(FarMap::pick(1), 'object_id'), $homes, 'season 1\'s pick');
        // Rich, with deposits to match; the near minerals are left alone
        $this->assertSame([10, 10], DB::table('abundancies')->where('resource', self::KUNSPAR)->pluck('multiplier')
            ->map(fn ($m) => (int) $m)->all());
        $this->assertSame(4000, (int) DB::table('deposits')->where('resource', self::KUNSPAR)->value('capacity'));
        $this->assertTrue(DB::table('abundancies')->where('location', 10)->where('resource', self::WOLFITE)->exists());
        // Applying again changes nothing; the tick finds it in place
        FarMap::apply();
        $this->assertSame($homes, $this->homes());
        Cache::forget('far-map-ok');
        $this->assertFalse(FarMap::applyIfDue());
        // The same season always picks the same homes
        $this->assertSame(FarMap::pick(1), FarMap::pick(1));
    }

    public function testTheHomesMoveEachSeasonAndTheNextIsPublishedAWeekAhead()
    {
        FarMap::apply();
        $first = $this->homes();
        DB::table('deposit_state')->insert(['object_id' => $first[0], 'resource' => self::KUNSPAR, 'amount' => 5,
            'refilled_t' => 0]);

        // The next season is planned but not shown until a week before it starts
        $s = FarMap::seasons();
        $this->assertSame(1, $s['current']['season']);
        $this->assertSame(2, $s['next']['season']);
        $this->assertFalse($s['next']['published']);
        $this->assertNull($s['next']['homes']);
        DB::table('far_seasons')->where('season', 1)->update(['starts_at' => now()->subDays(22), 'ends_at' => now()->addDays(6)]);
        DB::table('far_seasons')->where('season', 2)->update(['starts_at' => now()->addDays(6), 'ends_at' => now()->addDays(34)]);
        $s = FarMap::seasons();
        $this->assertTrue($s['next']['published']);
        $next = collect($s['next']['homes'][0]['places'])->pluck('object_id')->sort()->values()->all();
        $this->assertSame(collect(FarMap::pick(2))->pluck('object_id')->sort()->values()->all(), $next);

        // Season 1 ends: the tick moves the homes to season 2's
        DB::table('far_seasons')->where('season', 1)->update(['starts_at' => now()->subDays(29), 'ends_at' => now()->subDay()]);
        DB::table('far_seasons')->where('season', 2)->update(['starts_at' => now()->subDay(), 'ends_at' => now()->addDays(27)]);
        Cache::forget('far-map-ok');
        $this->assertTrue(FarMap::applyIfDue());
        $this->assertSame($next, $this->homes());
        $this->assertSame(2, FarMap::seasons()['current']['season']);
        $this->assertFalse(DB::table('far_map')->where('season', 1)->exists(), 'past seasons are forgotten');
        if (!in_array($first[0], $next, true)) {
            $this->assertFalse(DB::table('deposit_state')->where('object_id', $first[0])->where('resource', self::KUNSPAR)->exists(),
                'a home the mineral left keeps nothing');
        }
    }

    public function testAfterTheGameDataIsReloadedTheHomesComeBack()
    {
        FarMap::apply();
        $homes = $this->homes();
        // A deploy loads the game data, which leaves the far minerals out
        DB::table('abundancies')->where('resource', self::KUNSPAR)->delete();
        DB::table('deposits')->where('resource', self::KUNSPAR)->delete();
        $this->artisan('forge:far-map')->assertExitCode(0);
        $this->assertSame($homes, $this->homes());
        // ...and if the start-up didn't, the tick does
        DB::table('abundancies')->where('resource', self::KUNSPAR)->delete();
        Cache::forget('far-map-ok');
        $this->assertTrue(FarMap::applyIfDue());
        $this->assertSame($homes, $this->homes());
    }

    public function testTheMapPageAndTheApiShowWhereTheyAre()
    {
        FarMap::apply();
        $io = DB::table('objects')->whereIn('object_id', $this->homes())->orderBy('object_name')->value('object_name');
        $this->getJson('/api/v1/far-map')->assertOk()->assertJsonPath('current.season', 1)
            ->assertJsonPath('current.homes.0.resource', self::KUNSPAR)->assertJsonPath('next.published', false)
            ->assertJsonPath('season_days', FarMap::SEASON_DAYS);
        $this->get('/map')->assertOk()->assertSee('Where Metals (A3) come from')->assertSee($io);
    }

    /** The game data leaves the far minerals out, and the House doesn't sell what is only found far away */
    public function testTheGameDataKeepsMetalsA3FarAway()
    {
        $sql = file_get_contents(base_path('game-engine/sql/game_data.sql'));
        preg_match('/INSERT INTO `far_minerals` \(resource, homes, multiplier\) VALUES\n(.*?);/s', $sql, $m);
        $far = array_map('intval', preg_match_all('/\((\d+), \d+, \d+\)/', $m[1] ?? '', $rows) ? $rows[1] : []);
        $this->assertContains(self::KUNSPAR, $far);
        $this->assertNotContains(self::WOLFITE, $far);
        preg_match_all('/INSERT INTO `abundancies` \(location, resource, multiplier\) VALUES\n(.*?);/s', $sql, $blocks);
        foreach ($blocks[1] as $block) {
            foreach ($far as $r) {
                $this->assertDoesNotMatchRegularExpression('/\(\d+, ' . $r . ', \d+\)/', $block, 'far mineral ' . $r . ' is placed by FarMap only');
            }
        }
        $this->assertStringContainsString('(10, 1130, 5)', implode("\n", $blocks[1]), 'wolfite is still at the Moon');
        preg_match_all('/INSERT INTO `station_goods` \(station_id, resource, side, price, daily_units, from_day, steady\) VALUES\n(.*?);/s', $sql, $goods);
        $all = implode("\n", $goods[1]);
        $this->assertDoesNotMatchRegularExpression('/\(\d, 2013, 2,/', $all, 'no exchange sells Metals (A3)');
        $this->assertMatchesRegularExpression('/\(\d, 2013, 1,/', $all, 'the exchanges buy them');
    }
}
