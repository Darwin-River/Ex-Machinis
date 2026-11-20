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

    /** #764: the House's own loop reposts its orders, with the key only, and the tick then leaves them to it */
    public function testTheHouseLoop()
    {
        \Illuminate\Support\Facades\Cache::forget('house-loop-alive');
        $this->get('/house/wrong-key')->assertStatus(403);
        $this->get('/house/test-cron-key')->assertOk();
        $this->assertTrue(\Illuminate\Support\Facades\Cache::has('house-loop-alive'));
        $this->assertSame(0, \App\Services\HouseMarket::tick(), 'the tick leaves the House to its loop');
        // One run at a time
        $lock = \Illuminate\Support\Facades\Cache::lock('house-refresh', 30);
        $this->assertTrue($lock->get());
        $this->assertSame(-1, \App\Services\HouseMarket::refreshNow());
        $lock->release();
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
