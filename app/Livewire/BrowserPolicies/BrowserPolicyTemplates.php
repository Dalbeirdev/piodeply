<?php

namespace App\Livewire\BrowserPolicies;

use App\Models\BrowserPolicy;
use App\Models\BrowserPolicyTemplate;
use App\Models\Project;
use App\Services\BrowserPolicyTemplateService;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Policy templates: apply a built-in or custom bundle to a project in one
 * click, save a project's current policies as a new template, and delete
 * custom templates. Gated by the browser-policy create ability.
 */
class BrowserPolicyTemplates extends Component
{
    /** Template key being applied (from the Apply modal row). */
    public ?string $applyKey = null;

    public ?int $applyProjectId = null;

    /** Save-as-template form. */
    public ?int $captureProjectId = null;

    public string $captureName = '';

    public ?string $captureDescription = null;

    public function mount(): void
    {
        $this->authorize('create', BrowserPolicy::class);
    }

    public function startApply(string $key): void
    {
        $this->applyKey = $key;
        $this->applyProjectId = null;
    }

    public function cancelApply(): void
    {
        $this->reset(['applyKey', 'applyProjectId']);
    }

    public function apply(BrowserPolicyTemplateService $templates): void
    {
        $this->authorize('create', BrowserPolicy::class);

        $this->validate([
            'applyProjectId' => ['required', 'integer', Rule::exists('projects', 'id')->withoutTrashed()],
        ], [], ['applyProjectId' => 'project']);

        $template = $templates->find((string) $this->applyKey);

        if ($template === null) {
            session()->flash('status', 'That template no longer exists.');
            $this->cancelApply();

            return;
        }

        $result = $templates->apply($template, Project::findOrFail($this->applyProjectId), auth()->id());

        session()->flash('status', sprintf(
            '%s applied: %d %s created%s. Agents pick the policies up on their next check-in.',
            $template['name'],
            $result['created'],
            str('policy')->plural($result['created']),
            $result['skipped'] > 0 ? ", {$result['skipped']} skipped (project already had that type)" : '',
        ));

        $this->cancelApply();
    }

    public function capture(BrowserPolicyTemplateService $templates): void
    {
        $this->authorize('create', BrowserPolicy::class);

        $validated = $this->validate([
            'captureProjectId'    => ['required', 'integer', Rule::exists('projects', 'id')->withoutTrashed()],
            'captureName'         => ['required', 'string', 'max:255', Rule::unique('browser_policy_templates', 'name')],
            'captureDescription'  => ['nullable', 'string', 'max:500'],
        ], [], ['captureProjectId' => 'project', 'captureName' => 'template name']);

        $project = Project::findOrFail($validated['captureProjectId']);

        if (! BrowserPolicy::where('project_id', $project->id)->exists()) {
            $this->addError('captureProjectId', 'That project has no browser policies to save.');

            return;
        }

        $templates->captureFromProject($validated['captureName'], $this->captureDescription, $project, auth()->id());

        $this->reset(['captureProjectId', 'captureName', 'captureDescription']);
        session()->flash('status', 'Template saved. Apply it to any project from the list below.');
    }

    public function deleteTemplate(int $templateId): void
    {
        $this->authorize('create', BrowserPolicy::class);

        BrowserPolicyTemplate::findOrFail($templateId)->delete();

        session()->flash('status', 'Template deleted. Policies it already created are unaffected.');
    }

    public function render(BrowserPolicyTemplateService $templates)
    {
        return view('livewire.browser-policies.browser-policy-templates', [
            'templates' => $templates->all(),
            'projects'  => Project::orderBy('name')->get(['id', 'name']),
        ])->layout('layouts.app');
    }
}
