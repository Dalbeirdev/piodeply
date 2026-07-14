<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\BrowserPolicy */
class BrowserPolicyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'label'       => $this->label(),
            'project_id'  => $this->project_id,
            'type'        => $this->type,
            'browsers'    => $this->browsers,
            'action'      => $this->action,
            'status'      => $this->status,
            'description' => $this->description,
            'created_at'  => $this->created_at?->toIso8601String(),
            'compliance'  => $this->when(isset($this->compliance_summary), fn () => $this->compliance_summary),
        ];
    }
}
