<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Super-Admin-only "login as" support. The original user id is kept in
 * the session; leaving restores it. Every start/stop is audit-logged.
 */
class ImpersonationController extends Controller
{
    public const SESSION_KEY = 'impersonator_id';

    public function start(User $user): RedirectResponse
    {
        $current = Auth::user();

        abort_unless($current->hasRole(Role::SuperAdmin->value), 403);
        abort_if(session()->has(self::SESSION_KEY), 403, 'Already impersonating — leave first.');
        abort_if($user->is($current), 403, 'You cannot impersonate yourself.');
        abort_if($user->hasRole(Role::SuperAdmin->value), 403, 'Super Admins cannot be impersonated.');

        activity('auth')
            ->causedBy($current)
            ->performedOn($user)
            ->withProperties(['ip' => request()->ip()])
            ->log('impersonation_started');

        session()->put(self::SESSION_KEY, $current->id);
        $this->loginAs($user);

        return redirect()->route('dashboard');
    }

    public function leave(): RedirectResponse
    {
        $impersonatorId = session()->pull(self::SESSION_KEY);
        abort_if($impersonatorId === null, 403, 'Not impersonating.');

        $impersonator = User::find($impersonatorId);
        abort_if($impersonator === null || ! $impersonator->hasRole(Role::SuperAdmin->value), 403);

        activity('auth')
            ->causedBy($impersonator)
            ->performedOn(Auth::user())
            ->log('impersonation_ended');

        $this->loginAs($impersonator);

        return redirect()->route('admin.users');
    }

    /**
     * Switch the session to another user. AuthenticateSession keys the
     * session to the user's password hash — without refreshing those
     * keys, the very next request logs the session out.
     */
    private function loginAs(User $user): void
    {
        Auth::guard('web')->login($user);

        // AuthenticateSession re-stores the password hash after the response
        // from the *request-memoised* guard user — point every guard at the
        // new identity or the next request gets logged out on hash mismatch.
        Auth::guard('sanctum')->setUser($user);
        Auth::shouldUse('web');

        session()->put([
            'password_hash_web'     => $user->getAuthPassword(),
            'password_hash_sanctum' => $user->getAuthPassword(),
        ]);
    }
}
