<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Never exposes api_key_hash or the download token — integrations get
 * identity and status only.
 *
 * @mixin \App\Models\Project
 */
class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'client_id'   => $this->client_id,
            'name'        => $this->name,
            'description' => $this->description,
            'status'      => $this->status,
            'computers'   => $this->whenCounted('computers'),
            'created_at'  => $this->created_at?->toIso8601String(),
        ];
    }
}
