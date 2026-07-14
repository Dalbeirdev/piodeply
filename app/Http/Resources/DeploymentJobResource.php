<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\DeploymentJob */
class DeploymentJobResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'computer_id'    => $this->computer_id,
            'computer'       => $this->whenLoaded('computer', fn () => $this->computer->hostname),
            'package_id'     => $this->package_id,
            'package'        => $this->whenLoaded('package', fn () => $this->package->name),
            'action'         => $this->action,
            'status'         => $this->status,
            'priority'       => $this->priority,
            'target_version' => $this->target_version,
            'attempts'       => $this->attempts,
            'max_attempts'   => $this->max_attempts,
            'exit_code'      => $this->exit_code,
            'failure_reason' => $this->failure_reason,
            'created_at'     => $this->created_at?->toIso8601String(),
            'finished_at'    => $this->finished_at?->toIso8601String(),
        ];
    }
}
