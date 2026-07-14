<?php

namespace App\Providers;

use App\Enums\Role;
use App\Listeners\LogAuthenticationActivity;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Super Admin passes every ability check, including future ones.
        Gate::before(function (User $user, string $ability) {
            return $user->hasRole(Role::SuperAdmin->value) ? true : null;
        });

        Event::listen(Login::class, [LogAuthenticationActivity::class, 'handleLogin']);
        Event::listen(Logout::class, [LogAuthenticationActivity::class, 'handleLogout']);
        Event::listen(Failed::class, [LogAuthenticationActivity::class, 'handleFailed']);
        Event::listen(PasswordReset::class, [LogAuthenticationActivity::class, 'handlePasswordReset']);
    }
}
