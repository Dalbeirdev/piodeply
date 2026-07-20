<?php

namespace App\Console\Commands;

use App\Enums\ClientStatus;
use App\Models\Client;
use App\Services\ClientSubscriptionService;
use App\Services\NotificationService;
use App\Services\SettingsService;
use Illuminate\Console\Command;

/**
 * Per-CLIENT dunning for the SaaS layer (the sibling of
 * billing:dunning-reminders, which covers this install's own Account).
 *
 * The first "your payment failed" email goes out from the webhook the
 * moment Stripe reports the failure; this command owns everything after:
 * a paced reminder every few days with the real countdown, and — when the
 * grace window closes — suspension. Suspension here is deliberately a
 * status + emails, never fleet breakage: agents keep running, deployments
 * keep working. Cutting a client's machines off over a card decline is an
 * operator's call, not an automation's.
 */
class SendClientDunning extends Command
{
    public const REMIND_EVERY_DAYS = 3;

    protected $signature = 'billing:client-dunning';

    protected $description = 'Remind past-due clients (paced) and suspend them when the grace window closes';

    public function handle(
        ClientSubscriptionService $subscriptions,
        NotificationService $notifications,
        SettingsService $settings,
    ): int {
        $graceDays = max(3, (int) $settings->get('billing.client_grace_days', '14'));

        $pastDue = Client::query()
            ->where('subscription_status', 'past_due')
            ->whereNotNull('subscription_past_due_since')
            ->get();

        $reminded = $suspended = 0;

        foreach ($pastDue as $client) {
            $daysOverdue = (int) $client->subscription_past_due_since->diffInDays(now());
            $daysLeft = max(0, $graceDays - $daysOverdue);

            if ($daysOverdue >= $graceDays && $client->billing_suspended_at === null) {
                $client->forceFill([
                    'status'               => ClientStatus::Suspended,
                    'billing_suspended_at' => now(),
                ])->saveQuietly();

                $subscriptions->sendDunningMail($client, 'suspended');
                $notifications->notify(
                    'billing.client_suspended',
                    "{$client->company_name} suspended after {$graceDays} days past due",
                    ['client' => $client->company_name, 'email' => $client->billing_email ?? $client->email]
                );

                activity('billing')
                    ->performedOn($client)
                    ->withProperties(['days_overdue' => $daysOverdue])
                    ->log('client_suspended_for_nonpayment');

                $suspended++;

                continue;
            }

            // Between failure and grace end: one reminder every few days,
            // carrying the actual countdown. Already-suspended clients get
            // nothing more — the suspension mail said everything.
            if ($client->billing_suspended_at === null
                && $client->dunning_last_sent_at !== null
                && $client->dunning_last_sent_at->lte(now()->subDays(self::REMIND_EVERY_DAYS))) {
                $subscriptions->sendDunningMail($client, 'reminder', $daysLeft);
                $client->forceFill([
                    'dunning_stage'        => $client->dunning_stage + 1,
                    'dunning_last_sent_at' => now(),
                ])->saveQuietly();
                $reminded++;
            }
        }

        $this->info("Client dunning: {$reminded} reminded, {$suspended} suspended.");

        return self::SUCCESS;
    }
}
