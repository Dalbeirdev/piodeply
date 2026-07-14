<?php

namespace App\Livewire\Deployments;

use App\Enums\JobAction;
use App\Models\Computer;
use App\Models\DeploymentJob;
use App\Models\Package;
use App\Services\DeploymentService;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Queue a deployment against a single computer (from its detail page).
 */
class DeployToComputer extends Component
{
    public Computer $computer;

    public ?int $package_id = null;

    public string $action = 'install';

    public int $priority = 5;

    public function mount(Computer $computer): void
    {
        $this->computer = $computer;
    }

    public function queue(DeploymentService $service): void
    {
        $this->authorize('create', DeploymentJob::class);

        $validated = $this->validate([
            'package_id' => ['required', 'integer', Rule::exists('packages', 'id')->where('is_active', true)],
            'action'     => ['required', Rule::in(JobAction::values())],
            'priority'   => ['required', 'integer', 'between:1,10'],
        ]);

        $service->queue(
            computer: $this->computer,
            package: Package::findOrFail($validated['package_id']),
            action: JobAction::from($validated['action']),
            priority: $validated['priority'],
            createdBy: auth()->id(),
        );

        $this->reset('package_id');
        $this->dispatch('job-queued');
        session()->flash('status', 'Deployment queued.');
    }

    public function render()
    {
        return view('livewire.deployments.deploy-to-computer', [
            'packages' => Package::active()->orderBy('name')->get(['id', 'name', 'installer_type']),
            'actions'  => JobAction::cases(),
        ]);
    }
}
