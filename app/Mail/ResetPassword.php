<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

/** The link that lets a player choose a new password (#910): HTML with a button,
 *  and the same words as plain text, like the verification mail (#754) */
class ResetPassword extends Mailable
{
    public function __construct(public string $name, public string $url)
    {
    }

    public function build()
    {
        return $this->subject('Reset your Ex Machinis password')
            ->view('emails.reset-password-html')
            ->text('emails.reset-password');
    }
}
