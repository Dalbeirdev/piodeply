<?php

namespace App\Repositories\Contracts;

use App\Models\Package;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface PackageRepositoryInterface extends RepositoryInterface
{
    public function searchPaginated(
        string $search = '',
        ?int $categoryId = null,
        ?string $installerType = null,
        ?bool $activeOnly = null,
        bool $withTrashed = false,
        ?int $visibleToClientId = null,
        int $perPage = 15,
    ): LengthAwarePaginator;

    public function restore(Package $package): bool;
}
