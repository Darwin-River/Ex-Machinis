<?php

namespace Tests\Feature;

use App\Agent;
use App\Services\Mail\InboxFactory;
use App\Services\Mail\IncomingMail;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeInbox;
use Tests\TestCase;

/**
 * /get-mails: registration via the registrar inbox and drone commands via
 * the catch-all inbox. Uses in-memory inboxes and the array mail transport.
 */
class GetMailsTest extends TestCase
{
    use DatabaseTransactions;

    private FakeInbox $inboxes;
    private int $droneId;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.domain' => 'mail.example.com']);
        putenv('MAIL_REGISTRATION_ACCOUNT=registrar');
        putenv('MAIL_CATCHALL_ACCOUNT=catchall');
        config(['app.cron_key' => 'test-cron-key']);

        // A drone with a realistic name (mail local parts can't contain spaces),
        // owned by the fixture company (test@example.com).
        $this->droneId = DB::table('agents')->insertGetId(['user_id' => 1, 'name' => 'SN424242', 'object_id' => 3, 'hull_type' => 1]);

        $this->inboxes = new FakeInbox();
        $this->app->instance(InboxFactory::class, $this->inboxes);
    }

    protected function tearDown(): void
    {
        putenv('MAIL_REGISTRATION_ACCOUNT');
        putenv('MAIL_CATCHALL_ACCOUNT');

        parent::tearDown();
    }

    private function mail(string $subject, string $from, array $to = [], ?string $text = null, string $fromName = 'Sender'): IncomingMail
    {
        return new IncomingMail($subject, $from, $fromName, $to, $text, null, '2026-10-21 12:00:00');
    }

    private function run_(): \Illuminate\Testing\TestResponse
    {
        return $this->get('/get-mails/test-cron-key')->assertOk();
    }

    private function sent(): \Illuminate\Support\Collection
    {
        return collect(app('mailer')->getSymfonyTransport()->messages())->map->getOriginalMessage();
    }

    public function testEmptyInboxes()
    {
        $this->run_()->assertSee('Mailbox registrar is empty')->assertSee('Mailbox catchall is empty');
    }

    public function testMagicaMailRegistersSenderAndIsDeleted()
    {
        $this->inboxes->put('registrar', $m = $this->mail(' MAGICA ', 'New.Player@Example.com', [], null, 'New Player'));

        $this->run_();

        $user = User::where('email', 'new.player@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame('New Player', $user->name);
        $this->assertCount(3, Agent::where('user_id', $user->user_id)->get());
        $this->assertSame([$m], $this->inboxes->deleted);
    }

    public function testMagicaWithoutDisplayNameUsesAddress()
    {
        $this->inboxes->put('registrar', $this->mail('magica', 'nameless@example.com', [], null, ''));

        $this->run_();

        $this->assertSame('nameless', User::where('email', 'nameless@example.com')->value('name'));
    }

    public function testOtherRegistrarMailIsJustDeleted()
    {
        $this->inboxes->put('registrar', $m = $this->mail('hello', 'someone@example.com'));

        $this->run_();

        $this->assertNull(User::where('email', 'someone@example.com')->first());
        $this->assertSame([$m], $this->inboxes->deleted);
    }

    public function testOwnerCommandIsQueuedForDrone()
    {
        $this->inboxes->put('catchall', $m = $this->mail('orders', 'TEST@example.com',
            ['SN424242@mail.example.com' => ''], "<run> 3 5 perform </run>"));

        $this->run_();

        $command = DB::table('commands')->where('agent_id', $this->droneId)->orderByDesc('id')->first();
        $this->assertNotNull($command);
        $this->assertSame('3 5 perform', $command->code);
        $this->assertSame('orders', $command->subject);
        $this->assertCount(0, $this->sent());
        $this->assertSame([$m], $this->inboxes->deleted);
    }

    public function testCommandFromOtherAddressIsRefused()
    {
        $before = DB::table('commands')->count();
        $this->inboxes->put('catchall', $this->mail('orders', 'intruder@example.com',
            ['SN424242@mail.example.com' => ''], "<run> 1 </run>"));

        $this->run_();

        $this->assertSame($before, DB::table('commands')->count());
        $reply = $this->sent()->sole();
        $this->assertSame('intruder@example.com', $reply->getTo()[0]->getAddress());
        $this->assertStringContainsString('Forbidden access', $reply->getSubject());
    }

    public function testInvalidContentGetsReplyFromDroneAddress()
    {
        $this->inboxes->put('catchall', $this->mail('hi', 'test@example.com',
            ['SN424242@mail.example.com' => ''], 'no commands here'));

        $this->run_();

        $reply = $this->sent()->sole();
        $this->assertSame('Re: hi', $reply->getSubject());
        $this->assertSame('test@example.com', $reply->getTo()[0]->getAddress());
        $this->assertSame('SN424242@mail.example.com', $reply->getFrom()[0]->getAddress());
    }

    public function testUnknownDroneAndOtherDomainsAreIgnored()
    {
        $before = DB::table('commands')->count();
        $this->inboxes->put('catchall', $m = $this->mail('x', 'test@example.com',
            ['nobody@mail.example.com' => '', 'SN424242@elsewhere.com' => ''], '<run> 1 </run>'));

        $this->run_();

        $this->assertSame($before, DB::table('commands')->count());
        $this->assertCount(0, $this->sent());
        $this->assertSame([$m], $this->inboxes->deleted);
    }

    public function testTooLongCommandIsRejectedAndDoesNotBlockLaterMails()
    {
        $long = '<run>' . str_repeat('1 drop ', 400) . '</run>'; // 2800 chars of code
        $this->inboxes->put('catchall', $big = $this->mail('bigbody', 'test@example.com', ['SN424242@mail.example.com' => ''], $long));
        $this->inboxes->put('catchall', $ok = $this->mail('orders', 'test@example.com', ['SN424242@mail.example.com' => ''], '<run> 1 2 + </run>'));

        $this->run_();

        $this->assertSame(['1 2 +'], DB::table('commands')->where('agent_id', $this->droneId)->pluck('code')->all());
        $this->assertSame([$big, $ok], $this->inboxes->deleted);
        $reply = $this->sent()->first();
        $this->assertSame('Re: bigbody', $reply->getSubject());
        $this->assertStringContainsString('the limit is 2048', $reply->getTextBody());
    }

    public function testLongSubjectIsTrimmed()
    {
        $this->inboxes->put('catchall', $this->mail(str_repeat('s', 3000), 'test@example.com', ['SN424242@mail.example.com' => ''], '<run> 1 </run>'));

        $this->run_();

        $this->assertSame(2048, mb_strlen(DB::table('commands')->where('agent_id', $this->droneId)->value('subject')));
    }

    public function testFailingMailIsKeptButOthersAreProcessed()
    {
        // Saving this one mail's command fails (say, a database error)
        \App\Command::saving(function ($command) {
            if ($command->code === 'fail') {
                throw new \RuntimeException('boom');
            }
        });
        $this->inboxes->put('catchall', $bad = $this->mail('a', 'test@example.com', ['SN424242@mail.example.com' => ''], '<run>fail</run>'));
        $this->inboxes->put('catchall', $ok = $this->mail('b', 'test@example.com', ['SN424242@mail.example.com' => ''], '<run> 2 </run>'));

        $this->run_()->assertSee('kept for retry');

        $this->assertSame([$ok], $this->inboxes->deleted);
        $this->assertSame(['2'], DB::table('commands')->where('agent_id', $this->droneId)->pluck('code')->all());
    }

    public function testMailWithoutCommandGetsHelpfulReply()
    {
        $this->inboxes->put('catchall', $this->mail('hello', 'test@example.com', ['SN424242@mail.example.com' => ''], '2 3 + . cr'));

        $this->run_();

        $text = $this->sent()->first()->getTextBody();
        $this->assertStringContainsString('between <run> and </run> tags', $text);
        $this->assertStringContainsString("From: test@example.com\nTo: SN424242@mail.example.com\nSent: 2026-10-21 12:00:00", $text);
        $this->assertStringNotContainsString('\\r\\n', $text);
    }
}
