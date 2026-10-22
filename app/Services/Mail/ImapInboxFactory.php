<?php

namespace App\Services\Mail;

use Webklex\PHPIMAP\ClientManager;

/**
 * Opens inboxes over IMAP with webklex/php-imap, a pure-PHP client (no PHP
 * imap extension needed; that extension was removed from PHP 8.4).
 */
class ImapInboxFactory implements InboxFactory
{
    public function __construct(
        private string $host,
        private int $port,
        private string|false $encryption,
        private bool $validateCert = true,
    ) {
    }

    /**
     * Build from the existing MAIL_* environment settings.
     *
     * MAIL_ENCRYPTION keeps the meaning it had with the old c-client mailbox
     * string ("{host:port/imap/tls}"), where "tls" meant STARTTLS on the plain
     * port. In webklex, "tls" means implicit TLS, so map it to "starttls".
     */
    public static function fromEnvironment(): self
    {
        $encryption = match (strtolower((string) getenv('MAIL_ENCRYPTION'))) {
            'tls', 'starttls' => 'starttls',
            'ssl' => 'ssl',
            default => false,
        };

        return new self(
            // MAIL_INCOMING_HOST when the IMAP server differs from MAIL_HOST
            (string) (getenv('MAIL_INCOMING_HOST') ?: getenv('MAIL_HOST')),
            (int) (getenv('MAIL_INCOMING_PORT') ?: ($encryption === 'ssl' ? 993 : 143)),
            $encryption,
            filter_var(getenv('MAIL_INCOMING_VALIDATE_CERT') ?: 'true', FILTER_VALIDATE_BOOLEAN),
        );
    }

    public function open(string $username, string $password): Inbox
    {
        $client = (new ClientManager())->make([
            'host' => $this->host,
            'port' => $this->port,
            'encryption' => $this->encryption,
            'validate_cert' => $this->validateCert,
            'username' => $username,
            'password' => $password,
            'protocol' => 'imap',
        ]);
        $client->connect();

        return new ImapInbox($client->getFolder('INBOX'));
    }
}
