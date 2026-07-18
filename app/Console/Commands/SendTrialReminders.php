<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Notifications\TrialEndingNotification;
use Illuminate\Console\Command;

/**
 * Emails the billing contact when a trial is within three days of ending, once
 * per trial. Runs daily from the scheduler. The actual charge at trial end is
 * driven by Stripe; this is only the heads-up.
 */
class SendTrialReminders extends Command
{
    protected $signature = 'billing:trial-reminders {--days=3 : Remind when the trial ends within this many days}';

    protected $description = 'Email a reminder before each trial ends';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->addDays($days);

        $accounts = Account::query()
            ->where('status', 'trialing')
            ->whereNotNull('trial_ends_at')
            ->whereNull('trial_reminder_sent_at')
            ->where('trial_ends_at', '>', now())   // not already ended
            ->where('trial_ends_at', '<=', $cutoff) // within the window
            ->get();

        $sent = 0;
        foreach ($accounts as $account) {
            $contact = $account->billingContact();
            if ($contact === null) {
                continue;
            }

            $daysLeft = max(1, (int) ceil(now()->diffInDays($account->trial_ends_at, false)));
            $contact->notify(new TrialEndingNotification($account, $daysLeft));

            $account->forceFill(['trial_reminder_sent_at' => now()])->save();
            $sent++;
        }

        $this->info("Sent {$sent} trial reminder(s).");

        return self::SUCCESS;
    }
}
