<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ChannelNotification extends Mailable
{
    public function __construct(
        public string $alertTitle,
        public string $body,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "[PioDeploy] {$this->alertTitle}");
    }

    public function content(): Content
    {
        return new Content(htmlString: nl2br(e($this->body)));
    }
}
