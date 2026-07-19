<?php

namespace App\Notifications;

use App\Models\Account;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The follow-up after Stripe's own retries have failed: sent every few days
 * while the subscription stays unpaid, until the card is fixed or the
 * subscription ends.
 */
class DunningReminderNotification extends Notification
{
    use Queueable;

    public function __construct(public Account $account)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $suspended = $this->account->status === 'suspended';

        return (new MailMessage)
            ->error()
            ->subject($suspended
                ? 'Your PioDeploy subscription is suspended — action needed'
                : 'Reminder: your PioDeploy payment is still outstanding')
            ->greeting($suspended ? 'Subscription suspended' : 'Payment still outstanding')
            ->line($suspended
                ? "Payment for the {$this->account->plan?->name} plan could not be collected, and the subscription is now suspended."
                : "We still couldn't collect payment for the {$this->account->plan?->name} plan.")
            ->line('Updating your payment method takes a minute and restores everything automatically.')
            ->action('Update payment method', route('billing.subscription'))
            ->line('Need help, or want to change plans instead? Just reply to this email.');
    }
}
