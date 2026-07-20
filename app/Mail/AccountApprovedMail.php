<?php

namespace App\Mail;

use App\Models\Signup;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "You're in" — sent the moment an admin approves a signup. Carries the
 * login URL and which email to use; never the password (they chose it
 * themselves at signup and we only ever held a hash).
 */
class AccountApprovedMail extends Mailable
{
    public function __construct(public Signup $signup)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your PioDeploy account is ready');
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.account-approved', with: [
            'signup'   => $this->signup,
            'loginUrl' => route('login'),
        ]);
    }
}
