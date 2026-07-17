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
        $response = Http::timeout(self::WEBHOOK_TIMEOUT_SECONDS)
            ->post($channel->destination, $this->webhookPayload($event, $title, $data));

        if ($response->failed()) {
            throw new \RuntimeException("Webhook returned HTTP {$response->status()}.");
        }
    }

    /**
     * One payload, three readers. Each platform ignores the keys it does not
     * know and renders the ones it does:
     *   - Teams reads the MessageCard (@type/sections) and shows a card with
     *     a coloured bar and a facts table. It does NOT render Slack's *bold*,
     *     so we never send Slack markdown.
     *   - Slack reads "text".
     *   - Discord reads "content".
     * The raw event and data ride along for anything scripted.
     *
     * @param array<string, scalar|null> $data
     * @return array<string, mixed>
     */
    private function webhookPayload(string $event, string $title, array $data): array
    {
        [$emoji, $colour] = $this->accent($event);

        // Plain, no markdown: this renders acceptably everywhere, where
        // Slack's *bold* would show literal asterisks in Teams.
        $plain = trim("{$emoji} {$title}\n\n" . $this->lines($data));

        return [
            // Slack / generic
            'text'    => $plain,
            // Discord
            'content' => $plain,

            // Microsoft Teams (incoming-webhook MessageCard)
            '@type'      => 'MessageCard',
            '@context'   => 'https://schema.org/extensions',
            'themeColor' => $colour,
            'summary'    => $title,
            'title'      => "{$emoji} {$title}",
            'sections'   => [[
                'facts'    => collect($data)
                    ->map(fn ($value, $key) => [
                        'name'  => ucfirst(str_replace('_', ' ', $key)),
                        'value' => (string) ($value ?? '—'),
                    ])
                    ->values()
                    ->all(),
                'markdown' => false,
            ]],

            // Structured, for anything reading the raw body
            'event'   => $event,
            'data'    => $data,
            'sent_at' => now()->toIso8601String(),
        ];
    }

    /**
     * An emoji and a card colour per event, so a glance says how much it
     * matters. Colours are hex without the # that Teams expects.
     *
     * @return array{0: string, 1: string}
     */
    private function accent(string $event): array
    {
        return match ($event) {
            'job.failed'            => ['⚠️', 'D64545'],  // red — needs attention
            'agent.offline'         => ['🔌', 'F59E0B'],  // amber
            'browser_policy.failed' => ['🛡️', 'F59E0B'],  // amber
            'policy.drift'          => ['📋', '2563EB'],  // blue — informational
            'computer.registered'   => ['🟢', '22C55E'],  // green — good news
            'lead.received'         => ['✉️', '0F766E'],  // teal — a new enquiry
            default                 => ['🔔', '0F766E'],  // test and anything else
        };
    }

    private function lines(array $data): string
    {
        return collect($data)
            ->map(fn ($value, $key) => ucfirst(str_replace('_', ' ', $key)) . ': ' . ($value ?? '—'))
            ->join("\n");
    }
}
