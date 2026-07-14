<?php

namespace App\Repositories\Contracts;

use App\Models\Computer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ComputerRepositoryInterface extends RepositoryInterface
{
    public function searchPaginated(
        string $search = '',
        ?int $projectId = null,
        ?int $clientId = null,
        ?bool $online = null,
        bool $withTrashed = false,
        int $perPage = 15,
    ): LengthAwarePaginator;

    public function findByAgentUuid(string $agentUuid, bool $withTrashed = false): ?Computer;

    public function restore(Computer $computer): bool;
}
