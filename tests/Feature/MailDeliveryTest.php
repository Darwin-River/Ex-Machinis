<?php

namespace Tests\Feature;

use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Sends the app's emails through the real mailer stack (array transport),
 * so the messages are actually built rather than faked.
 */
class MailDeliveryTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // The app reads these with getenv(); production sets them in .env.
        putenv('MAIL_HOST=mail.example.com');
        putenv('REPORTS_DESTINATION=reports@example.com');
        config(['app.cron_key' => 'test-cron-key']);
    }

    protected function tearDown(): void
    {
        putenv('MAIL_HOST');
        putenv('REPORTS_DESTINATION');

        parent::tearDown();
    }

    private function sentMessages()
    {
        return app('mailer')->getSymfonyTransport()->messages();
    }

    public function testWelcomeEmailIsSentOnRegistration()
    {
        $this->post('/web-register', [
            'name' => 'Mail Tester',
            'email' => 'mail.tester@example.com',
        ])->assertRedirect('/message');

        $messages = $this->sentMessages();
        $this->assertCount(1, $messages);

        $email = $messages->first()->getOriginalMessage();
        $this->assertSame('Welcome to Ex Machinis!', $email->getSubject());
        $this->assertSame('mail.tester@example.com', $email->getTo()[0]->getAddress());
        $this->assertSame('registrar@mail.example.com', $email->getFrom()[0]->getAddress());

        // The welcome email lists the three new drones by name.
        $user = User::where('email', 'mail.tester@example.com')->first();
        foreach (\App\Agent::where('user_id', $user->user_id)->get() as $drone) {
            $this->assertStringContainsString($drone->name, $email->getTextBody());
        }
    }

    public function testAlreadyRegisteredEmailForKnownAddress()
    {
        \App\User::registerUser('test@example.com', 'Test Company');

        $email = $this->sentMessages()->first()->getOriginalMessage();
        $this->assertSame('Already registered', $email->getSubject());
        $this->assertStringContainsString('Test Drone@mail.example.com', $email->getTextBody());
    }

    public function testUsersReportIsSent()
    {
        $this->get('/user/send-report/test-cron-key')->assertOk();

        $email = $this->sentMessages()->first()->getOriginalMessage();
        $this->assertSame('reports@example.com', $email->getTo()[0]->getAddress());
        $this->assertStringContainsString('Test Company', $email->getHtmlBody());
    }
}
