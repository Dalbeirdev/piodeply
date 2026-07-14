<?php

namespace App\Livewire\Policies;

use App\Enums\PolicyMode;
use App\Models\SoftwarePolicy;
use App\Services\PolicyService;
use Livewire\Component;

class PolicyShow extends Component
{
    public SoftwarePolicy $policy;

    /** '' = all; or compliant|non_compliant|pending|failed|excluded|offline */
    public string $statusFilter = '';

    public function mount(SoftwarePolicy $policy): void
    {
        $this->authorize('view', $policy);
        $this->policy = $policy->load(['project.client', 'package', 'creator']);
    }

    public function filterBy(string $status): void
    {
        $this->statusFilter = $this->statusFilter === $status ? '' : $status;
    }

    public function enforceNow(PolicyService $service): void
    {
        $this->authorize('enforce', $this->policy);

        if ($this->policy->mode === PolicyMode::Audit) {
            session()->flash('status', 'Audit-only policy — compliance is reported but nothing is queued.');

            return;
        }

        $queued = $service->enforce($this->policy);
        $this->policy->refresh();

        session()->flash('status', $queued > 0
            ? "{$queued} remediation job(s) queued."
            : 'Fleet already compliant — nothing to queue.');
    }

    public function toggleExclusion(int $computerId): void
    {
        $this->authorize('update', $this->policy);

        $this->policy->excludedComputers()->toggle([$computerId]);

        activity('policies')
            ->causedBy(auth()->user())
            ->performedOn($this->policy)
            ->withProperties(['computer_id' => $computerId])
            ->log('exclusion_toggled');
    }

    public function render(PolicyService $service)
    {
        $rows = $service->complianceFor($this->policy);

        $filtered = match ($this->statusFilter) {
            '' => $rows,
            'offline' => $rows->filter(fn (array $row) => $row['offline'] && $row['status'] !== 'excluded'),
            default => $rows->where('status', $this->statusFilter),
        };

        return view('livewire.policies.policy-show', [
            'summary' => $service->complianceSummary($this->policy),
            'rows'    => $filtered->values(),
        ])->layout('layouts.app');
    }
}
