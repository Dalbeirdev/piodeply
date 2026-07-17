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
        // Compact pagination everywhere (Previous / Next + page summary).
        \Illuminate\Pagination\Paginator::defaultView('pagination.compact');
        \Illuminate\Pagination\Paginator::defaultSimpleView('pagination.compact');

        // Shared branding for the public marketing site.
        \Illuminate\Support\Facades\View::composer('marketing.*', function ($view) {
            $content = app(\App\Services\SiteContentService::class);
            $company = app(\App\Services\SettingsService::class)->get('branding.company_name');

            $view->with([
                'company' => $company,
                // The house that built the product. When the setting is just
                // the product's own name, sentences like "PioDeploy started
                // inside PioDeploy" come out as nonsense — so callers get null
                // and phrase it without naming anyone.
                'house'   => $company !== config('app.name') ? $company : null,
                'email'   => $content->get('contact.email') ?: (config('mail.from.address') ?: 'hello@piodeploy.app'),
                'content' => $content,
                'billing' => app(\App\Services\BillingService::class),
            ]);
        });

        // Agent API: generous but bounded — one fleet key shouldn't starve others.
        \Illuminate\Support\Facades\RateLimiter::for('agent', function (\Illuminate\Http\Request $request) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(240)
                ->by(sha1((string) $request->header('X-Api-Key', $request->ip())));
        });

        // Integration API: per token-owner, tighter than the agent lane.
        \Illuminate\Support\Facades\RateLimiter::for('integration', function (\Illuminate\Http\Request $request) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(60)
                ->by($request->user()?->id ?? $request->ip());
        });

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
