<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AgentRegisterRequest;
use App\Models\Computer;
use App\Models\Project;
use App\Services\ComputerService;
use App\Services\DeploymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentController extends Controller
{
    public function __construct(
        private readonly ComputerService $computers,
        private readonly DeploymentService $deployments,
    ) {
    }

    /**
     * POST /api/v1/agent/register
     * Idempotent: the same agent UUID always maps to the same computer.
     */
    public function register(AgentRegisterRequest $request): JsonResponse
    {
        /** @var Project $project */
        $project = $request->attributes->get('project');

        $computer = $this->computers->register(
            project: $project,
            agentUuid: $request->validated('agent_uuid'),
            inventory: $this->inventoryWithPublicIp($request),
            agentVersion: $request->validated('agent_version'),
        );

        return response()->json([
            'computer_id'       => $computer->id,
            'hostname'          => $computer->hostname,
            'project'           => $project->name,
            'heartbeat_seconds' => (int) config('piodeploy.agent.heartbeat_seconds'),
            'server_time'       => now()->toIso8601String(),
        ], 201);
    }

    /**
     * POST /api/v1/agent/heartbeat
     */
    public function heartbeat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'agent_uuid'    => ['required', 'uuid'],
            'agent_version' => ['nullable', 'string', 'max:20'],
        ]);

        $computer = $this->resolveComputer($request, $validated['agent_uuid']);

        $this->computers->heartbeat($computer, $validated['agent_version'] ?? null);

        return response()->json([
            'status'            => 'ok',
            'pending_jobs'      => $this->deployments->pendingCountFor($computer),
            'heartbeat_seconds' => (int) config('piodeploy.agent.heartbeat_seconds'),
            'server_time'       => now()->toIso8601String(),
        ]);
    }

    /**
     * POST /api/v1/agent/inventory
     */
    public function inventory(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'agent_uuid' => ['required', 'uuid'],
            'inventory'  => ['required', 'array'],
        ] + AgentRegisterRequest::inventoryRules());

        $computer = $this->resolveComputer($request, $validated['agent_uuid']);

        $this->computers->updateInventory($computer, $this->inventoryWithPublicIp($request));

        return response()->json(['status' => 'ok']);
    }

    /**
     * POST /api/v1/agent/software — full installed-software inventory.
     */
    public function software(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'agent_uuid'           => ['required', 'uuid'],
            'software'             => ['required', 'array', 'max:3000'],
            'software.*.name'      => ['required', 'string', 'max:255'],
            'software.*.version'   => ['nullable', 'string', 'max:100'],
            // Agent 1.3.3+ reports what the package manager is offering.
            // Optional, so older agents keep reporting inventory unchanged.
            'software.*.available_version' => ['nullable', 'string', 'max:100'],
            'software.*.publisher' => ['nullable', 'string', 'max:255'],
            'software.*.source'    => ['required', \Illuminate\Validation\Rule::in(Computer::softwareSources())],
        ]);

        $computer = $this->resolveComputer($request, $validated['agent_uuid']);

        $stored = $this->computers->replaceSoftwareInventory($computer, $validated['software']);

        return response()->json(['status' => 'ok', 'stored' => $stored]);
    }

    /**
     * The agent's public IP is what we see on the wire — agents never
     * self-report it.
     */
    private function inventoryWithPublicIp(Request $request): array
    {
        return (array) $request->input('inventory', []) + ['public_ip' => $request->ip()];
    }

    private function resolveComputer(Request $request, string $agentUuid): Computer
    {
        /** @var Project $project */
        $project = $request->attributes->get('project');

        $computer = Computer::query()
            ->where('agent_uuid', $agentUuid)
            ->where('project_id', $project->id)
            ->first();

        abort_if($computer === null, 404, 'Unknown agent for this project. Re-register first.');

        return $computer;
    }
}
