<?php

namespace App\Services\Mail;

/**
 * A message read from an inbox, independent of the IMAP library.
 */
class IncomingMail
{
    /**
     * @param array<string, string> $to recipient address => display name
     * @param mixed $handle the library's own message object, used to delete it
     */
    public function __construct(
        public string $subject,
        public string $fromAddress,
        public string $fromName,
        public array $to,
        public ?string $textPlain,
        public ?string $textHtml,
        public string $date,
        public mixed $handle = null,
    ) {
    }
}
