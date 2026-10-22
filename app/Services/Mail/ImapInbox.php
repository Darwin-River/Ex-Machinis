<?php

namespace App\Services\Mail;

use Webklex\PHPIMAP\Address;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message;

class ImapInbox implements Inbox
{
    public function __construct(private Folder $folder)
    {
    }

    public function messages(): array
    {
        $messages = [];
        foreach ($this->folder->messages()->all()->get() as $message) {
            $messages[] = $this->toIncomingMail($message);
        }

        return $messages;
    }

    public function delete(IncomingMail $mail): void
    {
        $mail->handle->delete(true);
    }

    private function toIncomingMail(Message $message): IncomingMail
    {
        $from = $message->getFrom()->first();

        $to = [];
        foreach ($message->getTo()->toArray() as $address) {
            if ($address instanceof Address && $address->mail !== '') {
                $to[$address->mail] = $address->personal;
            }
        }

        $date = $message->getDate()->first();

        return new IncomingMail(
            subject: (string) $message->getSubject(),
            fromAddress: $from instanceof Address ? $from->mail : '',
            fromName: $from instanceof Address ? $from->personal : '',
            to: $to,
            textPlain: $message->hasTextBody() ? $message->getTextBody() : null,
            textHtml: $message->hasHTMLBody() ? $message->getHTMLBody() : null,
            // No Date header: use the time we read it
            date: ($date ?: now())->format('Y-m-d H:i:s'),
            handle: $message,
        );
    }
}
