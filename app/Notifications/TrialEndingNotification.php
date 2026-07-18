<?php

namespace App\Notifications;

use App\Models\Account;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TrialEndingNotification extends Notification
{
    use Queueable;

    public function __construct(public Account $account, public int $daysLeft)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $plan = $this->account->plan;
        $amountCents = $this->account->billing_interval === 'year'
            ? $plan?->yearly_price_cents
            : $plan?->monthly_price_cents;
        $amount = $amountCents ? '$' . number_format($amountCents / 100, 2) : null;

        return (new MailMessage)
            ->subject("Your PioDeploy trial ends in {$this->daysLeft} " . str('day')->plural($this->daysLeft))
            ->greeting('A quick heads-up')
            ->line("Your free trial of the {$plan?->name} plan ends in {$this->daysLeft} " . str('day')->plural($this->daysLeft) . '.')
            ->when($amount, fn ($m) => $m->line("Unless you cancel, your card will be charged {$amount} for the {$this->account->billing_interval}ly plan and your subscription will continue uninterrupted."))
            ->action('Manage your subscription', route('billing.subscription'))
            ->line('No action is needed if you want to keep using PioDeploy.');
    }
}
