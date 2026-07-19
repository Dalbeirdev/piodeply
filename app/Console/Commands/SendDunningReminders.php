<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Notifications\DunningReminderNotification;
use Illuminate\Console\Command;

/**
 * Follow-up nudges for unpaid subscriptions. Stripe's retry cycle already
 * triggers an email per failed attempt (via the webhook); this covers the
 * quiet stretch after Stripe gives up — one reminder every REMIND_EVERY_DAYS
 * while the account stays past due, in grace, or suspended.
 */
class SendDunningReminders extends Command
{
    public const REMIND_EVERY_DAYS = 3;

    protected $signature = 'billing:dunning-reminders';

    protected $description = 'Email billing contacts of unpaid accounts (paced, deduplicated)';

    public function handle(): int
    {
        $due = Account::query()
            ->whereIn('status', ['past_due', 'grace', 'suspended'])
            ->where(fn ($q) => $q
                ->whereNull('dunning_notified_at')
                ->orWhere('dunning_notified_at', '<=', now()->subDays(self::REMIND_EVERY_DAYS)))
            ->get();

        $sent = 0;

        foreach ($due as $account) {
            $contact = $account->billingContact();

            if ($contact === null) {
                continue; // nobody to tell — surfaced by the in-app banner instead
            }

            $contact->notify(new DunningReminderNotification($account));
            $account->forceFill(['dunning_notified_at' => now()])->save();
            $sent++;
        }

        $this->info("Dunning reminders sent: {$sent}.");

        return self::SUCCESS;
    }
}
