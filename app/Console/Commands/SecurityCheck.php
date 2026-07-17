<?php

namespace App\Console\Commands;

use App\Enums\Role as RoleEnum;
use App\Models\Project;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Deployment-configuration audit: quick pass/warn checklist an operator
 * runs before (and after) putting an instance in front of customers.
 * Exits non-zero when anything warns, so it can gate a deploy script.
 */
class SecurityCheck extends Command
{
    protected $signature = 'security:check';

    protected $description = 'Audit runtime configuration and account hygiene';

    private int $warnings = 0;

    /**
     * Hosts nobody owns. Shipped in .env.production.example for an operator to
     * replace, and passed straight through to production when they didn't.
     */
    // Substrings of the shipped example values only. 'smtp.host' was here and
    // matched smtp.HOSTinger.com — this instance's real provider — failing the
    // check on a correct config. Needles must be specific enough that no real
    // host contains one.
    private const PLACEHOLDER_HOSTS = ['yourprovider', 'example.com', 'example.org', 'changeme', 'smtp.example'];

    public function handle(): int
    {
        $production = app()->environment('production');

        $this->check(
            ! $production || ! config('app.debug'),
            'APP_DEBUG is off in production',
            'APP_DEBUG=true in production leaks stack traces and secrets'
        );

        $this->check(
            ! $production || str_starts_with((string) config('app.url'), 'https://'),
            'APP_URL uses https in production',
            'APP_URL is not https — agents and browsers would send credentials in clear text'
        );

        $this->check(
            ! $production || config('session.secure'),
            'Session cookies are secure in production',
            'SESSION_SECURE_COOKIE should be true when serving over https'
        );

        $this->check(
            config('session.http_only') === true,
            'Session cookies are HttpOnly',
            'session.http_only must stay true'
        );

        $mailProblem = $production ? $this->mailerProblem() : null;
        $this->check(
            $mailProblem === null,
            'Mailer is configured in production',
            $mailProblem ?? ''
        );

        $superAdmins = User::role(RoleEnum::SuperAdmin->value)->count();
        $this->check($superAdmins >= 1, 'At least one Super Admin exists', 'No Super Admin — nobody can administer roles');
        $this->check($superAdmins <= 3, 'Super Admin count is small (≤3)', "{$superAdmins} Super Admins — least privilege suggests fewer");

        $demoAccounts = User::where('email', 'like', '%@piodeploy.local')->count();
        $this->check(
            ! $production || $demoAccounts === 0,
            'No demo accounts in production',
            "{$demoAccounts} demo account(s) (@piodeploy.local) present"
        );

        if ($production) {
            $weak = User::role(RoleEnum::SuperAdmin->value)->get()
                ->filter(fn (User $user) => Hash::check('admin@123', $user->password))
                ->count();
            $this->check($weak === 0, 'No Super Admin uses the well-known local password', "{$weak} Super Admin(s) still use the demo password");
        }

        $staleKeys = Project::where(function ($q) {
            $q->whereNull('api_key_rotated_at')
                ->orWhere('api_key_rotated_at', '<', now()->subYear());
        })->where('created_at', '<', now()->subYear())->count();
        $this->check(
            $staleKeys === 0,
            'No project API keys older than a year',
            "{$staleKeys} project key(s) have not been rotated in over a year"
        );

        $unbound = User::role(RoleEnum::Client->value)->whereNull('client_id')->count();
        $this->check(
            $unbound === 0,
            'Every Client-role account is bound to a client',
            "{$unbound} Client-role account(s) have no client binding (they fail closed, but fix the data)"
        );

        $this->newLine();
        if ($this->warnings === 0) {
            $this->info('Security check passed — no warnings.');

            return self::SUCCESS;
        }

        $this->warn("Security check finished with {$this->warnings} warning(s).");

        return self::FAILURE;
    }

    /**
     * Why mail would fail, if it would. "Not the log driver" was the whole
     * test before, so MAIL_MAILER=smtp pointed at the example host passed
     * cleanly and then failed at the first send — the check reported on the
     * one part that happened to be right, and told the operator not to look.
     */
    private function mailerProblem(): ?string
    {
        if (config('mail.default') === 'log') {
            return 'MAIL_MAILER=log means notification emails go to a file, not people';
        }

        if (trim((string) config('mail.from.address')) === '') {
            return 'MAIL_FROM_ADDRESS is empty — messages will be rejected by most providers';
        }

        // Only SMTP exposes a host we can sanity-check; API mailers carry
        // their own credentials and fail loudly on their own.
        if (config('mail.default') !== 'smtp') {
            return null;
        }

        $host = trim((string) config('mail.mailers.smtp.host'));

        if ($host === '') {
            return 'MAIL_HOST is empty — nothing can be sent';
        }

        foreach (self::PLACEHOLDER_HOSTS as $placeholder) {
            if (str_contains(mb_strtolower($host), $placeholder)) {
                return "MAIL_HOST is still the example value ({$host}) — every notification will fail to send";
            }
        }

        return null;
    }

    private function check(bool $passed, string $okMessage, string $warnMessage): void
    {
        if ($passed) {
            $this->line("  <fg=green>✓</> {$okMessage}");
        } else {
            $this->warnings++;
            $this->line("  <fg=red>✗</> {$warnMessage}");
        }
    }
}
