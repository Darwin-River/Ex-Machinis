<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Our own design (#524): no trace of the old commercial template */
class SiteDesignTest extends TestCase
{
    public static function pages()
    {
        return [
            'blog' => ['/blog', 'Strategy Blog'],
            'support' => ['/support', 'Keep the Lights On'],
            'about' => ['/about-us', 'About Us'],
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

    public function testTheTemplatesFilesAreGone()
    {
        foreach (['html/js', 'html/fonts', 'resources/assets/sass/custom-styles', 'resources/assets/js/core.min.js'] as $path) {
            $this->assertFileDoesNotExist(base_path($path));
        }
        $this->assertSame(['favicon.svg', 'logo.png'], array_values(array_diff(scandir(base_path('html/images')), ['.', '..'])));
    }
}
