<?php

namespace Tests\Feature;

use App\Agent;
use App\Services\Mail\ImapInboxFactory;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Reads and deletes real messages over IMAP with the webklex adapter.
 *
 * Needs a GreenMail server with auth disabled (CI starts one; see
 * .github/workflows/tests.yml). Set IMAP_TEST_HOST to run it locally, e.g.
 *   java -Dgreenmail.setup.test.all -Dgreenmail.auth.disabled -jar greenmail-standalone.jar
 */
class ImapInboxTest extends TestCase
{
    use DatabaseTransactions;

    private string $host;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = (string) getenv('IMAP_TEST_HOST');
        if ($this->host === '') {
            $this->markTestSkipped('IMAP_TEST_HOST is not set (no test mail server).');
        }
    }

    private function send(Email $email): void
    {
        Transport::fromDsn('smtp://' . $this->host . ':3025')->send($email);
    }

    public static function connections(): array
    {
        return [
            'plain imap' => [3143, false],
            'imaps' => [3993, 'ssl'],
        ];
    }

    /**
     * @dataProvider connections
     */
    public function testReadsAndDeletesMessages(int $port, string|false $encryption)
    {
        $account = 'SN' . random_int(100000, 999999) . '@mail.example.com';

        $this->send((new Email())
            ->from(new Address('owner@example.com', 'Drone Owner'))
            ->to($account)
            ->subject('orders')
            ->text("<run> 3 5 perform </run>\n"));
        $this->send((new Email())
            ->from('other@example.com')
            ->to($account)
            ->subject('html only')
            ->html('<p>Hello <b>drone</b></p>'));

        // GreenMail's certificate is self-signed.
        $factory = new ImapInboxFactory($this->host, $port, $encryption, false);
        $inbox = $factory->open($account, 'any-password');

        $mails = $inbox->messages();
        $this->assertCount(2, $mails);
        $bySubject = [];
        foreach ($mails as $mail) {
            $bySubject[$mail->subject] = $mail;
        }

        $orders = $bySubject['orders'];
        $this->assertSame('owner@example.com', $orders->fromAddress);
        $this->assertSame('Drone Owner', $orders->fromName);
        $this->assertSame([strtolower($account)], array_map('strtolower', array_keys($orders->to)));
        $this->assertStringContainsString('<run> 3 5 perform </run>', $orders->textPlain);
        $this->assertNull($orders->textHtml);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $orders->date);

        $html = $bySubject['html only'];
        $this->assertSame('other@example.com', $html->fromAddress);
        $this->assertSame('', $html->fromName);
        $this->assertNull($html->textPlain);
        $this->assertStringContainsString('<b>drone</b>', $html->textHtml);

        foreach ($mails as $mail) {
            $inbox->delete($mail);
        }
        $this->assertCount(0, $factory->open($account, 'any-password')->messages());
    }

    public function testIncomingHostFallsBackToMailHost()
    {
        $host = fn () => (fn () => $this->host)->call(ImapInboxFactory::fromEnvironment());

        putenv('MAIL_HOST=smtp.example.com');
        $this->assertSame('smtp.example.com', $host());

        putenv('MAIL_INCOMING_HOST=imap.example.com');
        $this->assertSame('imap.example.com', $host());

        putenv('MAIL_HOST');
        putenv('MAIL_INCOMING_HOST');
    }

    public function testEncryptionSettingKeepsItsOldMeaning()
    {
        // "tls" meant STARTTLS in the old c-client mailbox string.
        foreach (['tls' => 'starttls', 'ssl' => 'ssl', '' => false, 'notls' => false] as $env => $expected) {
            putenv('MAIL_ENCRYPTION=' . $env);
            $factory = ImapInboxFactory::fromEnvironment();
            $encryption = (fn () => $this->encryption)->call($factory);
            $this->assertSame($expected, $encryption, "MAIL_ENCRYPTION=$env");
        }
        putenv('MAIL_ENCRYPTION');
    }

    /**
     * The whole /get-mails path over real IMAP: a "magica" mail registers a
     * player, a command mail from the owner queues a command, inboxes empty.
     */
    public function testGetMailsEndToEnd()
    {
        // As in the Docker setup, the IMAP server (MAIL_INCOMING_HOST) and the
        // game's mail domain (MAIL_DOMAIN) are different names.
        $domain = 'localhost';
        $suffix = random_int(100000, 999999);
        $registrar = "registrar$suffix@$domain";
        $catchall = "catchall$suffix@$domain";
        $owner = "owner$suffix@example.com";
        $drone = 'SN' . $suffix;

        foreach ([
            'MAIL_INCOMING_HOST' => $this->host, 'MAIL_INCOMING_PORT' => '3143', 'MAIL_ENCRYPTION' => '',
            'MAIL_REGISTRATION_ACCOUNT' => $registrar, 'MAIL_REGISTRATION_PASSWORD' => 'x',
            'MAIL_CATCHALL_ACCOUNT' => $catchall, 'MAIL_CATCHALL_PASSWORD' => 'x',
        ] as $k => $v) {
            putenv("$k=$v");
        }
        config(['app.cron_key' => 'test-cron-key', 'mail.domain' => $domain]);
        // Drone owned by the fixture company; its owner writes from $owner.
        DB::table('users')->where('user_id', 1)->update(['email' => $owner]);
        $droneId = DB::table('agents')->insertGetId(['user_id' => 1, 'name' => $drone, 'object_id' => 3, 'hull_type' => 1]);

        // Catch-all mail goes to the drone's address; deliver a copy to the
        // catch-all account the way the real mail server's catch-all does.
        $this->send((new Email())->from(new Address("new$suffix@example.com", 'New Player'))->to($registrar)->subject('magica')->text('hi'));
        $this->send((new Email())->from($owner)->to("$drone@$domain")->bcc($catchall)->subject('orders')->text('<run> 1 2 + </run>'));

        $this->get('/get-mails/test-cron-key')->assertOk()->assertSee('magica')->assertSee('Inserting command');

        $this->assertSame('New Player', User::where('email', "new$suffix@example.com")->value('name'));
        $this->assertSame('1 2 +', DB::table('commands')->where('agent_id', $droneId)->value('code'));

        $factory = new ImapInboxFactory($this->host, 3143, false);
        $this->assertCount(0, $factory->open($registrar, 'x')->messages());
        $this->assertCount(0, $factory->open($catchall, 'x')->messages());

        foreach (['MAIL_INCOMING_HOST', 'MAIL_INCOMING_PORT', 'MAIL_ENCRYPTION', 'MAIL_REGISTRATION_ACCOUNT', 'MAIL_REGISTRATION_PASSWORD', 'MAIL_CATCHALL_ACCOUNT', 'MAIL_CATCHALL_PASSWORD'] as $k) {
            putenv($k);
        }
    }
}
