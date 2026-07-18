<?php

namespace App\Notifications;

use App\Models\Account;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TrialStartedNotification extends Notification
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
        $plan = $this->account->plan;
        $ends = $this->account->trial_ends_at;

        return (new MailMessage)
            ->subject('Your PioDeploy trial has started')
            ->greeting('Welcome aboard!')
            ->line("Your 14-day free trial of the {$plan?->name} plan is now active.")
            ->when($ends, fn ($m) => $m->line("Your trial runs until {$ends->toFormattedDayDateString()}. We'll charge your card automatically then — cancel anytime before to avoid billing."))
            ->action('Open your dashboard', route('dashboard'))
            ->line('Thanks for trying PioDeploy.');
    }
}
