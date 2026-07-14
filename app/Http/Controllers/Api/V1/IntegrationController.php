<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Base for the token API. Every endpoint checks two layers:
 * the token's ability (what this credential may do) and the owning
 * user's role permission (what this person may do) — a "read" token
 * issued by a Viewer cannot see more than the Viewer can.
 */
abstract class IntegrationController extends Controller
{
    protected function requireAbility(Request $request, string $ability, string $permission): void
    {
        abort_unless($request->user()->tokenCan($ability), 403, "This token lacks the '{$ability}' ability.");
        abort_unless($request->user()->can($permission), 403, 'Your account lacks the required permission.');
    }

    protected function tenantId(Request $request): ?int
    {
        return $request->user()->tenantClientId();
    }
}
