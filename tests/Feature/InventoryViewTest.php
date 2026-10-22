<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The sqInventory view (game-engine/sql/create_db.sql): a drone's current
 * units of each resource, from its latest successful inventory event.
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

    public function testOrderSearchesUseTheView()
    {
        // Sell order: holds 20, selling down to 5 => 15 for sale.
        $this->get('/sells/search')->assertJsonPath('data.0.volume', 15);
        // Buy order: holds none of resource 1, buying up to 10 => 10 wanted.
        $this->get('/buys/search')->assertJsonPath('data.0.volume', 10)
            ->assertJsonPath('data.0.inventory_units', null);
    }
}
