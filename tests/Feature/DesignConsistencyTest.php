<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/** Design consistency from the round 71 and 72 reviews (#744) */
class DesignConsistencyTest extends TestCase
{
    use DatabaseTransactions;

    public function testMarketPagesShareATabBarWithTheirOwnMarked()
    {
        config(['forge.new_rules' => true]);
        foreach (['market' => 'Goods', 'market/house' => 'House', 'market/history' => 'History', 'market/shares' => 'Shares'] as $path => $label) {
            $this->get('/' . $path)->assertOk()->assertSee('aria-label="Market pages"', false)
                ->assertSee('aria-current="page" >' . $label, false);
        }
        $this->get('/market/shares')->assertSee('<li class="active">Stock exchange</li>', false);
    }

    public function testOldBlogPostsSayTheyAreUnderTheOldRules()
    {
        $this->get('/blog/1-state-machines')->assertOk()->assertSee('id="old-rules"', false);
        $this->get('/blog/12-a-new-way-to-play')->assertOk()->assertDontSee('id="old-rules"', false);
        $this->get('/blog')->assertOk()->assertSee('written under the old rules');
    }

    public function testTheManualHasOneTitle()
    {
        $page = $this->get('/manual')->assertOk()->getContent();
        $this->assertStringNotContainsString("Ex Machinis Player's Manual</h1>", $page);
        $this->assertStringNotContainsString('Converted from the September 2020 edition', $page);
    }
}
