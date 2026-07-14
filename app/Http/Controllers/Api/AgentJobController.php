<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Computer;
use App\Models\DeploymentJob;
use App\Models\Project;
use App\Services\DeploymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentJobController extends Controller
{
    public function __construct(
        private readonly DeploymentService $deployments,
    ) {
    }

    /**
     * GET /api/v1/agent/jobs — claim the next batch of work for this agent.
     * Returns everything the agent needs to execute without a second call.
     */
    public function index(Request $request): JsonResponse
    {
        $computer = $this->resolveComputer($request);

        $jobs = $this->deployments->claimFor($computer);

        return response()->json([
            'jobs' => $jobs->map(fn (DeploymentJob $job) => $this->transform($job))->all(),
        ]);
    }

    /**
     * POST /api/v1/agent/jobs/{job}/result — report execution outcome.
     */
    public function result(Request $request, DeploymentJob $job): JsonResponse
    {
        $computer = $this->resolveComputer($request);
        abort_unless($job->computer_id === $computer->id, 404, 'Job does not belong to this agent.');

        $validated = $request->validate([
            'success'        => ['required', 'boolean'],
            'exit_code'      => ['nullable', 'integer'],
            'output_log'     => ['nullable', 'string', 'max:65535'],
            'failure_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $job = $this->deployments->reportResult(
            $job,
            (bool) $validated['success'],
            $validated['exit_code'] ?? null,
            $validated['output_log'] ?? null,
            $validated['failure_reason'] ?? null,
        );

        return response()->json(['status' => $job->status->value]);
    }

    private function transform(DeploymentJob $job): array
    {
        $package = $job->package;
        $version = $job->packageVersion ?? $package?->latestVersion()->first();

        return [
            'job_id'         => $job->id,
            'action'         => $job->action->value,
            'package'        => $package?->name,
            'installer_type' => $package?->installer_type->value,
            'winget_id'      => $package?->winget_id,
            'choco_id'       => $package?->choco_id,
            'version'        => $version?->version,
            'installer_url'  => $version?->installer_url,
            'sha256'         => $version?->sha256,
            'silent_args'    => $version?->silent_args,
            'uninstall_args' => $version?->uninstall_args,
        ];
    }

    private function resolveComputer(Request $request): Computer
    {
        /** @var Project $project */
        $project = $request->attributes->get('project');

        $validated = $request->validate(['agent_uuid' => ['required', 'uuid']]);

        $computer = Computer::query()
            ->where('agent_uuid', $validated['agent_uuid'])
            ->where('project_id', $project->id)
            ->first();

        abort_if($computer === null, 404, 'Unknown agent for this project. Re-register first.');

        return $computer;
    }
}
