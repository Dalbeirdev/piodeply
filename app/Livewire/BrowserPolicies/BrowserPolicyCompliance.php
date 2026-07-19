<?php

namespace App\Livewire\BrowserPolicies;

use App\Models\BrowserPolicy;
use App\Models\BrowserPolicyResult;
use App\Services\BrowserPolicyService;
use Livewire\Component;

/**
 * Cross-policy compliance dashboard: fleet totals, a per-policy breakdown,
 * and the machines currently failing something — one page that answers
 * "are we protected, and where not?". Read-only; drill-down happens on the
 * per-policy show page.
 */
class BrowserPolicyCompliance extends Component
{
    /** '' = all, or a worst-status bucket to filter the policy table by. */
    public string $onlyProblems = '';

    public function render(BrowserPolicyService $service)
    {
        $this->authorize('viewAny', BrowserPolicy::class);

        $tenantId = auth()->user()->tenantClientId();

        $policies = BrowserPolicy::query()
            ->with(['project.client'])
            ->where('status', 'active')
            ->when($tenantId !== null, fn ($q) => $q->whereHas(
                'project',
                fn ($p) => $p->withTrashed()->where('client_id', $tenantId)
            ))
            ->orderBy('name')
            ->get();

        $rows = $policies->map(function (BrowserPolicy $policy) use ($service) {
            $summary = $service->complianceSummary($policy);
            $summary['policy'] = $policy;
            $summary['last_report'] = $policy->results()->max('reported_at');

            return $summary;
        });

        if ($this->onlyProblems === '1') {
            $rows = $rows->filter(fn (array $r) => $r['non_compliant'] > 0 || $r['pending'] > 0);
        }

        // The machines currently failing a policy, most recent first. Scoped
        // through the policy relation so a tenant never sees foreign hosts.
        $attention = BrowserPolicyResult::query()
            ->with(['computer', 'policy.project'])
            ->whereIn('status', ['non_compliant', 'error'])
            ->whereHas('policy', fn ($q) => $q
                ->where('status', 'active')
                ->when($tenantId !== null, fn ($p) => $p->whereHas(
                    'project',
                    fn ($pr) => $pr->withTrashed()->where('client_id', $tenantId)
                )))
            ->orderByDesc('reported_at')
            ->limit(25)
            ->get();

        return view('livewire.browser-policies.browser-policy-compliance', [
            'fleet'     => $service->fleetSummary($tenantId),
            'rows'      => $rows,
            'attention' => $attention,
        ])->layout('layouts.app');
    }
}
