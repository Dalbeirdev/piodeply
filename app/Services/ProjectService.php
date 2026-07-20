<?php

namespace App\Services;

use App\DTOs\ProjectData;
use App\Models\Project;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use Illuminate\Support\Str;

class ProjectService
{
    public function __construct(
        private readonly ProjectRepositoryInterface $projects,
    ) {
    }

    /**
     * Create a project with a fresh API key + download token.
     * The plaintext key is returned exactly once and never stored.
     *
     * @return array{project: Project, plain_api_key: string}
     */
    public function create(ProjectData $data): array
    {
        $plainKey = $this->generateApiKey();

        /** @var Project $project */
        $project = $this->projects->create($data->toProjectAttributes() + [
            // Legacy columns, kept in step until nothing reads them; the
            // authoritative copy is the project_api_keys row below.
            'api_key_hash'   => hash('sha256', $plainKey),
            'api_key_prefix' => substr($plainKey, 0, 12),
            'download_token' => $this->generateDownloadToken(),
        ]);

        $project->apiKeys()->create([
            'label'      => 'Primary key',
            'key_hash'   => hash('sha256', $plainKey),
            'key_prefix' => substr($plainKey, 0, 12),
            'created_by' => auth()->id(),
        ]);

        return ['project' => $project, 'plain_api_key' => $plainKey];
    }

    public function update(Project $project, ProjectData $data): Project
    {
        $this->projects->update($project, $data->toProjectAttributes());

        return $project->fresh('client');
    }

    /**
     * Issues an additional key (shown once). Existing keys — and every
     * machine enrolled with them — are untouched: keys are per-site or
     * per-wave credentials, not one shared secret.
     */
    public function createApiKey(Project $project, string $label): array
    {
        $plainKey = $this->generateApiKey();

        $key = $project->apiKeys()->create([
            'label'      => trim($label) !== '' ? trim($label) : 'Key '.now()->format('Y-m-d'),
            'key_hash'   => hash('sha256', $plainKey),
            'key_prefix' => substr($plainKey, 0, 12),
            'created_by' => auth()->id(),
        ]);

        activity('projects')
            ->causedBy(auth()->user())
            ->performedOn($project)
            ->withProperties(['label' => $key->label, 'prefix' => $key->key_prefix])
            ->log('api_key_created');

        return ['key' => $key, 'plain_api_key' => $plainKey];
    }

    /**
     * Revokes one key. Only agents enrolled with THIS key stop
     * authenticating; machines on the project's other keys never notice.
     */
    public function revokeApiKey(\App\Models\ProjectApiKey $key): void
    {
        if (! $key->isActive()) {
            return; // already revoked — nothing to do, nothing to log twice
        }

        $key->forceFill(['revoked_at' => now()])->save();

        activity('projects')
            ->causedBy(auth()->user())
            ->performedOn($key->project)
            ->withProperties(['label' => $key->label, 'prefix' => $key->key_prefix])
            ->log('api_key_revoked');
    }

    /**
     * Kept for the existing ⚿ action: now it ADDS a key rather than
     * replacing the only one — rotating during a rollout used to stop
     * every enrolled agent at once. Retiring the old key is a separate,
     * deliberate revoke.
     */
    public function rotateApiKey(Project $project): string
    {
        return $this->createApiKey($project, 'Rotated '.now()->format('Y-m-d'))['plain_api_key'];
    }

    public function delete(Project $project): void
    {
        // Machines anchor history, jobs, and compliance records; a project
        // cannot vanish from under them. Retired (soft-deleted) machines
        // count — retirement parks a machine, it does not erase it.
        $active = $project->computers()->count();
        $retired = $project->computers()->onlyTrashed()->count();

        if ($active + $retired > 0) {
            throw new \App\Exceptions\ProjectHasMachinesException($active, $retired);
        }

        $this->projects->delete($project); // soft delete
    }

    public function restore(Project $project): void
    {
        $this->projects->restore($project);
    }

    private function generateApiKey(): string
    {
        return Project::API_KEY_PREFIX . Str::random(40);
    }

    private function generateDownloadToken(): string
    {
        do {
            $token = Str::lower(Str::random(32));
        } while (Project::withTrashed()->where('download_token', $token)->exists());

        return $token;
    }
}
