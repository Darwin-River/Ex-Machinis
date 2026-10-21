<?php

namespace App\Services\Mail;

interface Inbox
{
    /**
     * All messages currently in the inbox.
     *
     * @return IncomingMail[]
     */
    public function messages(): array;

    /**
     * Permanently delete a message returned by messages().
     */
    public function delete(IncomingMail $mail): void;
}
