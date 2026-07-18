<?php

namespace App\Notifications;

use App\Models\Account;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

class PaymentFailedNotification extends Notification
{
    use Queueable;

    public function __construct(public Account $account, public ?Carbon $retryAt)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->error()
            ->subject('Your PioDeploy payment failed')
            ->greeting('Payment problem')
            ->line("We couldn't charge your card for the {$this->account->plan?->name} plan.");

        if ($this->retryAt) {
            $mail->line("We'll try again on {$this->retryAt->toFormattedDayDateString()}. Please update your card before then to avoid interruption.");
        } else {
            $mail->line('We were unable to collect payment after several attempts, so the subscription has been suspended. Update your card to reactivate.');
        }

        return $mail
            ->action('Update payment method', route('billing.subscription'))
            ->line('Your machines keep running during the grace period.');
    }
}
