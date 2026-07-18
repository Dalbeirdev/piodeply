<?php

use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\AgentJobController;
use App\Http\Middleware\AuthenticateAgent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Public pricing API — plans catalogue + calculator. No customer data is
// exposed, so it needs no auth, but it is rate limited.
Route::prefix('v1/billing')
    ->middleware('throttle:60,1')
    ->group(function () {
        Route::get('/plans', [\App\Http\Controllers\Api\PricingController::class, 'plans']);
        Route::post('/pricing/calculate', [\App\Http\Controllers\Api\PricingController::class, 'calculate']);
    });

// Integration API for external tools (RMM/PSA/scripts). Authenticate with
// a personal access token from /user/api-tokens: Authorization: Bearer <token>.
Route::prefix('v1')
    ->middleware(['auth:sanctum', 'throttle:integration'])
    ->group(function () {
        Route::get('/clients', [\App\Http\Controllers\Api\V1\FleetController::class, 'clients']);
        Route::get('/projects', [\App\Http\Controllers\Api\V1\FleetController::class, 'projects']);
        Route::get('/computers', [\App\Http\Controllers\Api\V1\FleetController::class, 'computers']);
        Route::get('/computers/{computer}', [\App\Http\Controllers\Api\V1\FleetController::class, 'computer']);
        Route::get('/packages', [\App\Http\Controllers\Api\V1\FleetController::class, 'packages']);

        Route::get('/deployments', [\App\Http\Controllers\Api\V1\DeploymentsController::class, 'index']);
        Route::post('/deployments', [\App\Http\Controllers\Api\V1\DeploymentsController::class, 'store']);
        Route::get('/deployments/{job}', [\App\Http\Controllers\Api\V1\DeploymentsController::class, 'show']);

        Route::get('/policies', [\App\Http\Controllers\Api\V1\PoliciesController::class, 'index']);
        Route::get('/policies/{policy}', [\App\Http\Controllers\Api\V1\PoliciesController::class, 'show']);

        Route::get('/browser-policies', [\App\Http\Controllers\Api\V1\BrowserPoliciesController::class, 'index']);
        Route::post('/browser-policies', [\App\Http\Controllers\Api\V1\BrowserPoliciesController::class, 'store']);
        Route::get('/browser-policies/{policy}', [\App\Http\Controllers\Api\V1\BrowserPoliciesController::class, 'show']);
        Route::put('/browser-policies/{policy}', [\App\Http\Controllers\Api\V1\BrowserPoliciesController::class, 'update']);
        Route::delete('/browser-policies/{policy}', [\App\Http\Controllers\Api\V1\BrowserPoliciesController::class, 'destroy']);
        Route::get('/computers/{computer}/browser-policies', [\App\Http\Controllers\Api\V1\BrowserPoliciesController::class, 'deviceResults']);
    });

Route::prefix('v1/agent')
    ->middleware([AuthenticateAgent::class, 'throttle:agent'])
    ->group(function () {
        Route::post('/register', [AgentController::class, 'register']);
        Route::post('/heartbeat', [AgentController::class, 'heartbeat']);
        Route::get('/bundle', [AgentController::class, 'bundle'])->name('agent.bundle');
        Route::post('/inventory', [AgentController::class, 'inventory']);
        Route::post('/software', [AgentController::class, 'software']);
        Route::post('/jobs', [AgentJobController::class, 'index']); // POST: carries agent_uuid + claims
        Route::post('/jobs/{job}/result', [AgentJobController::class, 'result']);
        Route::post('/browser-policies', [\App\Http\Controllers\Api\AgentBrowserPolicyController::class, 'index']);
        Route::post('/browser-policies/results', [\App\Http\Controllers\Api\AgentBrowserPolicyController::class, 'results']);
    });
