<?php

namespace App\Services;

use App\Enums\InstallerType;
use App\Enums\PolicyMode;
use App\Models\Package;
use App\Models\PolicyTemplate;
use App\Models\Project;
use App\Models\SoftwarePolicy;
use Illuminate\Support\Str;

/**
 * Turns a template into real policies on a project — and a project's
 * policies back into a template. Applying is idempotent: a policy that
 * already exists (same package + action) is skipped, never duplicated,
 * so re-applying after adding apps to a template only fills the gaps.
 */
class PolicyTemplateService
{
    /** @return array{created: int, skipped: int} */
    public function applyToProject(PolicyTemplate $template, Project $project, ?int $userId): array
    {
        $created = $skipped = 0;

        foreach ($template->items as $item) {
            $package = $this->findOrCreatePackage($item->winget_id, $item->package_name);

            $exists = SoftwarePolicy::query()
                ->where('project_id', $project->id)
                ->where('package_id', $package->id)
                ->where('action', $item->action)
                ->exists();

            if ($exists) {
                $skipped++;

                continue;
            }

            SoftwarePolicy::create([
                'project_id'   => $project->id,
                'package_id'   => $package->id,
                'action'       => $item->action,
                'mode'         => $item->mode,
                'version_mode' => $item->version_mode,
                'frequency'    => $item->frequency,
                'created_by'   => $userId,
            ]);
            $created++;
        }

        activity('policies')
            ->causedBy($userId ? \App\Models\User::find($userId) : null)
            ->performedOn($project)
            ->withProperties(['template' => $template->name, 'created' => $created, 'skipped' => $skipped])
            ->log('policy_template_applied');

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Snapshot a project's winget-based policies as a new template. Policies
     * on packages without a winget id are skipped — a template must be
     * portable, and only the winget identity travels.
     *
     * @return array{template: PolicyTemplate, captured: int, skipped: int}
     */
    public function createFromProject(Project $project, string $name, ?string $description, ?int $userId): array
    {
        $template = PolicyTemplate::create([
            'name'        => $name,
            'description' => $description,
            'is_builtin'  => false,
            'created_by'  => $userId,
        ]);

        $captured = $skipped = 0;
        $sort = 0;

        $policies = SoftwarePolicy::with('package')
            ->where('project_id', $project->id)
            ->where('mode', '!=', PolicyMode::Disabled)
            ->get();

        foreach ($policies as $policy) {
            if (empty($policy->package?->winget_id)) {
                $skipped++;

                continue;
            }

            $template->items()->create([
                'winget_id'    => $policy->package->winget_id,
                'package_name' => $policy->package->name,
                'action'       => $policy->action,
                'mode'         => $policy->mode,
                'version_mode' => $policy->version_mode,
                'frequency'    => $policy->frequency,
                'sort_order'   => $sort++,
            ]);
            $captured++;
        }

        return ['template' => $template, 'captured' => $captured, 'skipped' => $skipped];
    }

    /**
     * The catalogue package behind a winget id, created on the spot when
     * the catalogue does not have it yet — a template must work on a fresh
     * install without anyone pre-building the catalogue.
     */
    private function findOrCreatePackage(string $wingetId, string $name): Package
    {
        $package = Package::where('winget_id', $wingetId)->first();
        if ($package !== null) {
            return $package;
        }

        $slug = Str::slug($name);
        if (Package::where('slug', $slug)->exists()) {
            $slug .= '-'.Str::lower(Str::random(4));
        }

        return Package::create([
            'package_category_id' => \App\Models\PackageCategory::firstOrCreate(
                ['slug' => 'general'],
                ['name' => 'General', 'sort_order' => 99],
            )->id,
            'name'           => $name,
            'slug'           => $slug,
            'winget_id'      => $wingetId,
            'installer_type' => InstallerType::Winget,
            'is_active'      => true,
        ]);
    }
}
