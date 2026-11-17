<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #707: resource pages show a slowly turning picture of the resource, made
 * by tools/render, with a still for visitors who ask for reduced motion.
 */
class ResourcePictureTest extends TestCase
{
    use DatabaseTransactions;

    public function testAResourceWithATurntableShowsIt()
    {
        DB::table('resources')->insertOrIgnore(['id' => 5201, 'name' => 'Smelter (A1)',
            'description' => 'A high temperature furnace for melting and mixing metals', 'mass' => 70]);

        $this->get('/resources/5201')->assertOk()
            ->assertSee('class="col-12 col-md-5 col-lg-4 mb-4 text-center resource-picture"', false)
            ->assertSee(url('images/resources/5201.webp'), false)
            ->assertSee('srcset="' . url('images/resources/5201-still.webp') . '" media="(prefers-reduced-motion: reduce)"', false)
            ->assertSee('alt="Smelter (A1), turning slowly"', false);
    }

    public function testAResourceWithoutOneShowsNoPicture()
    {
        $bare = DB::table('resources')->pluck('id')
            ->first(fn ($id) => !is_file(public_path("images/resources/$id.webp")));
        $this->assertNotNull($bare, 'the test data has a resource without a picture');

        $this->get('/resources/' . $bare)->assertOk()->assertDontSee('resource-picture');
    }

    public function testTheResourceListShowsThumbnails()
    {
        DB::table('resources')->insertOrIgnore(['id' => 5201, 'name' => 'Smelter (A1)',
            'description' => 'A high temperature furnace for melting and mixing metals', 'mass' => 70]);
        $bare = DB::table('resources')->pluck('id')
            ->first(fn ($id) => !is_file(public_path("images/resources/$id-thumb.webp")));

        // The list is a Vue table fed with JSON: a thumbnail for a resource with one, null for one without
        $this->get('/resources')->assertOk()
            ->assertSee('images\\/resources\\/5201-thumb.webp', false)
            ->assertSee('&quot;id&quot;:' . $bare . ',', false);
        $list = $this->get('/resources')->getContent();
        $this->assertMatchesRegularExpression('/&quot;id&quot;:' . $bare . ',[^}]*&quot;thumb&quot;:null/', $list);
    }

    public function testEveryTurntableHasAStillAndAResource()
    {
        $pictures = glob(public_path('images/resources/*.webp'));
        $turntables = array_filter($pictures, fn ($p) => !preg_match('/-(still|thumb|parts)\.webp$/', $p));
        $this->assertNotEmpty($turntables);
        // The resource ids in the game data's resources table
        $sql = file_get_contents(base_path('game-engine/sql/game_data.sql'));
        $rows = explode(";\n", explode('INSERT INTO `resources`', $sql, 2)[1], 2)[0];
        preg_match_all('/^\s*\((\d+),/m', $rows, $m);
        $known = array_map('intval', $m[1]);

        foreach ($turntables as $file) {
            $id = basename($file, '.webp');
            $this->assertMatchesRegularExpression('/^\d+$/', $id, "$file is named by a resource id");
            $this->assertContains((int) $id, $known, "$file is a resource in the game data");
            $this->assertFileExists(public_path("images/resources/$id-still.webp"));
            $this->assertFileExists(public_path("images/resources/$id-thumb.webp"));
        }
    }

    /** #802: a craft's page numbers the subsystems its recipe uses on its picture, and lists them */
    public function testACraftsPageNumbersTheSubsystemsItIsBuiltFrom()
    {
        $this->assertFileExists(public_path('images/resources/6011-parts.json'));
        $data = json_decode(file_get_contents(public_path('images/resources/6011-parts.json')), true);
        DB::table('resources')->insertOrIgnore(['id' => 6011, 'name' => 'Mining Cutter (A1)', 'description' => 'A medium mining craft', 'mass' => 689]);
        DB::table('resources')->insertOrIgnore(['id' => 5001, 'name' => 'Engine (A1)', 'description' => 'An engine', 'mass' => 10]);

        $page = $this->get('/resources/6011')->assertOk()
            ->assertSee('id="craft-parts"', false)
            ->assertSee(url('images/resources/6011-parts.webp'), false)
            ->assertSee('Built from')
            // Engine (A1) is first in the Mining Cutter's recipe, twice over, and links to its page
            ->assertSee('<a href="/resources/5001">Engine (A1)</a> &times;2', false);
        // A marker for each subsystem the picture shows, at its place in it
        $shown = $data['parts'][0];
        $page->assertSee('left: ' . round($shown['x'] * 100, 2) . '%; top: ' . round($shown['y'] * 100, 2) . '%', false);

        // A resource that isn't a craft has none
        $this->get('/resources/5001')->assertOk()->assertDontSee('id="craft-parts"', false);
    }

    /** #802: every numbered picture lists its craft's real recipe, and only numbers what is in it */
    public function testEveryCraftPictureMatchesItsRecipe()
    {
        $sql = file_get_contents(base_path('game-engine/sql/game_data.sql'));
        $files = glob(public_path('images/resources/*-parts.json'));
        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            $id = (int) basename($file, '-parts.json');
            $data = json_decode(file_get_contents($file), true);
            $this->assertFileExists(public_path("images/resources/$id-parts.webp"));
            // The recipe: what its Make protocol (id + 10000) depletes at its first second
            preg_match_all('/\(\d+, 0, (\d+), ' . ($id + 10000) . ', \d+, \d+, \d+, \d+, 1, \d+, (\d+), 1\)/', $sql, $m, PREG_SET_ORDER);
            $recipe = [];
            foreach ($m as [, $rid, $qty]) {
                $recipe[(int) $rid] = ($recipe[(int) $rid] ?? 0) + (int) $qty;
            }
            $this->assertNotEmpty($recipe, "craft $id has a recipe");
            $this->assertSame($recipe, array_column($data['recipe'], 'qty', 'id'), "$file lists craft $id's recipe");
            foreach ($data['parts'] as $p) {
                $this->assertArrayHasKey($p['id'], $recipe, "$file numbers only subsystems in the recipe");
                $this->assertTrue($p['x'] >= 0 && $p['x'] <= 1 && $p['y'] >= 0 && $p['y'] <= 1, "$file places its numbers inside the picture");
            }
        }
    }
}
