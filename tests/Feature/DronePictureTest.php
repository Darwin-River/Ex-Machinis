<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #802: a drone's page shows its hull, rendered by tools/render: a craft hull
 * with the subsystems its recipe uses numbered on it and listed beneath, the
 * Mosquito plain.
 */
class DronePictureTest extends TestCase
{
    use DatabaseTransactions;

    public function testAMosquitoDroneShowsItsHullPlain()
    {
        DB::table('agents')->where('agent_id', 1)->update(['hull_type' => 1]);

        $this->get('/spacecraft/1')->assertOk()
            ->assertSee('id="hull-picture"', false)
            ->assertSee(url('images/hulls/1.webp'), false)
            ->assertSee('Mosquito hull')
            ->assertDontSee('<ol class="hull-picture-key"', false);
    }

    public function testADroneInACraftHullNumbersTheSubsystemsItIsBuiltFrom()
    {
        DB::table('hulls')->insertOrIgnore(['id' => 6011, 'name' => 'Mining Cutter (A1)', 'cargo_capacity' => 2000,
            'a_slots' => 3, 'b_slots' => 3, 'c_slots' => 2, 'd_slots' => 0, 'e_slots' => 0]);
        DB::table('resources')->insertOrIgnore(['id' => 5001, 'name' => 'Engine (A1)', 'description' => 'An engine', 'mass' => 10]);
        DB::table('agents')->where('agent_id', 1)->update(['hull_type' => 6011]);
        $data = json_decode(file_get_contents(public_path('images/resources/6011-parts.json')), true);

        $page = $this->get('/spacecraft/1')->assertOk()
            ->assertSee(url('images/resources/6011-parts.webp'), false)
            ->assertSee('Mining Cutter (A1): built from')
            // Engine (A1) is first in the Mining Cutter's recipe, twice over, and links to its page
            ->assertSee('<a href="/resources/5001">Engine (A1)</a> &times;2', false);
        // A number on each subsystem the picture shows, at its place in it
        $shown = $data['parts'][0];
        $page->assertSee('left: ' . round($shown['x'] * 100, 2) . '%; top: ' . round($shown['y'] * 100, 2) . '%', false);
    }

    public function testAHullWithoutAPictureShowsNone()
    {
        DB::table('agents')->where('agent_id', 1)->update(['hull_type' => 2]);   // the Humming Bird has none

        $this->get('/spacecraft/1')->assertOk()->assertDontSee('id="hull-picture"', false);
    }

    public function testEveryHullPictureIsAHullWithItsLabels()
    {
        $files = glob(public_path('images/hulls/*.webp'));
        $this->assertNotEmpty($files);
        $sql = file_get_contents(base_path('game-engine/db-backup/exmachinis_tables.sql'));
        foreach ($files as $file) {
            $id = basename($file, '.webp');
            $this->assertMatchesRegularExpression('/^\d+$/', $id, "$file is named by a hull id");
            $this->assertMatchesRegularExpression('/INSERT INTO `hulls` VALUES .*\(' . $id . ',/', $sql, "$file is a hull");
            $data = json_decode(file_get_contents(public_path("images/hulls/$id.json")), true);
            $this->assertSame([], $data['recipe'], "a hull that isn't a craft has no recipe to number");
            $this->assertSame([], $data['parts']);
        }
    }
}
