<?php

namespace App\DTOs;

use App\Enums\ProjectStatus;

class ProjectData extends DataTransferObject
{
    public function __construct(
        public readonly int $clientId,
        public readonly string $name,
        public readonly ?string $description = null,
        public readonly ProjectStatus $status = ProjectStatus::Active,
    ) {
    }

    public function toProjectAttributes(): array
    {
        return [
            'client_id'   => $this->clientId,
            'name'        => $this->name,
            'description' => $this->description,
            'status'      => $this->status,
        ];
    }
}
