<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Public agent download (the token is the secret; keys are never embedded).
Route::middleware('throttle:30,1')->group(function () {
    Route::get('/download/agent/{token}', [\App\Http\Controllers\AgentDownloadController::class, 'script'])
        ->name('agent.download');
    Route::get('/download/agent/{token}/binary', [\App\Http\Controllers\AgentDownloadController::class, 'binary'])
        ->name('agent.download.binary');
});

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/dashboard', \App\Livewire\Dashboard::class)->name('dashboard');

    Route::get('/admin/users', \App\Livewire\Admin\ManageUsers::class)
        ->middleware('permission:users.view')
        ->name('admin.users');

    Route::get('/admin/roles', \App\Livewire\Admin\ManageRoles::class)
        ->middleware('permission:roles.manage')
        ->name('admin.roles');

    Route::get('/admin/notifications', \App\Livewire\Admin\NotificationChannels::class)
        ->middleware('permission:settings.manage')
        ->name('admin.notifications');

    Route::post('/admin/impersonate/{user}', [\App\Http\Controllers\ImpersonationController::class, 'start'])
        ->middleware('role:Super Admin')
        ->name('impersonate.start');
    Route::post('/impersonate/leave', [\App\Http\Controllers\ImpersonationController::class, 'leave'])
        ->name('impersonate.leave');

    Route::middleware('permission:clients.view')->group(function () {
        Route::get('/clients', \App\Livewire\Clients\ClientsIndex::class)->name('clients.index');
        Route::get('/clients/create', \App\Livewire\Clients\ClientForm::class)->name('clients.create');
        Route::get('/clients/{client}/edit', \App\Livewire\Clients\ClientForm::class)->name('clients.edit');
    });

    Route::middleware('permission:projects.view')->group(function () {
        Route::get('/projects', \App\Livewire\Projects\ProjectsIndex::class)->name('projects.index');
        Route::get('/projects/create', \App\Livewire\Projects\ProjectForm::class)->name('projects.create');
        Route::get('/projects/{project}/edit', \App\Livewire\Projects\ProjectForm::class)->name('projects.edit');
    });

    Route::middleware('permission:computers.view')->group(function () {
        Route::get('/computers', \App\Livewire\Computers\ComputersIndex::class)->name('computers.index');
        Route::get('/computers/{computer}', \App\Livewire\Computers\ComputerShow::class)->name('computers.show');
        Route::get('/computers/{computer}/edit', \App\Livewire\Computers\ComputerEdit::class)->name('computers.edit');
    });

    Route::middleware('permission:packages.view')->group(function () {
        Route::get('/packages', \App\Livewire\Packages\PackagesIndex::class)->name('packages.index');
        Route::get('/packages/create', \App\Livewire\Packages\PackageForm::class)->name('packages.create');
        Route::get('/packages/{package}', \App\Livewire\Packages\PackageShow::class)->name('packages.show');
        Route::get('/packages/{package}/edit', \App\Livewire\Packages\PackageForm::class)->name('packages.edit');
    });

    Route::middleware('permission:deployments.view')->group(function () {
        Route::get('/deployments', \App\Livewire\Deployments\DeploymentsIndex::class)->name('deployments.index');
    });

    Route::middleware('permission:reports.view')->group(function () {
        Route::get('/reports', \App\Livewire\Reports\ReportsIndex::class)->name('reports.index');
        Route::get('/reports/compliance', \App\Livewire\Reports\ComplianceReport::class)->name('reports.compliance');
        Route::get('/reports/deployments', \App\Livewire\Reports\DeploymentsReport::class)->name('reports.deployments');
        Route::get('/reports/computers', \App\Livewire\Reports\ComputersReport::class)->name('reports.computers');
    });

    Route::middleware('permission:policies.view')->group(function () {
        Route::get('/policies', \App\Livewire\Policies\PoliciesIndex::class)->name('policies.index');
        Route::get('/policies/create', \App\Livewire\Policies\PolicyForm::class)->name('policies.create');
        Route::get('/policies/{policy}', \App\Livewire\Policies\PolicyShow::class)->name('policies.show');
        Route::get('/policies/{policy}/edit', \App\Livewire\Policies\PolicyForm::class)->name('policies.edit');
    });
});
