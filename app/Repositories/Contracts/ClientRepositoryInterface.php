<?php

namespace App\Repositories\Contracts;

use App\Models\Client;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ClientRepositoryInterface extends RepositoryInterface
{
    /**
     * Search + filter + paginate for the index screen.
     */
    public function searchPaginated(
        string $search = '',
        ?string $status = null,
        bool $withTrashed = false,
        int $perPage = 15,
    ): LengthAwarePaginator;

    public function findByEmail(string $email, bool $withTrashed = false): ?Client;

    public function restore(Client $client): bool;
}
