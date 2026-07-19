<?php

namespace App\Http\Controllers;

use App\Models\BrowserPolicy;
use App\Models\Project;
use App\Services\BrowserPolicyTemplateService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Portable JSON downloads of browser-policy sets — a project's current
 * policies, or any template (built-in or custom). The file re-imports on the
 * Templates page of any PioDeploy instance.
 */
class BrowserPolicyExportController extends Controller
{
    public function project(Project $project, BrowserPolicyTemplateService $templates)
    {
        Gate::authorize('create', BrowserPolicy::class);

        abort_if(BrowserPolicy::where('project_id', $project->id)->doesntExist(), 404, 'That project has no browser policies to export.');

        return $this->download($templates->exportProject($project));
    }

    public function template(string $key, BrowserPolicyTemplateService $templates)
    {
        Gate::authorize('create', BrowserPolicy::class);

        $template = $templates->find($key);

        abort_if($template === null, 404, 'Template not found.');

        return $this->download($templates->exportTemplate($template));
    }

    private function download(array $document)
    {
        $filename = Str::slug($document['name']).'.piodeploy-policies.json';

        return response()->json($document, 200, [
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
