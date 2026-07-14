<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;

/**
 * Writes authentication lifecycle events to the activity log
 * (spatie/laravel-activitylog). Registered in AppServiceProvider.
 */
class LogAuthenticationActivity
{
    public function handleLogin(Login $event): void
    {
        activity('auth')
            ->causedBy($event->user)
            ->withProperties($this->requestContext())
            ->log('login');
    }

    public function handleLogout(Logout $event): void
    {
        if ($event->user === null) {
            return;
        }

        activity('auth')
            ->causedBy($event->user)
            ->withProperties($this->requestContext())
            ->log('logout');
    }

    public function handleFailed(Failed $event): void
    {
        activity('auth')
            ->withProperties($this->requestContext() + [
                'email' => $event->credentials['email'] ?? null,
            ])
            ->log('login_failed');
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        activity('auth')
            ->causedBy($event->user)
            ->withProperties($this->requestContext())
            ->log('password_reset');
    }

    private function requestContext(): array
    {
        return [
            'ip'         => request()->ip(),
            'user_agent' => request()->userAgent(),
        ];
    }
}
