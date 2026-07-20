<?php

namespace App\Mail;

use App\Models\Client;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Every dunning message a client can receive, one mailable — the stage
 * decides subject and body. One class because the stages are one story
 * (payment failed → reminders → suspended → restored) and must never
 * drift apart in tone or links.
 */
class ClientBillingMail extends Mailable
{
    /** failed | reminder | suspended | restored */
    public function __construct(
        public Client $client,
        public string $stage,
        public ?int $daysLeft = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: match ($this->stage) {
            'failed'    => 'Action needed: your PioDeploy payment did not go through',
            'reminder'  => "Reminder: payment still due — {$this->daysLeft} day".($this->daysLeft === 1 ? '' : 's').' before your account is suspended',
            'suspended' => 'Your PioDeploy account has been suspended for non-payment',
            'restored'  => 'Payment received — your PioDeploy account is active again',
            default     => 'Your PioDeploy billing',
        });
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.client-billing', with: [
            'client'     => $this->client,
            'stage'      => $this->stage,
            'daysLeft'   => $this->daysLeft,
            'billingUrl' => route('tenant.billing'),
        ]);
    }
}
