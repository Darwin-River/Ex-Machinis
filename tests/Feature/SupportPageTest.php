<?php

namespace Tests\Feature;

use Tests\TestCase;

class SupportPageTest extends TestCase
{
    public function testThePageShowsTheCostsAndHowMuchIsCovered()
    {
        config([
            'support.costs' => ['Server' => 30, 'Domain' => 2, 'AI' => 20],
            'support.covered' => 39,
            'support.month' => 'October 2026',
        ]);
        $this->get('/support')->assertOk()
            ->assertSee('Server')
            ->assertSee('$52')
            ->assertSee('75% covered: $39 of $52 in October 2026', false)
            ->assertSee('aria-valuenow="75"', false);
    }

    public function testCoverageStopsAtAHundredPercent()
    {
        config(['support.costs' => ['Server' => 10], 'support.covered' => 25]);
        $this->get('/support')->assertOk()->assertSee('100% covered', false);
    }

    public function testWithoutAKofiNameThePageSaysComingSoon()
    {
        config(['support.kofi' => '']);
        $this->get('/support')->assertOk()
            ->assertSee('Our Ko-fi page is coming soon')
            ->assertDontSee('ko-fi.com/', false);
    }

    public function testWithAKofiNameThePageLinksToIt()
    {
        config(['support.kofi' => 'exmachinis']);
        $this->get('/support')->assertOk()
            ->assertSee('https://ko-fi.com/exmachinis', false)
            ->assertDontSee('coming soon');
    }

    public function testEveryPageLinksToSupportAndNoneToPatreon()
    {
        $this->get('/blog')->assertOk()
            ->assertSee('href="' . url('support') . '"', false)
            ->assertDontSee('patreon', false);
    }
}
