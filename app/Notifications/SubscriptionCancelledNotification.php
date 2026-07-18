<?php

namespace App\Notifications;

use App\Models\Account;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Sent when a subscription is cancelled. If access continues to a period end,
 * the email says when — and how to come back before then.
 */
class SubscriptionCancelledNotification extends Notification
{
    use Queueable;

    public function __construct(public Account $account, public ?Carbon $endsAt)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your PioDeploy subscription is cancelled')
            ->greeting('Subscription cancelled')
            ->line("Your {$this->account->plan?->name} subscription has been cancelled.")
            ->when($this->endsAt, fn ($m) => $m->line("You keep full access until {$this->endsAt->toFormattedDayDateString()}. Resume before then to continue without interruption."))
            ->action('Resume subscription', route('billing.subscription'))
            ->line('We\'re sorry to see you go — your data stays put if you come back.');
    }
}
