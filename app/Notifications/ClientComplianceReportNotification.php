<?php

namespace App\Notifications;

use App\Models\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The monthly compliance report, PDF attached — sent to a client's portal
 * users when the client is opted in.
 */
class ClientComplianceReportNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Client $client,
        public string $pdfContent,
        public string $filename,
        public string $company,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->client->company_name} — monthly compliance report")
            ->greeting('Your compliance report')
            ->line("Attached is the monthly compliance report for {$this->client->company_name}: fleet status, policy compliance and deployment activity for the last 30 days.")
            ->line('You can also view live status any time in your portal.')
            ->action('Open your portal', url('/dashboard'))
            ->salutation("— {$this->company}")
            ->attachData($this->pdfContent, $this->filename, ['mime' => 'application/pdf']);
    }
}
