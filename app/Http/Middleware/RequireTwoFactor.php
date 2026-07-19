<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Services\SettingsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optional 2FA enforcement, driven by the security.require_two_factor
 * setting: 'off' (default), 'staff' (everyone except client-portal users)
 * or 'all'. A signed-in user without confirmed 2FA is routed to their
 * profile to enrol; the profile, password-confirmation, 2FA and logout
 * endpoints stay reachable so enrolment itself can never dead-lock.
 */
class RequireTwoFactor
{
    /** Path prefixes that must stay reachable while unenrolled. */
    private const ALLOWED_PREFIXES = [
        'user/profile',
        'user/two-factor',
        'user/confirm-password',
        'user/confirmed-password-status',
        'user/password',
        'logout',
        'livewire', // profile page components (incl. the 2FA form) post here
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $mode = (string) app(SettingsService::class)->get('security.require_two_factor');

        if (! in_array($mode, ['staff', 'all'], true)) {
            return $next($request);
        }

        if ($mode === 'staff' && $user->hasRole(Role::Client->value)) {
            return $next($request);
        }

        if ($user->two_factor_confirmed_at !== null) {
            return $next($request);
        }

        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if ($request->is($prefix) || $request->is($prefix.'/*')) {
                return $next($request);
            }
        }

        return redirect('/user/profile')->with(
            'two_factor_required',
            'Your administrator requires two-factor authentication — set it up below to continue.',
        );
    }
}
