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
            ->assertSee('id="chapter-3-mining-refining-and-making"', false)
            ->assertSee('href="#chapter-3-mining-refining-and-making"', false)
            ->assertSee('id="chapter-4-issuing-commands"', false)
            ->assertSee('Equipping your drone')
            ->assertSee('function launched()', false)
            ->assertDontSee('Keep this manual current');
    }

    /** Round 70: the manual opened with a changelog from 2020; it's at the end now */
    public function testTheManualOpensWithTheGameNotAChangelog()
    {
        $html = $this->get('/manual')->assertOk()->getContent();
        $changes = strpos($html, 'id="appendix-d-changes-since-the-september-2020-edition"');
        $this->assertNotFalse($changes, 'the changes are an appendix');
        $this->assertNotFalse(strpos($html, 'id="appendix-b-changes-since-the-september-2020-edition"'), 'its old ID still lands');
        $this->assertGreaterThan(strpos($html, 'id="appendix-a-when-your-script-fails"'), $changes, 'after Appendix A');
        $this->assertLessThan(strpos($html, 'id="chapter-1-getting-started-with-the-miner"'), strpos($html, 'Your first startup'),
            'the manual points newcomers to the guide first');
    }

    /**
     * #932: the manual is staged. Five parts, each chapter opening with what
     * it is for and ending with an exercise and a mastery check, the
     * reference at the back, and every old chapter's ID still landing
     */
    public function testTheManualIsStaged()
    {
        $html = $this->get('/manual')->assertOk()->getContent();
        $at = fn (string $id) => strpos($html, 'id="' . $id . '"');
        $parts = ['part-i-your-first-drone', 'part-ii-markets', 'part-iii-growing', 'part-iv-going-public', 'part-v-finance',
            'appendix-a-when-your-script-fails', 'appendix-b-the-lua-reference', 'appendix-c-glossary',
            'appendix-d-changes-since-the-september-2020-edition'];
        $last = 0;
        foreach ($parts as $id) {
            $this->assertNotFalse($at($id), "$id is there");
            $this->assertGreaterThan($last, $at($id), "$id comes in order");
            $this->assertStringContainsString('href="#' . $id . '"', $html, "the contents link to $id");
            $last = $at($id);
        }
        // The simplest first: mining before the order book, the order book before shares, shares before loans
        $this->assertLessThan($at('chapter-7-the-order-book-at-a-place'), $at('extracting-minerals'));
        $this->assertLessThan($at('trading-shares-with-other-players'), $at('chapter-7-the-order-book-at-a-place'));
        $this->assertLessThan($at('loans'), $at('trading-shares-with-other-players'));
        // Every chapter says what it is for and ends with an exercise and a mastery check
        preg_match_all('/<h2 id="chapter-\d+-/', $html, $chapters);
        $this->assertCount(23, $chapters[0]);
        $this->assertSame(23, substr_count($html, '<em>In this chapter:</em>'));
        $this->assertSame(23, substr_count($html, '<strong>Exercise.</strong>'));
        $this->assertSame(23, substr_count($html, "<strong>You've mastered this when</strong>"));
        // Old links into the manual still land
        foreach (['chapter-1-getting-started', 'chapter-2-speaking-lua', 'chapter-3-working-with-resources', 'chapter-4-issuing-commands',
                     'chapter-5-building-a-vocabulary', 'chapter-6-going-to-market', 'chapter-7-maturing-and-shares',
                     'chapter-8-remembering-things', 'chapter-9-asking-questions', 'chapter-10-making-decisions',
                     'chapter-10-writing-your-drone-in-lua', 'releasing-a-startup', 'trading-script', 'shares-and-the-stock-exchange',
                     'raising-credits-with-new-shares', 'unpacking-your-kit', 'running-low-on-fuel', 'owned-outright'] as $old) {
            $this->assertNotFalse($at($old), "#$old still lands");
        }
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
        $this->get('/release')->assertOk()->assertSee('Found a startup')->assertDontSee('press Release');
    }

    public function testRendering()
    {
        $html = HomeController::renderPlayersManual(
            "# Title\n\n> **Keep this manual current.** For maintainers.\n\n## Part One: Start\n\n"
            . "See the [design manual](design-manual.md) and [part one](#part-one-start).\n\n"
            . "| a | b |\n|---|---|\n| 1 | 2 |\n\n<script>alert(1)</script>\n\n![map](images/map.png)\n");

        $this->assertStringContainsString('<h2 id="part-one-start">', $html);
        $this->assertStringContainsString('href="https://github.com/Ludocia/ExMachinis/blob/main/documents/design-manual.md"', $html);
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
