<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Views print addresses as "<name>@<mail domain>". In Blade, "@{{ ... }}"
 * prints the braces literally, so the address came out as
 * "SN1{{ config('mail.domain') }}".
 */
class MailDomainInViewsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.domain' => 'game.example']);
    }

    public function testWelcomeEmailListsDroneAddresses()
    {
        $data = ['drone1' => 'SN1', 'drone2' => 'SN2', 'drone3' => 'SN3'];
        foreach (['email.welcome_drones_text', 'email.welcome_drones_html'] as $view) {
            $out = view($view, $data)->render();
            $this->assertStringContainsString('SN1@game.example, SN2@game.example, and SN3@game.example', $out, $view);
            $this->assertStringNotContainsString('{{', $out, $view);
        }
    }

    public function testNoViewPrintsBladeLiterally()
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views')));
        foreach ($files as $file) {
            if (str_ends_with($file, '.blade.php')) {
                $this->assertStringNotContainsString('@{{', file_get_contents($file), (string) $file);
            }
        }
    }

    public function testHomePageRegistrarLink()
    {
        $this->get('/')->assertOk()->assertSee('mailto:registrar@game.example?subject=magica', false);
    }
}
