<?php

namespace Tests\Feature;

use App\Enums\InstallerType;
use App\Enums\JobAction;
use App\Enums\JobStatus;
use App\Enums\PackageMode;
use App\Http\Controllers\Api\AgentJobController;
use App\Models\Client;
use App\Models\Computer;
use App\Models\DeploymentJob;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\Project;
use App\Services\DeploymentService;
use App\Services\InstalledStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The path a genuine "vendor MSI/EXE, opt-in, verified" candidate depends on
 * — InstallerType::Exe/Msi with a real installer_url + sha256 + silent
 * switches, existing infrastructure the fallback-ladder recommendation was
 * built on. It had only field-validation coverage before this; nothing
 * proved the actual deploy → agent-payload → installed-state loop, or that
 * PackageMode governs a binary package exactly like a winget one.
 *
 * No package is reactivated here. Two live vendor lookups (FileZilla's
 * official wiki and forum) both returned 403 while researching a genuine
 * candidate for this phase — the one source found (FileZillaPro.com) names
 * the PAID Pro product, not the free Client this catalogue would carry, so
 * "/user=all" cannot be confirmed for it. Writing an unverified silent
 * switch into a job an agent runs unattended as SYSTEM, across a live
 * fleet, is exactly the risk this project keeps refusing to take on a guess
 * (Edge, Teams, the browser-policy registry keys). This file proves the
 * MECHANISM is sound and ready for whichever verified candidate comes next.
 */
class BinaryPackageDeploymentTest extends TestCase
{
    use RefreshDatabase;

    private function computer(): Computer
    {
        $project = Project::factory()->create(['client_id' => Client::factory()->create()->id]);

        return Computer::factory()->create(['project_id' => $project->id]);
    }

    private function binaryPackage(array $overrides = []): Package
    {
        return Package::factory()->create(array_merge([
            'installer_type' => InstallerType::Exe,
            'winget_id'      => null,
            'management_mode' => PackageMode::Deploy,
        ], $overrides));
    }

    // ── management_mode governs a binary package exactly like a winget one ──

    public function test_a_deploy_mode_binary_package_queues_normally(): void
    {
        $package = $this->binaryPackage();
        PackageVersion::factory()->create([
            'package_id' => $package->id, 'version' => '3.2.1', 'is_latest' => true,
            'installer_url' => 'https://example.test/app-3.2.1.exe', 'sha256' => str_repeat('a', 64),
            'silent_args' => '/S', 'uninstall_args' => '/uninstall /S',
        ]);

        $job = app(DeploymentService::class)->queue(
            computer: $this->computer(), package: $package, action: JobAction::Install,
        );

        $this->assertInstanceOf(DeploymentJob::class, $job);
    }

    /**
     * The whole reason PackageMode exists: a binary package awaiting
     * verification (exactly the state a researched-but-unconfirmed vendor
     * candidate would sit in) must be refused identically to Edge or Teams
     * — never installable "because it happens to be Exe type".
     */
    public function test_an_unsupported_binary_package_is_refused_the_same_as_any_other(): void
    {
        $package = $this->binaryPackage(['management_mode' => PackageMode::Unsupported]);

        $this->expectException(\DomainException::class);

        app(DeploymentService::class)->queue(
            computer: $this->computer(), package: $package, action: JobAction::Install,
        );
    }

    // ── The agent actually receives what it needs to run the installer ──

