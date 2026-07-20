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

        try {
            $computer = $this->computers->register(
                project: $project,
                agentUuid: $request->validated('agent_uuid'),
                inventory: $this->inventoryWithPublicIp($request),
                agentVersion: $request->validated('agent_version'),
            );
        } catch (\App\Exceptions\DeviceLimitReachedException $e) {
            // 402 Payment Required — the fleet has outgrown the plan.
            return response()->json([
                'error'        => 'device_limit_reached',
                'message'      => $e->getMessage(),
                'device_limit' => $e->limit,
                'device_count' => $e->current,
            ], 402);
        }

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
            // Operator-queued agent commands, delivered exactly once: the flag
            // is cleared as it is handed out, so a command that fails on the
            // machine is re-queued by clicking again — never by looping.
            'reinstall'         => $this->computers->pullAgentCommand($computer, 'reinstall_requested_at'),
            'uninstall'         => $this->computers->pullAgentCommand($computer, 'uninstall_requested_at'),
            'heartbeat_seconds' => (int) config('piodeploy.agent.heartbeat_seconds'),
            'server_time'       => now()->toIso8601String(),
            // What the agent should be running, and where to get it. An agent
            // already on this version ignores both; an older one self-updates,
            // so a machine is upgraded once and never touched by hand again.
            'latest_agent_version' => \App\Services\EnrollmentScriptService::CURRENT_AGENT_VERSION,
            'bundle_url'           => \Illuminate\Support\Facades\Storage::disk('local')
                ->exists(\App\Http\Controllers\AgentDownloadController::BUNDLE_PATH)
                    ? route('agent.bundle')
                    : null,
        ]);
    }

    /**
     * GET /api/v1/agent/bundle — the current agent zip, for an agent that has
     * decided to update itself. Authenticated by the same API key the agent
     * already holds, so no download token is needed.
     */
    public function bundle(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        abort_unless(
            \Illuminate\Support\Facades\Storage::disk('local')->exists(\App\Http\Controllers\AgentDownloadController::BUNDLE_PATH),
            404,
            'No agent bundle published.'
        );

        return \Illuminate\Support\Facades\Storage::disk('local')
            ->download(\App\Http\Controllers\AgentDownloadController::BUNDLE_PATH, 'PioDeployAgent.zip');
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
            // Agent 1.3.5+ reports readiness self-checks; optional so older
            // agents keep reporting inventory unchanged.
            'environment'          => ['sometimes', 'array', 'max:20'],
            'environment.*.key'    => ['required', 'string', 'max:50'],
            'environment.*.ok'     => ['required', 'boolean'],
            'environment.*.detail' => ['nullable', 'string', 'max:500'],
        ]);

        $computer = $this->resolveComputer($request, $validated['agent_uuid']);

        $stored = $this->computers->replaceSoftwareInventory(
            $computer, $validated['software'], $validated['environment'] ?? null
        );

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
