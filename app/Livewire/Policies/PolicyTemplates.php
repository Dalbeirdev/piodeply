<?php

namespace App\Livewire\Policies;

use App\Enums\Permission;
use App\Models\PolicyTemplate;
use App\Models\Project;
use App\Services\PolicyTemplateService;
use Livewire\Component;

/**
 * One-click starter kits. Anyone who can manage policies may APPLY a
 * template to a project they can see; only staff may create or delete
 * templates, because templates are global — a tenant-made one would leak
 * that tenant's software choices to every other customer.
 */
class PolicyTemplates extends Component
{
    /** template id => chosen project id, per Apply row. */
    public array $applyProject = [];

    public string $newName = '';

    public string $newDescription = '';

    public ?int $sourceProjectId = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->can(Permission::PoliciesView->value), 403);
    }

    public function apply(int $templateId, PolicyTemplateService $service): void
    {
        abort_unless(auth()->user()->can(Permission::PoliciesManage->value), 403);

        $template = PolicyTemplate::findOrFail($templateId);
        $project = $this->visibleProjects()->findOrFail((int) ($this->applyProject[$templateId] ?? 0));

        $result = $service->applyToProject($template, $project, auth()->id());

        session()->flash('status',
            "\"{$template->name}\" applied to {$project->name}: {$result['created']} "
            .str('policy')->plural($result['created'])." created"
            .($result['skipped'] > 0 ? ", {$result['skipped']} already existed" : '').'.');
    }

    public function saveAsTemplate(PolicyTemplateService $service): void
    {
        $this->authorizeStaff();

        $validated = $this->validate([
            'newName'        => ['required', 'string', 'max:100', 'unique:policy_templates,name'],
            'newDescription' => ['nullable', 'string', 'max:500'],
            'sourceProjectId' => ['required', 'exists:projects,id'],
        ]);

        $project = Project::findOrFail($validated['sourceProjectId']);
        $result = $service->createFromProject($project, $validated['newName'], $validated['newDescription'] ?: null, auth()->id());

        $this->reset('newName', 'newDescription', 'sourceProjectId');

        session()->flash('status',
            "Template \"{$result['template']->name}\" saved with {$result['captured']} "
            .str('policy')->plural($result['captured'])
            .($result['skipped'] > 0 ? " ({$result['skipped']} non-winget policies skipped — templates only carry portable winget identities)" : '').'.');
    }

    public function delete(int $templateId): void
    {
        $this->authorizeStaff();

        $template = PolicyTemplate::findOrFail($templateId);
        $template->delete(); // items cascade; applied policies stay — they belong to their projects

        session()->flash('status', "Template \"{$template->name}\" deleted. Policies it created are untouched.");
    }

    /** Staff-only actions: template CRUD. Tenants apply, never author. */
    private function authorizeStaff(): void
    {
        abort_unless(auth()->user()->can(Permission::PoliciesManage->value), 403);
        abort_if(auth()->user()->tenantClientId() !== null, 403, 'Templates are managed by platform staff.');
    }

    /** Tenancy: client-bound users only ever see their own projects. */
    private function visibleProjects()
    {
        $tenantId = auth()->user()->tenantClientId();

        return Project::query()
            ->when($tenantId !== null, fn ($q) => $q->where('client_id', $tenantId))
            ->when(auth()->user()->visibleProjectIds() !== null,
                fn ($q) => $q->whereIn('id', auth()->user()->visibleProjectIds()))
            ->orderBy('name');
    }

    public function render()
    {
        return view('livewire.policies.policy-templates', [
            'templates' => PolicyTemplate::with('items')->orderByDesc('is_builtin')->orderBy('name')->get(),
            'projects'  => $this->visibleProjects()->get(['id', 'name', 'client_id']),
            'isStaff'   => auth()->user()->tenantClientId() === null,
            'canManage' => auth()->user()->can(Permission::PoliciesManage->value),
        ])->layout('layouts.app');
    }
}
