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

    public function testEveryTurntableHasAStillAndAResource()
    {
        $pictures = glob(public_path('images/resources/*.webp'));
        $turntables = array_filter($pictures, fn ($p) => !str_ends_with($p, '-still.webp'));
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
        }
    }
}
