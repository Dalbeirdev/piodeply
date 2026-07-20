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
            // Re-enrolment is a live agent: void any standing proof-of-removal.
            'agent_uninstalled_at' => null,
        ];

        $existing = $this->computers->findByAgentUuid($agentUuid, withTrashed: true);

        if ($existing !== null) {
            if ($existing->trashed()) {
                $this->computers->restore($existing);
            }
            $this->computers->update($existing, $attributes);

            return $existing->fresh();
        }

        // A brand-new machine: enforce the plan's device limit. Existing
        // machines re-register above without a check; this only gates growth,
        // and only when a plan actually sets a limit (unbilled installs are
        // unlimited).
        $this->assertHasCapacityForNewDevice();

        /** @var Computer $computer */
        $computer = $this->computers->create($attributes + ['agent_uuid' => $agentUuid]);

        app(NotificationService::class)->notify('computer.registered', "New computer enrolled: {$computer->hostname}", [
            'computer' => $computer->hostname,
            'client'   => $computer->project->client->company_name,
            'project'  => $computer->project->name,
            'os'       => trim(($computer->os_name ?? '') . ' ' . ($computer->windows_build ?? '')),
            'serial'   => $computer->serial_number,
        ]);

        return $computer;
    }

    public function heartbeat(Computer $computer, ?string $agentVersion = null): Computer
    {
        $computer->forceFill(array_filter([
            'last_seen_at'  => now(),
            'agent_version' => $agentVersion,
        ]) + [
            'offline_notified_at' => null, // machine is back — re-arm the offline alert
            // A checking-in agent is by definition not uninstalled. This is
            // what keeps the uninstalled-proof honest: it survives only on a
            // machine that actually went silent after the command.
            'agent_uninstalled_at' => null,
        ])->saveQuietly(); // heartbeats must not spam the activity log

        return $computer;
    }

    /**
     * Reads and clears an operator-queued agent command in one step. The
     * heartbeat is the only caller, so "delivered" and "cleared" cannot
     * drift apart: an agent is told exactly once, and a command that never
     * took effect is visibly gone from the UI rather than looping forever.
     */
    public function pullAgentCommand(Computer $computer, string $column): bool
    {
        if ($computer->{$column} === null) {
            return false;
        }

        $update = [$column => null];

        // Delivering an uninstall starts the proof-of-removal clock: the
        // marker stands unless the machine checks in again (which clears it
        // — see heartbeat). Permanent deletion requires this marker.
        if ($column === 'uninstall_requested_at') {
            $update['agent_uninstalled_at'] = now();
        }

        $computer->forceFill($update)->saveQuietly();

        return true;
    }

    /**
     * Whether this machine may be permanently deleted: its agent was never
     * there (never checked in), or an uninstall was delivered and the
     * machine has stayed silent since. A machine with a live agent can only
     * be retired — deleting the record would orphan a working agent that
     * would simply re-register.
     */
    public function agentIsGone(Computer $computer): bool
    {
        return $computer->last_seen_at === null
            || $computer->agent_uninstalled_at !== null;
    }

    /**
     * Permanent removal — record, history, jobs. Guarded here so no caller
     * (UI, console, future API) can skip the agent-first rule.
     */
    public function forceDelete(Computer $computer): void
    {
        if (! $this->agentIsGone($computer)) {
            throw new \DomainException(
                "{$computer->hostname} still has its agent installed. Use \"Uninstall agent\" on the "
                .'machine page (or the enrolment page\'s uninstall script for unreachable machines), '
                .'wait for it to go silent, then delete permanently.'
            );
        }

        $computer->forceDelete();
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
    public function replaceSoftwareInventory(Computer $computer, array $items, ?array $environment = null): int
    {
        // Readiness rides with the inventory; keep the last report when an
        // older agent sends none rather than blanking what we knew.
        if ($environment !== null) {
            $computer->forceFill(['environment' => array_values($environment)])->saveQuietly();
        }

        $stored = \Illuminate\Support\Facades\DB::transaction(function () use ($computer, $items) {
            $computer->software()->delete();

            $now = now();
            $rows = collect($items)
                ->filter(fn (array $item) => trim((string) ($item['name'] ?? '')) !== '')
                ->map(fn (array $item) => [
                    'computer_id' => $computer->id,
                    'name'        => mb_substr(trim($item['name']), 0, 255),
                    'version'     => isset($item['version']) ? mb_substr((string) $item['version'], 0, 100) : null,
                    // Agents older than 1.3.3 do not send this.
                    'available_version' => isset($item['available_version'])
                        ? mb_substr((string) $item['available_version'], 0, 100)
                        : null,
                    'publisher'   => isset($item['publisher']) ? mb_substr((string) $item['publisher'], 0, 255) : null,
                    'source'      => $item['source'],
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]);

            $rows->chunk(500)->each(fn ($chunk) => \App\Models\ComputerSoftware::insert($chunk->all()));

            $computer->forceFill(['last_seen_at' => now()])->saveQuietly();

            return $rows->count();
        });

        // Fresh inventory in hand — heal any drift from the project's
        // software policies (new machines self-provision here).
        app(PolicyService::class)->enforceForComputer($computer);

        return $stored;
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

    /**
     * Guard a new enrollment against the account's device limit. No-op when no
     * plan sets a limit. Alerts the team the first time the ceiling is hit.
     */
    private function assertHasCapacityForNewDevice(): void
    {
        $account = \App\Models\Account::current();
        $limit = $account->effectiveDeviceLimit();

        if ($limit === null) {
            return; // unbilled / no plan → unlimited
        }

        $current = $account->deviceCount();
        if ($current >= $limit) {
            app(NotificationService::class)->notify(
                'billing.device_limit',
                "Device limit reached ({$current}/{$limit})",
                ['limit' => $limit, 'current' => $current, 'plan' => $account->plan?->name]
            );

            throw new \App\Exceptions\DeviceLimitReachedException($limit, $current);
        }
    }

    private function onlyInventory(array $inventory): array
    {
        return array_intersect_key($inventory, array_flip(self::INVENTORY_FIELDS));
    }
}
