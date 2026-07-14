<?php

use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\AgentJobController;
use App\Http\Middleware\AuthenticateAgent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

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
    });

Route::prefix('v1/agent')
    ->middleware([AuthenticateAgent::class, 'throttle:agent'])
    ->group(function () {
        Route::post('/register', [AgentController::class, 'register']);
        Route::post('/heartbeat', [AgentController::class, 'heartbeat']);
        Route::post('/inventory', [AgentController::class, 'inventory']);
        Route::post('/software', [AgentController::class, 'software']);
        Route::post('/jobs', [AgentJobController::class, 'index']); // POST: carries agent_uuid + claims
        Route::post('/jobs/{job}/result', [AgentJobController::class, 'result']);
    });
