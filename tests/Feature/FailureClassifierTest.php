<?php

namespace Tests\Feature;

use App\Enums\FailureKind;
use App\Models\DeploymentJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The engine used to ask only "have the retries run out?", so a machine with
 * no disk space looked identical to a package that can never install: three
 * attempts, then a red row. The classifier separates a failure a retry can
 * clear from one that needs a person — and which person.
 */
class FailureClassifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_momentary_clash_is_worth_retrying(): void
    {
        // Another install holding the installer lock.
        $this->assertSame(FailureKind::Transient, DeploymentJob::classifyFailure(1618));
        $this->assertTrue(DeploymentJob::classifyFailure(1618)->shouldRetry());
    }

    public function test_a_package_that_cannot_install_here_is_not_retried(): void
    {
        foreach ([
            -1978335216, // no applicable installer
            -1978335146, // no machine-wide installer
            -1978335090, // install technology mismatch (Edge)
            -1978335212, // MSIX under a machine-wide install (Teams)
        ] as $code) {
            $this->assertSame(FailureKind::Package, DeploymentJob::classifyFailure($code), "code {$code}");
            $this->assertFalse(DeploymentJob::classifyFailure($code)->shouldRetry());
        }
    }

    /** The case the old engine could not tell apart from a broken package. */
    public function test_a_machine_that_cannot_accept_installs_is_not_retried(): void
    {
        foreach ([112, 3010, -2147024891, -1073741515] as $code) {
            $kind = DeploymentJob::classifyFailure($code);

            $this->assertSame(FailureKind::Machine, $kind, "code {$code}");
            $this->assertFalse($kind->shouldRetry());
            $this->assertFalse($kind->ownedByOperator(), 'a machine problem belongs to whoever manages that machine');
        }
    }

    public function test_a_package_problem_belongs_to_the_operator(): void
    {
        $this->assertTrue(DeploymentJob::classifyFailure(-1978335216)->ownedByOperator());
    }

    /**
     * Unknown still retries. A bare exit 1 is the commonest unclassified
     * failure and plenty of those clear on a second pass — refusing to retry
     * everything uncatalogued would stop retrying most real failures. It
     * earns attention once the retries are spent, not instead of them.
     */
    public function test_an_unrecognised_code_still_retries_but_is_owned_by_the_operator(): void
    {
        foreach ([1, -999999] as $code) {
            $kind = DeploymentJob::classifyFailure($code);

            $this->assertSame(FailureKind::Unknown, $kind, "code {$code}");
            $this->assertTrue($kind->shouldRetry(), 'a generic installer failure is still worth one more pass');
            $this->assertTrue($kind->ownedByOperator());
        }
    }

    public function test_no_exit_code_is_unknown_not_transient(): void
    {
        $this->assertSame(FailureKind::Unknown, DeploymentJob::classifyFailure(null));
    }

    public function test_a_job_classifies_its_own_exit_code(): void
    {
        $job = DeploymentJob::factory()->make(['exit_code' => 112]);

        $this->assertSame(FailureKind::Machine, $job->failureKind());
        $this->assertStringContainsString('machine', $job->failureKind()->label());
    }
}
