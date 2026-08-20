<?php

namespace App\Livewire\Reports;

use App\Enums\JobStatus;
use App\Models\Computer;
use App\Models\DeploymentJob;
use App\Services\PolicyService;
use Livewire\Component;

class ReportsIndex extends Component
{
    /**
     * One number per tile — not a re-derivation, the same figure the linked
     * report itself leads with, so the hub can never promise something a
     * click then contradicts.
     */
    private function deploymentsGlance(?int $tenantId): array
    {
        // Same 30-day default DeploymentsReport opens with, so the number
        // here is exactly what a click through lands on, not a different
        // window quietly showing a different rate.
        $byStatus = DeploymentJob::query()
            ->when($tenantId !== null, fn ($q) => $q->whereHas(
                'computer.project',
                fn ($p) => $p->withTrashed()->where('client_id', $tenantId)
                    ->when(auth()->user()->visibleProjectIds() !== null,
                        fn ($qq) => $qq->whereIn('projects.id', auth()->user()->visibleProjectIds()))
            ))
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $succeeded = (int) $byStatus->get(JobStatus::Succeeded->value, 0);
        $failed = (int) $byStatus->get(JobStatus::Failed->value, 0);
        $finished = $succeeded + $failed;

        return [
            'rate' => $finished > 0 ? round($succeeded / $finished * 100, 1) : null,
        ];
    }

    public function render(PolicyService $policyService)
    {
        abort_unless(auth()->user()->can(\App\Enums\Permission::ReportsView->value), 403);

        $tenantId = auth()->user()->tenantClientId();

        return view('livewire.reports.reports-index', [
            'compliance'  => $policyService->fleetSummary($tenantId),
            'deployments' => $this->deploymentsGlance($tenantId),
            // What the Fleet health report's own description leads with —
            // "spot offline agents" — not a second copy of the Dashboard's
            // averaged health score restated on another page.
            'offline'     => Computer::visibleTo(auth()->user())->offline()->count(),
        ])->layout('layouts.app');
    }
}
