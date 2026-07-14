<?php

namespace App\Services;

use App\Models\Computer;
use App\Models\Project;
use App\Repositories\Contracts\ComputerRepositoryInterface;

/**
 * Agent-facing lifecycle (register / heartbeat / inventory) plus the
 * portal-side management actions. The agent API (Phase 18) calls into
 * this service; nothing here assumes an HTTP context.
 */
class ComputerService
{
    /** Inventory fields an agent may set. */
    private const INVENTORY_FIELDS = [
        'hostname', 'serial_number', 'manufacturer', 'model',
        'os_name', 'os_version', 'windows_build',
        'cpu', 'ram_bytes', 'disk_total_bytes', 'disk_free_bytes',
        'public_ip', 'private_ip', 'mac_address',
        'secure_boot', 'tpm_enabled', 'tpm_version',
    ];

    public function __construct(
        private readonly ComputerRepositoryInterface $computers,
    ) {
    }

    /**
     * Idempotent agent registration: the same agent UUID always maps to the
     * same computer (re-registration updates inventory and revives a
     * soft-deleted record instead of duplicating it).
     */
    public function register(Project $project, string $agentUuid, array $inventory, ?string $agentVersion = null): Computer
    {
        $attributes = $this->onlyInventory($inventory) + [
            'project_id'    => $project->id,
            'agent_version' => $agentVersion,
            'last_seen_at'  => now(),
        ];

        $existing = $this->computers->findByAgentUuid($agentUuid, withTrashed: true);

        if ($existing !== null) {
            if ($existing->trashed()) {
                $this->computers->restore($existing);
            }
            $this->computers->update($existing, $attributes);

            return $existing->fresh();
        }

        /** @var Computer */
        return $this->computers->create($attributes + ['agent_uuid' => $agentUuid]);
    }

    public function heartbeat(Computer $computer, ?string $agentVersion = null): Computer
    {
        $computer->forceFill(array_filter([
            'last_seen_at'  => now(),
            'agent_version' => $agentVersion,
        ]))->saveQuietly(); // heartbeats must not spam the activity log

        return $computer;
    }

    public function updateInventory(Computer $computer, array $inventory): Computer
    {
        $this->computers->update($computer, $this->onlyInventory($inventory) + ['last_seen_at' => now()]);

        return $computer->fresh();
    }

    /**
     * Replace the computer's software inventory (agents report the full
     * list each time — replace semantics keep it authoritative).
     *
     * @param list<array{name: string, version?: ?string, publisher?: ?string, source: string}> $items
     */
    public function replaceSoftwareInventory(Computer $computer, array $items): int
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($computer, $items) {
            $computer->software()->delete();

            $now = now();
            $rows = collect($items)
                ->filter(fn (array $item) => trim((string) ($item['name'] ?? '')) !== '')
                ->map(fn (array $item) => [
                    'computer_id' => $computer->id,
                    'name'        => mb_substr(trim($item['name']), 0, 255),
                    'version'     => isset($item['version']) ? mb_substr((string) $item['version'], 0, 100) : null,
                    'publisher'   => isset($item['publisher']) ? mb_substr((string) $item['publisher'], 0, 255) : null,
                    'source'      => $item['source'],
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]);

            $rows->chunk(500)->each(fn ($chunk) => \App\Models\ComputerSoftware::insert($chunk->all()));

            $computer->forceFill(['last_seen_at' => now()])->saveQuietly();

            return $rows->count();
        });
    }

    public function reassign(Computer $computer, Project $project): Computer
    {
        $this->computers->update($computer, ['project_id' => $project->id]);

        return $computer->fresh('project.client');
    }

    public function delete(Computer $computer): void
    {
        $this->computers->delete($computer); // soft delete
    }

    public function restore(Computer $computer): void
    {
        $this->computers->restore($computer);
    }

    private function onlyInventory(array $inventory): array
    {
        return array_intersect_key($inventory, array_flip(self::INVENTORY_FIELDS));
    }
}
