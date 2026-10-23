<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The sqInventory view (game-engine/sql/create_db.sql): a drone's current
 * units of each resource, adding up the latest successful inventory event of
 * each state (installed, locked).
 */
class InventoryViewTest extends TestCase
{
    use DatabaseTransactions;

    private function units(int $drone, int $resource)
    {
        return DB::table('sqInventory')->where(['drone' => $drone, 'resource' => $resource])->value('units');
    }

    public function testFixtureInventory()
    {
        // Latest successful increment wins; the failed decrement (99) and the
        // move event (77) are ignored.
        $this->assertSame(20, $this->units(1, 2));
        $this->assertNull($this->units(1, 1));
    }

    public function testLatestSuccessfulIncrementOrDecrementCounts()
    {
        $add = fn (int $type, int $outcome, int $qty) => DB::table('events')->insert([
            'event_type' => $type, 'outcome' => $outcome, 'drone' => 1, 'resource' => 3, 'new_quantity' => $qty,
        ]);

        $add(1, 1, 5);
        $this->assertSame(5, $this->units(1, 3));

        $add(1, 1, 8);
        $this->assertSame(8, $this->units(1, 3));

        $add(2, 1, 2);          // decrement
        $this->assertSame(2, $this->units(1, 3));

        $add(2, 0, 0);          // failed: ignored
        $add(4, 1, 50);         // sell order: not an inventory change
        $this->assertSame(2, $this->units(1, 3));
    }

    public function testModulesInUseStillCount()
    {
        // Two units; one gets locked, as an engine is while it travels (#210): the
        // unlocked count drops to 1 and the locked count rises to 1
        $add = fn (int $type, int $locked, int $qty) => DB::table('events')->insert([
            'event_type' => $type, 'outcome' => 1, 'drone' => 1, 'resource' => 3,
            'installed' => 0, 'locked' => $locked, 'new_quantity' => $qty,
        ]);

        $add(1, 0, 2);
        $this->assertSame(2, $this->units(1, 3));

        $add(2, 0, 1);          // lock: one leaves the unlocked state...
        $add(1, 1, 1);          // ...and joins the locked state
        $this->assertSame(2, $this->units(1, 3));

        $add(2, 1, 0);          // unlock
        $add(1, 0, 2);
        $this->assertSame(2, $this->units(1, 3));

        // The spacecraft page lists the locked engine on its own row, and no
        // empty (0 units) row once it is unlocked
        $add(2, 0, 1);
        $add(1, 1, 1);
        $page = $this->get('/spacecraft/1')->assertOk();
        $this->assertSame(2, substr_count($page->getContent(), 'href="' . url('/resources/3') . '"'));
    }

    public function testOrderSearchesUseTheView()
    {
        // Sell order: holds 20, selling down to 5 => 15 for sale.
        $this->get('/sells/search')->assertJsonPath('data.0.volume', 15);
        // Buy order: holds none of resource 1, buying up to 10 => 10 wanted.
        $this->get('/buys/search')->assertJsonPath('data.0.volume', 10)
            ->assertJsonPath('data.0.inventory_units', null);
    }
}
