<?php

namespace App\Notifications;

use App\Models\Account;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A renewal succeeded — the "payment success / invoice ready" email.
 */
class PaymentReceiptNotification extends Notification
{
    use Queueable;

    public function __construct(public Account $account, public ?int $amountCents, public string $currency = 'usd')
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $amount = $this->amountCents !== null
            ? strtoupper($this->currency) . ' ' . number_format($this->amountCents / 100, 2)
            : null;

        return (new MailMessage)
            ->subject('Your PioDeploy payment receipt')
            ->greeting('Thanks for your payment')
            ->when($amount, fn ($m) => $m->line("We've received {$amount} for your {$this->account->plan?->name} plan."))
            ->line('Your subscription is active and your invoice is ready to download.')
            ->action('View invoices', route('billing.invoices'))
            ->line('Thank you for using PioDeploy.');
    }
}
