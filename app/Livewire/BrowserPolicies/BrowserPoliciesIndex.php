<?php

namespace App\Livewire\BrowserPolicies;

use App\Livewire\Concerns\WithCompactPagination;
use App\Models\BrowserPolicy;
use App\Services\BrowserPolicyService;
use Livewire\Component;

class BrowserPoliciesIndex extends Component
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
        $policy = BrowserPolicy::findOrFail($policyId);
        $this->authorize('update', $policy);

        $policy->update(['status' => $policy->status === 'active' ? 'inactive' : 'active']);
    }

    public function delete(int $policyId): void
    {
        $policy = BrowserPolicy::findOrFail($policyId);
        $this->authorize('delete', $policy);
        $policy->delete();
        session()->flash('status', 'Browser policy deleted. Agents roll the setting back on their next check-in.');
    }

    public function render(BrowserPolicyService $service)
    {
        $this->authorize('viewAny', BrowserPolicy::class);

        $tenantId = auth()->user()->tenantClientId();

        $policies = BrowserPolicy::query()
            ->with(['project.client'])
            ->visibleTo($tenantId)
            // Grouped, or the project branch escapes the tenancy filter above
            // (AND binds tighter than OR) and leaks another client's policies.
            ->when($this->search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$this->search}%")
                ->orWhereHas('project', fn ($p) => $p->where('name', 'like', "%{$this->search}%"))))
            ->when($this->projectFilter !== '', fn ($q) => $q->where('project_id', $this->projectFilter))
            ->orderByDesc('status')
            ->orderBy('id')
            ->paginate(20);

        $summaries = collect($policies->items())
            ->mapWithKeys(fn (BrowserPolicy $policy) => [
                $policy->id => $policy->isActive() ? $service->complianceSummary($policy) : null,
            ]);

        return view('livewire.browser-policies.browser-policies-index', [
            'policies'  => $policies,
            'summaries' => $summaries,
            'projects'  => \App\Models\Project::orderBy('name')
                ->when($tenantId !== null, fn ($q) => $q->where('client_id', $tenantId))
                ->get(['id', 'name']),
        ])->layout('layouts.app');
    }
}
