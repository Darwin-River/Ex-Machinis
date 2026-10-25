<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The Machine view (#324 step 19). The machine itself is tested in CI by
 * game-engine/machine (make test), against the engine's own VM.
 */
class MachinePageTest extends TestCase
{
    public function testMachinePageLoadsTheVm()
    {
        $this->get('/machine')->assertOk()
            ->assertSee('id="m-defs"', false)
            ->assertSee('wasm/machine.wasm', false)
            ->assertSee('wasm/machine.js', false)
            ->assertSee(': cube ( n -- n*n*n ) dup square * ;', false);
    }

    public function testMachinePageTakesAWordFromTheLink()
    {
        $this->get('/machine?defs=' . urlencode(': twice 2 * ;') . '&stack=21&word=twice')->assertOk()
            ->assertSee(': twice 2 * ;')
            ->assertSee('value="21"', false)
            ->assertSee('value="twice"', false);
    }

    public function testTheVmIsServed()
    {
        $this->assertFileExists(base_path('html/wasm/machine.wasm'));
        $this->assertSame("\0asm", substr(file_get_contents(base_path('html/wasm/machine.wasm')), 0, 4));
    }
}
