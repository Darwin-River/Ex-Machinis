<?php

namespace Tests\Feature;

use App\Http\Controllers\BlogController;
use App\Services\Release;
use Tests\TestCase;

class BlogPageTest extends TestCase
{
    public function testTheIndexListsEveryPost()
    {
        $posts = BlogController::posts();
        $this->assertGreaterThanOrEqual(5, count($posts));
        $response = $this->get('/blog')->assertOk();
        foreach ($posts as $slug => $post) {
            $this->assertNotSame('', $post['title'], "$slug has a title");
            $this->assertNotSame('', $post['summary'], "$slug has a summary");
            $response->assertSee('/blog/' . $slug, false)->assertSee($post['title']);
        }
    }

    public function testAPostRendersItsMarkdownAndLinksToTheNext()
    {
        $this->get('/blog/1-state-machines')->assertOk()
            ->assertSee('Drones that change their minds')
            ->assertSee('<h2 id="the-code">The code</h2>', false)
            ->assertSee(': step ( -- )')
            ->assertSee('/blog/2-buy-orders', false)
            // Front matter isn't shown as text
            ->assertDontSee('summary:');
    }

    public function testAnUnknownPostIsNotFound()
    {
        $this->get('/blog/no-such-post')->assertNotFound();
    }

    public function testPostCodeRedefinesNoWordTheDroneKnows()
    {
        // The code examples load on a drone (game-engine/machine
        // check_starters.mjs checks them on the drone VM); here: none of
        // them replaces a game word, as the Release page would warn
        foreach (BlogController::posts() as $slug => $post) {
            preg_match_all('/```forth\R(.*?)```/s', file_get_contents($post['file']), $m);
            $this->assertNotEmpty($m[1], "$slug has code");
            $this->assertEquals([], Release::redefinedWords(implode("\n", $m[1])), $slug);
        }
    }
}
