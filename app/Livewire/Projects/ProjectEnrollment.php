<?php

namespace App\Livewire\Projects;

use App\Models\Project;
use App\Services\EnrollmentScriptService;
use Livewire\Component;

/**
 * Ready-to-run enrollment scripts for a project.
 *
 * The API key is typed in by the operator and only ever lives in the
 * component's request state — it is hashed in the database and cannot be
 * read back, so there is nothing to pre-fill and nothing here stores it.
 */
class ProjectEnrollment extends Component
{
    public Project $project;

    /** Pasted by the operator; never persisted. */
    public string $apiKey = '';

    public string $method = 'gpo';

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);
        $this->project = $project->load('client');
    }

    public function select(string $method): void
    {
        $this->method = $method;
    }

    public function render(EnrollmentScriptService $scripts)
    {
        $all = $scripts->all($this->project, $this->apiKey);

        // A stale method in the URL should not fatal the page.
        $method = array_key_exists($this->method, $all) ? $this->method : 'gpo';

        $typed = trim($this->apiKey);

        return view('livewire.projects.project-enrollment', [
            'methods'  => $all,
            'current'  => $all[$method],
            'selected' => $method,
            'hasKey'   => $typed !== '',
            // Silently swapping in the placeholder would look like the key
            // simply did not take. Say which it is.
            'keyRejected' => $typed !== '' && ! EnrollmentScriptService::looksLikeAKey($typed),
        ])->layout('layouts.app');
    }
}
