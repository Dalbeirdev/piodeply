<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Client */
class ClientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'company_name' => $this->company_name,
            'status'       => $this->status,
            'created_at'   => $this->created_at?->toIso8601String(),
            'projects'     => ProjectResource::collection($this->whenLoaded('projects')),
        ];
    }
}
