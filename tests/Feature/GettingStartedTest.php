<?php

namespace Tests\Feature;

use App\Http\Controllers\HomeController;
use Tests\TestCase;

/**
 * Getting Started renders documents/players-manual.md.
 */
class GettingStartedTest extends TestCase
{
    public function testPageShowsThePlayersManual()
    {
        $this->get('/getting-started')
            ->assertOk()
            ->assertSee('<title>Getting Started - Ex-Machinis</title>', false)
            ->assertSee('id="chapter-4-issuing-commands"', false)
            ->assertSee('href="#chapter-4-issuing-commands"', false)
            ->assertSee('Unpacking your starter kit')
            ->assertSee('src="/getting-started/images/stack-robot.png"', false)
            ->assertDontSee('Keep this manual current');
    }

    public function testManualImageIsServed()
    {
        $this->get('/getting-started/images/stack-robot.png')->assertOk();
        $this->get('/getting-started/images/missing.png')->assertNotFound();
        $this->get('/getting-started/images/..%2F..%2F.env')->assertNotFound();
    }

    public function testRendering()
    {
        $html = HomeController::renderPlayersManual(
            "# Title\n\n> **Keep this manual current.** For maintainers.\n\n## Part One: Start\n\n"
            . "See the [design manual](design-manual.md) and [part one](#part-one-start).\n\n"
            . "| a | b |\n|---|---|\n| 1 | 2 |\n\n<script>alert(1)</script>\n");

        $this->assertStringContainsString('<h2 id="part-one-start">', $html);
        $this->assertStringContainsString('href="https://github.com/Darwin-River/Ex-Machinis/blob/main/documents/design-manual.md"', $html);
        $this->assertStringContainsString('href="#part-one-start"', $html);
        $this->assertStringContainsString('<table>', $html);
        $this->assertStringNotContainsString('For maintainers', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }
}
