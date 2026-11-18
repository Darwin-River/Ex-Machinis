<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Round 73's fixes (#380): every starter and example fits the release limit
 * (#829: the Maker was 16,648 characters), the market pages' tab bar is
 * styled and every good page's price chart parses (#830)
 */
class RoundSeventyThreeFixesTest extends TestCase
{
    use DatabaseTransactions;

    /** #829: a newcomer can release every starter and example as shipped */
    public function testEveryStarterAndExampleFitsTheReleaseLimit()
    {
        $max = (int) config('forge.max_script_length');
        $files = array_merge(glob(base_path('examples/starters/*.lua')), glob(base_path('examples/companies/*.lua')),
            glob(base_path('examples/traders/*.lua')));
        $this->assertNotEmpty($files);
        foreach ($files as $f) {
            // As the release reads it: trimmed (the check counts characters)
            $this->assertLessThanOrEqual($max, mb_strlen(trim(file_get_contents($f))), basename($f) . ' is over the release limit');
        }
        // And the Maker passes the check as the Release page serves it
        $maker = file_get_contents(base_path('examples/starters/maker.lua'));
        $this->postJson('/api/v1/check', ['script' => $maker])->assertOk()->assertJsonPath('error', null);
    }

    /** #830: the tab bar's styles go in the head: Vue on #app drops <style> tags inside it */
    public function testTheMarketTabsAreStyledFromTheHead()
    {
        foreach (['/market', '/market/house', '/market/history', '/market/shares', '/market/trade'] as $page) {
            $html = $this->get($page)->assertOk()->getContent();
            $style = strpos($html, '.mt-tabs {');
            $app = strpos($html, 'id="app"');
            $this->assertNotFalse($style, "$page has the tabs' styles");
            $this->assertLessThan($app, $style, "$page: the tabs' styles are before #app");
            $this->assertSame(1, substr_count($html, '.mt-tabs {'), "$page: once");
        }
    }

    /** #830: a good page's chart script parses with no places known ([] not {}) */
    public function testAGoodsChartParsesWithNoPlaces()
    {
        // Market history always draws the chart (a good's page only once it has traded); the same partial
        $html = $this->get('/market/history')->assertOk()->getContent();
        $this->assertStringNotContainsString('{}.forEach', $html);
        // And the partial always hands the script a list
        $this->assertStringContainsString('@json(collect($places)->values()).forEach',
            file_get_contents(resource_path('views/partials/price-volume-chart.blade.php')));
        $this->assertSame('[]', json_encode(collect([])->values()));
        $this->assertSame('[3,10]', json_encode(collect([0 => 3, 2 => 10])->values()));
    }
}
