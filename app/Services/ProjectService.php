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
            'api_key_hash'   => hash('sha256', $plainKey),
            'api_key_prefix' => substr($plainKey, 0, 12),
            'download_token' => $this->generateDownloadToken(),
        ]);

        return ['project' => $project, 'plain_api_key' => $plainKey];
    }

    public function update(Project $project, ProjectData $data): Project
    {
        $this->projects->update($project, $data->toProjectAttributes());

        return $project->fresh('client');
    }

    /**
     * Invalidate the current key and issue a new one (shown once).
     */
    public function rotateApiKey(Project $project): string
    {
        $plainKey = $this->generateApiKey();

        $this->projects->update($project, [
            'api_key_hash'       => hash('sha256', $plainKey),
            'api_key_prefix'     => substr($plainKey, 0, 12),
            'api_key_rotated_at' => now(),
        ]);

        activity('projects')
            ->causedBy(auth()->user())
            ->performedOn($project)
            ->log('api_key_rotated');

        return $plainKey;
    }

    public function delete(Project $project): void
    {
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
