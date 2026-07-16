<?php

namespace App\Livewire\Projects;

use App\Models\Project;
use App\Services\EnrollmentScriptService;
use Livewire\Component;

/**
 * Ready-to-run enrollment scripts for a project.
 *
 * The API key is never a property of this component. Livewire serialises
 * public properties into the page and round-trips them on every update, so
 * binding the key here would put a live fleet credential in the DOM and post
 * it on each keystroke. The scripts render with a placeholder and the browser
 * substitutes the key locally — it never reaches the server, which is also
 * why there is nothing here to store.
 */
class ProjectEnrollment extends Component
{
    public Project $project;

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
        $all = $scripts->all($this->project, null);

        // A stale method in the URL should not fatal the page.
        $method = array_key_exists($this->method, $all) ? $this->method : 'gpo';

        return view('livewire.projects.project-enrollment', [
            'methods'     => $all,
            'current'     => $all[$method],
            'selected'    => $method,
            'placeholder' => EnrollmentScriptService::KEY_PLACEHOLDER,
            'keyPattern'  => EnrollmentScriptService::KEY_PATTERN,
        ])->layout('layouts.app');
    }
}
