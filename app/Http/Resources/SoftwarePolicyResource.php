<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\SoftwarePolicy */
class SoftwarePolicyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'label'           => $this->label(),
            'project_id'      => $this->project_id,
            'package_id'      => $this->package_id,
            'action'          => $this->action,
            'mode'            => $this->mode,
            'version_mode'    => $this->version_mode,
            'desired_version' => $this->desired_version,
            'priority'        => $this->priority,
            'frequency'       => $this->frequency,
            'window'          => $this->windowLabel(),
            'last_enforced_at' => $this->last_enforced_at?->toIso8601String(),
            'compliance'      => $this->when(isset($this->compliance_summary), fn () => $this->compliance_summary),
        ];
    }
}
