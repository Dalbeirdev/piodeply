<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Browser;
use App\Enums\BrowserPolicyType;
use App\Enums\Permission;
use App\Http\Resources\BrowserPolicyResource;
use App\Models\BrowserPolicy;
use App\Models\Computer;
use App\Services\BrowserPolicyService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BrowserPoliciesController extends IntegrationController
{
    public function index(Request $request)
    {
        $this->requireAbility($request, 'read', Permission::PoliciesView->value);

        return BrowserPolicyResource::collection(
            BrowserPolicy::query()
                ->with('project')
                ->when($this->tenantId($request) !== null, fn ($q) => $q->whereHas(
                    'project',
                    fn ($p) => $p->withTrashed()->where('client_id', $this->tenantId($request))
                ))
                ->when($request->filled('project_id'), fn ($q) => $q->where('project_id', $request->integer('project_id')))
                ->orderBy('id')
                ->paginate(min(100, (int) $request->integer('per_page', 25)))
        );
    }

    public function show(Request $request, BrowserPolicy $policy, BrowserPolicyService $service)
    {
        $this->requireAbility($request, 'read', Permission::PoliciesView->value);
        abort_unless($request->user()->can('view', $policy), 404);

        $policy->compliance_summary = $service->complianceSummary($policy);

        return new BrowserPolicyResource($policy->load('project'));
    }

    public function store(Request $request)
    {
        $this->requireAbility($request, 'deploy', Permission::PoliciesManage->value);

        $validated = $this->validatePayload($request);

        abort_if(BrowserPolicy::where('project_id', $validated['project_id'])
            ->where('type', $validated['type'])->exists(), 422, 'This project already has a policy of this type.');

        $policy = BrowserPolicy::create($validated + ['created_by' => $request->user()->id]);

        return (new BrowserPolicyResource($policy))->response()->setStatusCode(201);
    }

    public function update(Request $request, BrowserPolicy $policy)
    {
        $this->requireAbility($request, 'deploy', Permission::PoliciesManage->value);
        abort_unless($request->user()->can('view', $policy), 404);

        $validated = $this->validatePayload($request);

        abort_if(BrowserPolicy::where('project_id', $validated['project_id'])
            ->where('type', $validated['type'])
            ->whereKeyNot($policy->id)->exists(), 422, 'This project already has a policy of this type.');

        $policy->update($validated);

        return new BrowserPolicyResource($policy->fresh());
    }

    public function destroy(Request $request, BrowserPolicy $policy)
    {
        $this->requireAbility($request, 'deploy', Permission::PoliciesManage->value);
        abort_unless($request->user()->can('view', $policy), 404);

        $policy->delete();

        return response()->json(['deleted' => true]);
    }

    /** GET /computers/{computer}/browser-policies — per-device results. */
    public function deviceResults(Request $request, Computer $computer)
    {
        $this->requireAbility($request, 'read', Permission::PoliciesView->value);
        abort_unless($request->user()->can('view', $computer), 404);

        return response()->json([
            'data' => $computer->browserPolicyResults()->with('policy:id,name,type,action')
                ->get()
                ->map(fn ($result) => [
                    'policy_id'   => $result->browser_policy_id,
                    'policy'      => $result->policy?->name,
                    'browser'     => $result->browser,
                    'status'      => $result->status,
                    'detail'      => $result->detail,
                    'reported_at' => $result->reported_at?->toIso8601String(),
                ]),
        ]);
    }

    private function validatePayload(Request $request): array
    {
        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'project_id'  => ['required', 'integer', Rule::exists('projects', 'id')->withoutTrashed()],
            'type'        => ['required', Rule::in(BrowserPolicyType::values())],
            'browsers'    => ['required', 'array', 'min:1'],
            'browsers.*'  => [Rule::in(['all', ...Browser::values()])],
            'action'      => ['required', Rule::in(BrowserPolicy::ACTIONS)],
            'status'      => ['required', Rule::in(BrowserPolicy::STATUSES)],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $validated['browsers'] = in_array('all', $validated['browsers'], true)
            ? ['all'] : array_values($validated['browsers']);

        return $validated;
    }
}
