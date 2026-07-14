<?php

namespace App\Livewire\Policies;

use App\Livewire\Concerns\WithCompactPagination;
use App\Models\SoftwarePolicy;
use App\Services\PolicyService;
use Livewire\Component;

class PoliciesIndex extends Component
{
    use WithCompactPagination;

    public string $search = '';

    public string $projectFilter = '';

    public function updating($name, $value): void
    {
        if (in_array($name, ['search', 'projectFilter'], true)) {
            $this->resetPage();
        }
    }

    public function toggle(int $policyId): void
    {
        $policy = SoftwarePolicy::findOrFail($policyId);
        $this->authorize('update', $policy);
        $policy->update(['is_active' => ! $policy->is_active]);
    }

    public function enforceNow(int $policyId, PolicyService $service): void
    {
        $policy = SoftwarePolicy::with(['project', 'package'])->findOrFail($policyId);
        $this->authorize('enforce', $policy);

        $queued = $service->enforce($policy);

        session()->flash('status', $queued > 0
            ? "{$policy->label()}: {$queued} job(s) queued."
            : "{$policy->label()}: fleet already compliant — nothing to queue.");
    }

    public function delete(int $policyId): void
    {
        $policy = SoftwarePolicy::findOrFail($policyId);
        $this->authorize('delete', $policy);
        $policy->delete();
        session()->flash('status', 'Policy deleted.');
    }

    public function render()
    {
        $this->authorize('viewAny', SoftwarePolicy::class);

        $tenantId = auth()->user()->tenantClientId();

        $policies = SoftwarePolicy::query()
            ->with(['project.client', 'package'])
            ->when($tenantId !== null, fn ($q) => $q->whereHas(
                'project',
                fn ($p) => $p->withTrashed()->where('client_id', $tenantId)
            ))
            ->when($this->search !== '', fn ($q) => $q->whereHas('package', fn ($p) => $p->where('name', 'like', "%{$this->search}%"))
                ->orWhereHas('project', fn ($p) => $p->where('name', 'like', "%{$this->search}%")))
            ->when($this->projectFilter !== '', fn ($q) => $q->where('project_id', $this->projectFilter))
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->paginate(20);

        return view('livewire.policies.policies-index', [
            'policies' => $policies,
            'projects' => \App\Models\Project::orderBy('name')
                ->when($tenantId !== null, fn ($q) => $q->where('client_id', $tenantId))
                ->get(['id', 'name']),
        ])->layout('layouts.app');
    }
}
