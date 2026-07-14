<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BrowserPolicyResult;
use App\Models\Computer;
use App\Models\Project;
use App\Services\BrowserPolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AgentBrowserPolicyController extends Controller
{
    public function __construct(
        private readonly BrowserPolicyService $policies,
    ) {
    }

    /** POST /api/v1/agent/browser-policies — the machine's desired state. */
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            $this->policies->documentFor($this->resolveComputer($request))
        );
    }

    /** POST /api/v1/agent/browser-policies/results — apply/verify outcomes. */
    public function results(Request $request): JsonResponse
    {
        $computer = $this->resolveComputer($request);

        $validated = $request->validate([
            'results'             => ['required', 'array', 'max:500'],
            'results.*.policy_id' => ['required', 'integer'],
            'results.*.browser'   => ['required', Rule::in(\App\Enums\Browser::values())],
            'results.*.status'    => ['required', Rule::in(BrowserPolicyResult::STATUSES)],
            'results.*.detail'    => ['nullable', 'string', 'max:500'],
            'results.*.old_value' => ['nullable', 'string', 'max:100'],
            'results.*.new_value' => ['nullable', 'string', 'max:100'],
        ]);

        $stored = $this->policies->ingestResults($computer, $validated['results']);

        return response()->json(['stored' => $stored]);
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
