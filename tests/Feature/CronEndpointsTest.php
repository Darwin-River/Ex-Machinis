<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The URLs called by cron jobs must only work with the configured key.
 */
class CronEndpointsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.cron_key' => 'test-cron-key']);
        Mail::fake();
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

    public function cronUrls()
    {
        return [
            'get mails'   => ['/get-mails/'],
            'send report' => ['/user/send-report/'],
        ];
    }

    public function testSendReportWorksWithCorrectKey()
    {
        $this->get('/user/send-report/test-cron-key')
            ->assertOk()
            ->assertSee('Notification sent');
    }

    public function testUsersReportListsUsersAndDroneCounts()
    {
        $html = view('email.users_report', [
            'currentUsers' => \App\User::with('agents')->orderBy('user_id', 'desc')->get(),
        ])->render();

        $this->assertStringContainsString('Test Company', $html);
        $this->assertStringContainsString('test@example.com', $html);
        $this->assertRegExp('#<td>\s*1\s*</td>\s*</tr>#', $html);
    }
}
