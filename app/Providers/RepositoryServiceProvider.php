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
        \App\Repositories\Contracts\ClientRepositoryInterface::class => \App\Repositories\Eloquent\EloquentClientRepository::class,
        \App\Repositories\Contracts\ProjectRepositoryInterface::class => \App\Repositories\Eloquent\EloquentProjectRepository::class,
        \App\Repositories\Contracts\ComputerRepositoryInterface::class => \App\Repositories\Eloquent\EloquentComputerRepository::class,
    ];
}
