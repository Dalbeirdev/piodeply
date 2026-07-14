<?php

use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\AgentJobController;
use App\Http\Middleware\AuthenticateAgent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

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
