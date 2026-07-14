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

        $this->check(
            ! $production || config('mail.default') !== 'log',
            'Mailer is configured in production',
            'MAIL_MAILER=log means notification emails go to a file, not people'
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
