<?php

namespace App\Repositories\Eloquent;

use App\Models\Client;
use App\Repositories\Contracts\ClientRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EloquentClientRepository extends BaseRepository implements ClientRepositoryInterface
{
    public function __construct(Client $model)
    {
        parent::__construct($model);
    }

    public function searchPaginated(
        string $search = '',
        ?string $status = null,
        bool $withTrashed = false,
        int $perPage = 15,
    ): LengthAwarePaginator {
        return $this->query()
            ->with('primaryContact')
            ->withCount('contacts')
            ->when($withTrashed, fn ($q) => $q->withTrashed())
            ->when($search !== '', fn ($q) => $q->search($search))
            ->when($status !== null && $status !== '', fn ($q) => $q->where('status', $status))
            ->orderBy('company_name')
            ->paginate($perPage);
    }

    public function findByEmail(string $email, bool $withTrashed = false): ?Client
    {
        return $this->query()
            ->when($withTrashed, fn ($q) => $q->withTrashed())
            ->where('email', $email)
            ->first();
    }

    public function restore(Client $client): bool
    {
        return (bool) $client->restore();
    }
}
