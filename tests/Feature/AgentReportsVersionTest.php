<?php

namespace Tests\Feature;

use App\DTOs\ProjectData;
use App\Enums\JobAction;
use App\Enums\JobStatus;
use App\Models\Client;
use App\Models\Computer;
use App\Models\DeploymentJob;
use App\Models\Package;
use App\Models\Project;
use App\Services\ProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Agent 1.3.0 reports what it found installed once a job finished, so the
 * portal can show what actually happened rather than what was asked for.
 * Older agents omit the field and must keep working.
 */
class AgentReportsVersionTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Computer $computer;

    /** The stored key is hashed; the plain one exists only at creation. */
    private string $apiKey;

    protected function setUp(): void
    {
        parent::setUp();

        $result = app(ProjectService::class)->create(new ProjectData(
            clientId: Client::factory()->create()->id,
            name: 'Version Reporting Fleet',
        ));

        $this->project = $result['project'];
        $this->apiKey = $result['plain_api_key'];
        $this->computer = Computer::factory()->create(['project_id' => $this->project->id]);
    }

    private function headers(): array
    {
        return ['X-Api-Key' => $this->apiKey, 'Accept' => 'application/json'];
    }

    private function job(array $attributes = []): DeploymentJob
    {
        return DeploymentJob::factory()->create([
            'computer_id' => $this->computer->id,
            'package_id'  => Package::factory()->create(['winget_id' => 'Google.Chrome'])->id,
            'action'      => JobAction::Install,
            'status'      => JobStatus::Running,
            ...$attributes,
        ]);
    }

    private function report(DeploymentJob $job, array $body)
    {
        return $this->postJson("/api/v1/agent/jobs/{$job->id}/result", [
            'agent_uuid' => $this->computer->agent_uuid,
            ...$body,
        ], $this->headers());
    }

    public function test_a_reported_version_is_stored_against_the_job(): void
    {
        $job = $this->job(['installed_version_before' => '138.0']);

        $this->report($job, ['success' => true, 'exit_code' => 0, 'installed_version' => '141.0'])
            ->assertOk();

        $job->refresh();
        $this->assertSame('141.0', $job->installed_version_after);
        $this->assertSame(JobStatus::Succeeded, $job->status);
    }

    public function test_the_list_shows_what_actually_landed_not_what_was_asked_for(): void
    {
        // "-> latest" resolves to a real number once the agent answers.
        $job = $this->job(['installed_version_before' => '138.0', 'target_version' => null]);
        $this->assertSame('138.0 → latest', $job->versionLabel());

        $this->report($job, ['success' => true, 'installed_version' => '141.0']);

        $this->assertSame('138.0 → 141.0', $job->refresh()->versionLabel());
    }

    public function test_a_target_that_did_not_take_is_visible_rather_than_assumed(): void
    {
        $job = $this->job(['installed_version_before' => '138.0', 'target_version' => '141.0']);

        // winget resolved something else; the label must not claim 141.0.
        $this->report($job, ['success' => true, 'installed_version' => '140.2']);

        $this->assertSame('138.0 → 140.2', $job->refresh()->versionLabel());
    }

    public function test_an_agent_older_than_1_3_0_still_reports_results(): void
    {
        $job = $this->job();

        $this->report($job, ['success' => true, 'exit_code' => 0])->assertOk();

        $job->refresh();
        $this->assertSame(JobStatus::Succeeded, $job->status);
        $this->assertNull($job->installed_version_after);
    }

    public function test_an_omitted_version_does_not_blank_one_an_earlier_attempt_recorded(): void
    {
        $job = $this->job(['installed_version_after' => '141.0', 'max_attempts' => 3, 'attempts' => 1]);

        // A retry from an older agent must not erase what we already knew.
        $this->report($job, ['success' => false, 'failure_reason' => 'transient'])->assertOk();

        $this->assertSame('141.0', $job->refresh()->installed_version_after);
    }

    public function test_a_failed_install_reports_the_version_still_present(): void
    {
        // Out of retries, so this failure is terminal.
        $job = $this->job(['installed_version_before' => '138.0', 'attempts' => 1, 'max_attempts' => 1]);

        $this->report($job, [
            'success'           => false,
            'failure_reason'    => 'winget exited with 1',
            'installed_version' => '138.0', // upgrade failed; still on the old one
        ])->assertOk();

        $job->refresh();
        $this->assertSame(JobStatus::Failed, $job->status);
        $this->assertSame('138.0', $job->installed_version_after);
    }

    public function test_an_over_long_version_is_rejected(): void
    {
        $this->report($this->job(), [
            'success'           => true,
            'installed_version' => str_repeat('9', 101),
        ])->assertStatus(422);
    }
}
