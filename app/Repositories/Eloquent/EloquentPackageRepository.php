<?php

namespace App\Repositories\Eloquent;

use App\Models\Package;
use App\Repositories\Contracts\PackageRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EloquentPackageRepository extends BaseRepository implements PackageRepositoryInterface
{
    public function __construct(Package $model)
    {
        parent::__construct($model);
    }

    public function searchPaginated(
        string $search = '',
        ?int $categoryId = null,
        ?string $installerType = null,
        ?bool $activeOnly = null,
        bool $withTrashed = false,
        int $perPage = 15,
    ): LengthAwarePaginator {
        return $this->query()
            ->with(['category', 'latestVersion'])
            ->withCount('versions')
            ->when($withTrashed, fn ($q) => $q->withTrashed())
            ->when($search !== '', fn ($q) => $q->search($search))
            ->when($categoryId !== null, fn ($q) => $q->where('package_category_id', $categoryId))
            ->when($installerType !== null && $installerType !== '', fn ($q) => $q->where('installer_type', $installerType))
            ->when($activeOnly === true, fn ($q) => $q->active())
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function restore(Package $package): bool
    {
        return (bool) $package->restore();
    }
}
