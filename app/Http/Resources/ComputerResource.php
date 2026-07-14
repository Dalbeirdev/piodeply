<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Computer */
class ComputerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'project_id'    => $this->project_id,
            'hostname'      => $this->hostname,
            'ring'          => $this->ring,
            'online'        => $this->isOnline(),
            'last_seen_at'  => $this->last_seen_at?->toIso8601String(),
            'agent_version' => $this->agent_version,
            'os_name'       => $this->os_name,
            'os_version'    => $this->os_version,
            'windows_build' => $this->windows_build,
            'manufacturer'  => $this->manufacturer,
            'model'         => $this->model,
            'serial_number' => $this->serial_number,
            'cpu'           => $this->cpu,
            'ram_bytes'     => $this->ram_bytes,
            'disk_total_bytes' => $this->disk_total_bytes,
            'disk_free_bytes'  => $this->disk_free_bytes,
            'private_ip'    => $this->private_ip,
            'software'      => $this->whenLoaded('software', fn () => $this->software->map(fn ($row) => [
                'name'      => $row->name,
                'version'   => $row->version,
                'publisher' => $row->publisher,
                'source'    => $row->source,
            ])),
        ];
    }
}
