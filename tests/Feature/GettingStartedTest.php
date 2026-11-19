<?php

namespace Tests\Feature;

use App\Http\Controllers\HomeController;
use Tests\TestCase;

/**
 * Getting started is the five-minute first-startup guide (#719); the
 * players' manual, documents/players-manual.md, is at /manual.
 */
class GettingStartedTest extends TestCase
{
    public function testTheManualIsAtManual()
    {
        $this->get('/manual')
            ->assertOk()
            ->assertSee('<title>Manual - Ex-Machinis</title>', false)
            ->assertSee('id="chapter-4-issuing-commands"', false)
            ->assertSee('href="#chapter-4-issuing-commands"', false)
            ->assertSee('Equipping your drone')
            ->assertSee('function launched()', false)
            ->assertDontSee('Keep this manual current');
    }

    /** Round 70: the manual opened with a changelog from 2020; it's at the end now */
    public function testTheManualOpensWithTheGameNotAChangelog()
    {
        $html = $this->get('/manual')->assertOk()->getContent();
        $changes = strpos($html, 'id="appendix-b-changes-since-the-september-2020-edition"');
        $this->assertNotFalse($changes, 'the changes are an appendix');
        $this->assertGreaterThan(strpos($html, 'id="appendix-a-when-your-script-fails"'), $changes, 'after Appendix A');
        $this->assertLessThan(strpos($html, 'id="chapter-1-getting-started"'), strpos($html, 'Your first startup'),
            'the manual points newcomers to the guide first');
    }

    public function testGettingStartedIsTheFirstStartupGuide()
    {
        config(['forge.new_rules' => true]);
        $grant = \App\Services\Startups::grant();
        $html = $this->get('/getting-started')->assertOk()
            ->assertSee('<title>Your first startup - Ex-Machinis</title>', false)
            ->assertSee('Which starter should I pick?')->assertSee('How do I start it?')->assertSee('Is it working?')
            ->assertSee('What if it goes wrong?')->assertSee('When is it mine?')
            ->assertSee('Road to maturity')
            ->assertSee(number_format($grant))
            ->assertSee(number_format($grant + \App\Services\Startups::matureMargin()))
            ->assertSee(\App\Services\Startups::SHARES . ' shares')
            ->assertSee('href="' . url('release?starter=miner') . '"', false)
            ->assertSee('href="' . url('release?starter=refiner') . '"', false)
            ->assertSee('href="' . url('release?starter=maker') . '"', false)
            ->assertDontSee('href="' . url('release?starter=trader') . '"', false)
            ->assertDontSee('Changes since the September 2020 edition')
            ->getContent();
        // Short: not the manual
        $this->assertStringNotContainsString('id="chapter-4-issuing-commands"', $html);
        $this->assertLessThan(60000, strlen($html));
    }

    /** Old links into the manual still land: the guide knows its anchors and forwards them */
    public function testOldManualAnchorsAreForwarded()
    {
        $anchors = HomeController::manualAnchors();
        $this->assertContains('chapter-4-issuing-commands', $anchors);
        $this->assertContains('running-low-on-fuel', $anchors, 'the manual\'s <a name> anchors too');
        $this->get('/getting-started')->assertOk()
            ->assertSee('"chapter-4-issuing-commands"', false)
            ->assertSee("location.replace('" . url('manual') . "#'", false);
        // Old manual addresses move for good
        $this->get('/players-manual')->assertRedirect('/manual')->assertStatus(301);
        $this->get('/getting-started/manual')->assertRedirect('/manual')->assertStatus(301);
    }

    public function testManualImageIsServed()
    {
        $this->get('/manual/images/missing.png')->assertNotFound();
        $this->get('/manual/images/..%2F..%2F.env')->assertNotFound();
        $this->get('/getting-started/images/map.png')->assertStatus(301)->assertRedirect('/manual/images/map.png');
    }

    /** The menus: the guide, the manual, and "Found a startup" as the action's one name */
    public function testTheMenusNameTheGuideAndTheAction()
    {
        config(['forge.new_rules' => true]);
        $this->get('/about-us')->assertOk()
            ->assertSee('First startup (5 minutes)')
            ->assertSee('href="' . url('manual') . '"', false)
            ->assertSee('Lua functions')
            ->assertSee('Found a startup')
            ->assertDontSee('Release a drone');
        $this->get('/')->assertOk()->assertSee('press Found startup')->assertDontSee('press Release');
    }

    public function testRendering()
    {
        $html = HomeController::renderPlayersManual(
            "# Title\n\n> **Keep this manual current.** For maintainers.\n\n## Part One: Start\n\n"
            . "See the [design manual](design-manual.md) and [part one](#part-one-start).\n\n"
            . "| a | b |\n|---|---|\n| 1 | 2 |\n\n<script>alert(1)</script>\n\n![map](images/map.png)\n");

        $this->assertStringContainsString('<h2 id="part-one-start">', $html);
        $this->assertStringContainsString('href="https://github.com/Darwin-River/Ex-Machinis/blob/main/documents/design-manual.md"', $html);
        $this->assertStringContainsString('href="#part-one-start"', $html);
        $this->assertStringContainsString('<table>', $html);
        $this->assertStringContainsString('src="/manual/images/map.png"', $html);
        $this->assertStringNotContainsString('For maintainers', $html);
        $this->assertStringNotContainsString('<script>', $html);

        // The manual's anchors become anchors; any other HTML stays escaped (#678)
        $html = HomeController::renderPlayersManual("<a name=\"running-low\"></a>**Fuel.**\n\n<a name=\"x\" onclick=\"y\"></a>\n");
        $this->assertStringContainsString('<a id="running-low"></a>', $html);
        $this->assertStringContainsString('&lt;a name="x" onclick="y"&gt;', $html, 'escaped, not an element');
        $this->assertStringNotContainsString('<a name="x"', $html);
    }
}
