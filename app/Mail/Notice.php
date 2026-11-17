<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

/**
 * An opt-in notice (#761): the daily e-mail, the weekly digest or a teacher's
 * class summary. Each item has a line, what to try next (or none) and a
 * link; every one ends with a link that turns all notices off. HTML with its
 * plain-text twin, as the verification e-mail (#754)
 */
class Notice extends Mailable
{
    public function __construct(public string $heading, public string $name, public string $intro,
                                public array $items, public string $unsubscribe)
    {
    }

    public function build()
    {
        return $this->subject($this->heading)
            ->view('emails.notice-html')
            ->text('emails.notice')
            ->withSymfonyMessage(function ($message) {
                // One-click unsubscribe for mail clients that offer it
                $message->getHeaders()->addTextHeader('List-Unsubscribe', '<' . $this->unsubscribe . '>');
            });
    }
}
