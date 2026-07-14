<?php

namespace App\Services;

use App\Models\NotificationChannel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Fans an event out to every subscribed channel. Delivery is synchronous
 * (no queue worker is assumed on XAMPP) and fault-isolated: a dead
 * webhook or SMTP hiccup is recorded on the channel and never breaks the
 * caller — notifications ride along agent API requests.
 */
class NotificationService
{
    private const WEBHOOK_TIMEOUT_SECONDS = 5;

    /**
     * @param array<string, scalar|null> $data Structured details, included
     *        in webhook payloads and rendered as lines in emails.
     */
    public function notify(string $event, string $title, array $data = []): int
    {
        $channels = NotificationChannel::subscribedTo($event)->get();

        $sent = 0;
        foreach ($channels as $channel) {
            if ($this->deliver($channel, $event, $title, $data)) {
                $sent++;
            }
        }

        return $sent;
    }

    public function deliver(NotificationChannel $channel, string $event, string $title, array $data = []): bool
    {
        try {
            match ($channel->type) {
                NotificationChannel::TYPE_EMAIL => $this->sendEmail($channel, $title, $data),
                NotificationChannel::TYPE_WEBHOOK => $this->sendWebhook($channel, $event, $title, $data),
                default => throw new \RuntimeException("Unknown channel type '{$channel->type}'."),
            };

            $channel->forceFill(['last_sent_at' => now(), 'last_error' => null])->saveQuietly();

            return true;
        } catch (\Throwable $e) {
            Log::warning("Notification delivery failed for channel '{$channel->name}': {$e->getMessage()}");
            $channel->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 500)])->saveQuietly();

            return false;
        }
    }

    private function sendEmail(NotificationChannel $channel, string $title, array $data): void
    {
        $body = $title . "\n\n" . $this->lines($data) . "\n\n— PioDeploy";

        Mail::to($channel->destination)->send(new \App\Mail\ChannelNotification($title, $body));
    }

    private function sendWebhook(NotificationChannel $channel, string $event, string $title, array $data): void
    {
        // "text" makes the payload drop-in compatible with Slack/Discord
        // incoming webhooks; structured fields serve everything else.
        $response = Http::timeout(self::WEBHOOK_TIMEOUT_SECONDS)
            ->post($channel->destination, [
                'event'   => $event,
                'title'   => $title,
                'text'    => trim("*{$title}*\n" . $this->lines($data)),
                'data'    => $data,
                'sent_at' => now()->toIso8601String(),
            ]);

        if ($response->failed()) {
            throw new \RuntimeException("Webhook returned HTTP {$response->status()}.");
        }
    }

    private function lines(array $data): string
    {
        return collect($data)
            ->map(fn ($value, $key) => ucfirst(str_replace('_', ' ', $key)) . ': ' . ($value ?? '—'))
            ->join("\n");
    }
}
