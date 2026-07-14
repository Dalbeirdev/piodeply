<?php

namespace App\Livewire\Computers;

use App\Models\Computer;
use App\Models\Project;
use App\Services\ComputerService;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Computers are created by agents; the portal only reassigns them
 * between projects (and edits nothing an agent would overwrite).
 */
class ComputerEdit extends Component
{
    public Computer $computer;

    public ?int $project_id = null;

    public function mount(Computer $computer): void
    {
        $this->authorize('update', $computer);
        $this->computer = $computer->load('project.client');
        $this->project_id = $computer->project_id;
    }

    public function save(ComputerService $service)
    {
        $this->authorize('update', $this->computer);

        $validated = $this->validate([
            'project_id' => ['required', 'integer', Rule::exists('projects', 'id')->withoutTrashed()],
        ]);

        $service->reassign($this->computer, Project::findOrFail($validated['project_id']));

        session()->flash('status', "“{$this->computer->hostname}” reassigned.");

        return $this->redirectRoute('computers.show', $this->computer);
    }

    public function render()
    {
        return view('livewire.computers.computer-edit', [
            'projects' => Project::with('client')->orderBy('name')->get(),
        ])->layout('layouts.app');
    }
}
