<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

/** The link that verifies a player account's e-mail address (#636) */
class VerifyAccount extends Mailable
{
    public function __construct(public string $name, public string $url)
    {
    }

    public function build()
    {
        return $this->subject('Verify your Ex Machinis account')->text('emails.verify-account');
    }
}
