<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/dashboard', function () {
        return view('dashboard');
    })->name('dashboard');

    Route::get('/admin/users', \App\Livewire\Admin\ManageUsers::class)
        ->middleware('permission:users.view')
        ->name('admin.users');

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
});
