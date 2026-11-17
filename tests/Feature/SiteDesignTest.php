<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Our own design (#524): no trace of the old commercial template */
class SiteDesignTest extends TestCase
{
    public static function pages()
    {
        return [
            'blog' => ['/blog', 'Strategy blog'],
            'support' => ['/support', 'Keep the lights on'],
            'about' => ['/about-us', 'About us'],
            'resources' => ['/resources', 'Resources'],
        ];
    }

    /** @dataProvider pages */
    public function testPagesUseOurLayoutAndPageHeader($url, $title)
    {
        $html = $this->get($url)->assertOk()->getContent();
        $this->assertStringContainsString('class="site-header"', $html);
        $this->assertStringContainsString('<h1 class="page-title">' . $title . '</h1>', $html);
        $this->assertStringContainsString('MIT licence', $html);
        foreach (['rd-navbar', 'core.min.js', 'script.js', 'breadcrumbs-modern', 'mdi-', '/images/bg-'] as $old) {
            $this->assertStringNotContainsString($old, $html, "$url still has $old");
        }
    }

    /** #737: each part of the site has a space scene behind its page header */
    public function testPageHeadersShowTheirPartOfTheSitesScene()
    {
        foreach (['/market' => 'market', '/resources' => 'resources', '/locations' => 'locations',
                  '/about-us' => 'about', '/queries' => 'learn'] as $url => $scene) {
            $this->get($url)->assertOk()
                ->assertSee('class="page-header has-scene"', false)
                ->assertSee('data-scene="' . $scene . '"', false)
                ->assertSee(url("images/headers/$scene.webp"), false);
        }
        foreach (['market', 'resources', 'spacecraft', 'locations', 'companies', 'learn', 'about', 'account', 'default'] as $scene) {
            $this->assertFileExists(public_path("images/headers/$scene.webp"), "the $scene scene");
        }
    }

    public function testTheTemplatesFilesAreGone()
    {
        foreach (['html/js', 'html/fonts', 'resources/assets/sass/custom-styles', 'resources/assets/js/core.min.js'] as $path) {
            $this->assertFileDoesNotExist(base_path($path));
        }
        // Our own renders, made by tools/render: the page-header scenes (#737) and the
        // resource pages' turntables (#707)
        $this->assertSame(['favicon.svg', 'headers', 'logo.png', 'resources'], array_values(array_diff(scandir(base_path('html/images')), ['.', '..'])));
    }
}
