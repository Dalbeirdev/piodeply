<?php

namespace App\Repositories\Eloquent;

use App\Models\Project;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EloquentProjectRepository extends BaseRepository implements ProjectRepositoryInterface
{
    public function __construct(Project $model)
    {
        parent::__construct($model);
    }

    public function searchPaginated(
        string $search = '',
        ?int $clientId = null,
        ?string $status = null,
        bool $withTrashed = false,
        int $perPage = 15,
        ?array $allowedProjectIds = null,
    ): LengthAwarePaginator {
        return $this->query()
            ->with('client')
            ->when($withTrashed, fn ($q) => $q->withTrashed())
            ->when($search !== '', fn ($q) => $q->search($search))
            ->when($allowedProjectIds !== null, fn ($q) => $q->whereIn('projects.id', $allowedProjectIds))
            ->when($clientId !== null, fn ($q) => $q->where('client_id', $clientId))
            ->when($status !== null && $status !== '', fn ($q) => $q->where('status', $status))
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function restore(Project $project): bool
    {
        return (bool) $project->restore();
    }
}
