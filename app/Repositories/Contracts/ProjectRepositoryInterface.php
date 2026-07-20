<?php

namespace App\Repositories\Contracts;

use App\Models\Project;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ProjectRepositoryInterface extends RepositoryInterface
{
    public function searchPaginated(
        string $search = '',
        ?int $clientId = null,
        ?string $status = null,
        bool $withTrashed = false,
        int $perPage = 15,
        ?array $allowedProjectIds = null,
    ): LengthAwarePaginator;

    public function restore(Project $project): bool;
}
