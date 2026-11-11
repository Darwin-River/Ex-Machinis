<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The scheduler's tick (#367 step 5: it read the game's mailboxes before
 * email was removed): it needs the cron key.
 */
class CronEndpointsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.cron_key' => 'test-cron-key']);
    }

    /**
     * @dataProvider cronUrls
     */
    public function testWrongKeyIsRefused($url)
    {
        $this->get($url . 'wrong-key')->assertForbidden();
    }

    /**
     * @dataProvider cronUrls
     */
    public function testRefusedWhenNoKeyIsConfigured($url)
    {
        config(['app.cron_key' => null]);
        $this->get($url . 'anything')->assertForbidden();
    }

    /**
     * @dataProvider cronUrls
     */
    public function testTickRunsWithTheKey($url)
    {
        $this->get($url . 'test-cron-key')->assertOk()->assertSee('ok');
    }

    public static function cronUrls()
    {
        return [
            'tick'               => ['/tick/'],
            'tick, its old name' => ['/get-mails/'],
        ];
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
}
