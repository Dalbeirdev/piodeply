<?php

namespace App\Livewire\Projects;

use App\DTOs\ProjectData;
use App\Enums\ProjectStatus;
use App\Models\Client;
use App\Models\Project;
use App\Services\ProjectService;
use Illuminate\Validation\Rule;
use Livewire\Component;

class ProjectForm extends Component
{
    public ?Project $project = null;

    public ?int $client_id = null;

    public string $name = '';

    public ?string $description = null;

    public string $status = 'active';

    public function mount(?Project $project = null): void
    {
        if ($project !== null && $project->exists) {
            $this->authorize('update', $project);
            $this->project = $project;
            $this->client_id = $project->client_id;
            $this->name = $project->name;
            $this->description = $project->description;
            $this->status = $project->status->value;
        } else {
            $this->authorize('create', Project::class);
        }
    }

    public function save(ProjectService $service)
    {
        $this->authorize($this->project ? 'update' : 'create', $this->project ?? Project::class);

        $validated = $this->validate([
            'client_id'   => ['required', 'integer', Rule::exists('clients', 'id')->withoutTrashed()],
            'name'        => ['required', 'string', 'max:255',
                Rule::unique('projects', 'name')
                    ->where('client_id', $this->client_id)
                    ->ignore($this->project?->id)
                    ->withoutTrashed()],
            'description' => ['nullable', 'string', 'max:2000'],
            'status'      => ['required', Rule::in(ProjectStatus::values())],
        ]);

        $data = new ProjectData(
            clientId: (int) $validated['client_id'],
            name: $validated['name'],
            description: $validated['description'] ?? null,
            status: ProjectStatus::from($validated['status']),
        );

        if ($this->project) {
            $service->update($this->project, $data);
            session()->flash('status', 'Project saved.');
        } else {
            $result = $service->create($data);
            session()->flash('status', 'Project created.');
            // Shown exactly once — the hash is all that's stored.
            session()->flash('new_api_key', $result['plain_api_key']);
            session()->flash('new_api_key_project', $result['project']->name);
        }

        return $this->redirectRoute('projects.index');
    }

    public function render()
    {
        return view('livewire.projects.project-form', [
            'clients'  => Client::orderBy('company_name')->get(['id', 'company_name']),
            'statuses' => ProjectStatus::cases(),
        ])->layout('layouts.app');
    }
}
