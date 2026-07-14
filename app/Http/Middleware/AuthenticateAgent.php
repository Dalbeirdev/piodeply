<?php

namespace App\Http\Middleware;

use App\Enums\ProjectStatus;
use App\Models\Project;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates agent requests via the project's API key (X-Api-Key).
 * Only the SHA-256 hash is stored server-side; lookup is by hash.
 * The resolved project is attached to the request for controllers.
 */
class AuthenticateAgent
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) $request->header('X-Api-Key', '');

        $project = $key === '' ? null : Project::findByApiKey($key);

        if ($project === null) {
            return response()->json(['message' => 'Invalid API key.'], 401);
        }

        if ($project->status !== ProjectStatus::Active) {
            return response()->json(['message' => 'Project is archived.'], 403);
        }

        $request->attributes->set('project', $project);

        return $next($request);
    }
}
