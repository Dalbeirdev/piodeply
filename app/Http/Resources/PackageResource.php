<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Package */
class PackageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'name'           => $this->name,
            'slug'           => $this->slug,
            'vendor'         => $this->vendor,
            'installer_type' => $this->installer_type,
            'architecture'   => $this->architecture,
            'winget_id'      => $this->winget_id,
            'choco_id'       => $this->choco_id,
            'is_active'      => $this->is_active,
            'latest_version' => $this->whenLoaded('latestVersion', fn () => $this->latestVersion?->version),
        ];
    }
}
