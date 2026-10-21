<?php

namespace App\Services\Mail;

/**
 * Opens the INBOX of a mail account. Bound in the container so tests can swap
 * in a fake.
 */
interface InboxFactory
{
    public function open(string $username, string $password): Inbox;
}
