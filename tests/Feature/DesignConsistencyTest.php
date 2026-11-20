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
        foreach (['market' => 'Goods', 'market/house' => 'House', 'market/history' => 'History', 'market/shares' => 'Stock exchange'] as $path => $label) {
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

    public function testPlacesHaveShapesAsWellAsColours()
    {
        $this->assertSame('square', \App\Services\World::placeShape('Mars'));
        $this->assertSame('diamond', \App\Services\World::placeShape('Phobos'));
        $this->assertSame('circle', \App\Services\World::placeShape('Earth'));
        $this->get('/market')->assertOk();
    }

    public function testTheLeaderboardShowsEachCompanysStatusBesideItsScore()
    {
        $this->get('/leaderboard')->assertOk()->assertSee('<th>Status</th>', false);
    }

    public function testDronesAreListedNewestFirst()
    {
        $ids = collect($this->getJson('/spacecraft/search')->assertOk()->json('data'))->pluck('agent_id')->all();
        $this->assertSame($ids, collect($ids)->sortDesc()->values()->all());
    }

    public function testTheLeaderboardAndLineageControlsAreStyled()
    {
        // #744: their boxes, checkboxes and slider were the browser's own, white on the dark site
        $this->get('/leaderboard')->assertOk()->assertSee('class="mk-input"', false)->assertSee('class="mk-button"', false)
            ->assertSee('.mk-input {', false);
        $this->get('/lineage')->assertOk()->assertSee('class="mk-input" id="find"', false)
            ->assertSee('class="mk-check" id="onlymulti"', false)->assertSee('class="mk-range" id="age"', false)
            // It opens on the newest revision, not an empty corner, and gives dates, not "day N" (#744, #753)
            ->assertSee('reveal(newest.id, false)', false)->assertDontSee("'game day ' + n.day", false)->assertDontSee("' · day '", false);
    }
}
