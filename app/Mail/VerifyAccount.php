<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

/** The link that verifies a player account's e-mail address (#636): HTML with a
 *  Verify button, and the same words as plain text for text-only clients (#754) */
class VerifyAccount extends Mailable
{
    public function __construct(public string $name, public string $url)
    {
    }

    public function build()
    {
        return $this->subject('Verify your Ex Machinis account')
            ->view('emails.verify-account-html')
            ->text('emails.verify-account');
    }
}
