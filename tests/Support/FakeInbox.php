<?php

namespace Tests\Support;

use App\Services\Mail\Inbox;
use App\Services\Mail\InboxFactory;
use App\Services\Mail\IncomingMail;

/**
 * In-memory inboxes keyed by account name, for testing mail handling.
 */
class FakeInbox implements Inbox, InboxFactory
{
    /** @var array<string, IncomingMail[]> */
    public array $accounts = [];

    /** @var IncomingMail[] */
    public array $deleted = [];

    private ?string $current = null;

    public function put(string $account, IncomingMail $mail): void
    {
        $this->accounts[$account][] = $mail;
    }

    public function open(string $username, string $password): Inbox
    {
        $inbox = clone $this;
        $inbox->current = $username;
        $inbox->parent = $this;
        return $inbox;
    }

    public ?FakeInbox $parent = null;

    public function messages(): array
    {
        return $this->parent->accounts[$this->current] ?? [];
    }

    public function delete(IncomingMail $mail): void
    {
        $this->parent->deleted[] = $mail;
    }
}
