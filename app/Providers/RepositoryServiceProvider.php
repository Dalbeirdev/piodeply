<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Binds repository contracts to their Eloquent implementations.
 * Entity repositories are registered here as they are introduced,
 * e.g.:
 *
 *     ClientRepositoryInterface::class => EloquentClientRepository::class,
 */
class RepositoryServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        // Populated per phase; no business entities exist yet (Phase 1).
    ];
}