    public function test_the_agent_payload_carries_the_vendor_installer_details(): void
    {
        $package = $this->binaryPackage();
        PackageVersion::factory()->create([
            'package_id' => $package->id, 'version' => '3.2.1', 'is_latest' => true,
            'installer_url' => 'https://example.test/app-3.2.1.exe',
            'sha256'        => str_repeat('b', 64),
            'silent_args'   => '/S /user=all',
            'uninstall_args' => '/uninstall /S',
        ]);
        $job = DeploymentJob::factory()->create([
            'package_id' => $package->id, 'computer_id' => $this->computer()->id,
            'action' => JobAction::Install, 'status' => JobStatus::Running,
        ]);

        $payload = (new \ReflectionMethod(AgentJobController::class, 'transform'))
            ->invoke(app(AgentJobController::class), $job);

        $this->assertSame('exe', $payload['installer_type']);
        $this->assertSame('https://example.test/app-3.2.1.exe', $payload['installer_url']);
        $this->assertSame(str_repeat('b', 64), $payload['sha256']);
        $this->assertSame('/S /user=all', $payload['silent_args']);
        $this->assertSame('/uninstall /S', $payload['uninstall_args']);
        // A binary package has no package-manager id — nothing here should
        // fall back to a stale/unrelated winget or choco value.
        $this->assertNull($payload['winget_id']);
        $this->assertNull($payload['choco_id']);
    }

    public function test_a_pinned_target_version_overrides_the_catalogues_latest(): void
    {
        $package = $this->binaryPackage();
        PackageVersion::factory()->create(['package_id' => $package->id, 'version' => '3.2.1', 'is_latest' => true]);
        $older = PackageVersion::factory()->create(['package_id' => $package->id, 'version' => '3.1.0', 'is_latest' => false]);

        $job = DeploymentJob::factory()->create([
            'package_id' => $package->id, 'computer_id' => $this->computer()->id,
            'package_version_id' => $older->id, 'target_version' => '3.1.0',
            'action' => JobAction::Rollback, 'status' => JobStatus::Running,
        ]);

        $payload = (new \ReflectionMethod(AgentJobController::class, 'transform'))
            ->invoke(app(AgentJobController::class), $job);

        $this->assertSame('3.1.0', $payload['version']);
    }

    // ── Installed-state fallback for a package the inventory cannot identify ──

    public function test_presence_is_unknown_until_this_platform_has_installed_it(): void
    {
        $package = $this->binaryPackage();
        $computer = $this->computer();

        $state = app(InstalledStateService::class)->stateOf($package, $computer);

        $this->assertFalse($state['present'], 'nothing installed it yet, as far as this platform knows');
    }

    public function test_a_succeeded_install_job_is_what_makes_it_present(): void
    {
        $package = $this->binaryPackage();
        $computer = $this->computer();
        DeploymentJob::factory()->create([
            'package_id' => $package->id, 'computer_id' => $computer->id,
            'action' => JobAction::Install, 'status' => JobStatus::Succeeded,
        ]);

        $state = app(InstalledStateService::class)->stateOf($package, $computer);

        $this->assertTrue($state['present']);
        $this->assertNull($state['version'], 'a binary install carries no reported version — the inventory cannot confirm one');
    }

    // ── The capability matrix a vendor-EXE candidate must respect ──

    public function test_an_exe_package_cannot_be_asked_to_uninstall_or_roll_back(): void
    {
        $package = $this->binaryPackage();

        $this->assertFalse($package->installer_type->supports(JobAction::Uninstall));
        $this->assertFalse($package->installer_type->supports(JobAction::Rollback));
        $this->assertTrue($package->installer_type->supports(JobAction::Install));
        $this->assertTrue($package->installer_type->supports(JobAction::Update));
    }

    public function test_queue_if_needed_refuses_an_uninstall_for_an_exe_package_before_queueing(): void
    {
        $package = $this->binaryPackage();

        $result = app(DeploymentService::class)->queueIfNeeded(
            computer: $this->computer(), package: $package, action: JobAction::Uninstall,
        );

        $this->assertSame(\App\Enums\QueueOutcome::Invalid, $result->outcome);
        $this->assertSame(0, DeploymentJob::count());
    }

    public function test_msi_type_does_support_uninstall(): void
    {
        $package = $this->binaryPackage(['installer_type' => InstallerType::Msi]);

        $this->assertTrue($package->installer_type->supports(JobAction::Uninstall));
    }
}
