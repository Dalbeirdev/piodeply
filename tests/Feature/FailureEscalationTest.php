<?php

namespace Tests\Feature;

use App\Models\Computer;
use App\Models\DeploymentJob;
use App\Models\NotificationChannel;
use App\Models\Package;
use App\Services\DeploymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A terminal failure has to reach someone — once. A package that cannot
 * install anywhere fails on every machine it is sent to, and one message per
 * machine buries the single fact that matters under forty copies of it.
 */
class FailureEscalationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::fake();

        // A webhook channel, so each escalation is one observable HTTP call.
        NotificationChannel::factory()->webhook()->events(['job.failed'])->create();
    }

    private function reportFailure(DeploymentJob $job, int $exitCode): DeploymentJob
    {
        return app(DeploymentService::class)->reportResult(
            $job, success: false, exitCode: $exitCode, log: 'boom', failureReason: "winget exited with {$exitCode}."
        );
    }

    private function job(Package $package, Computer $computer): DeploymentJob
    {
        return DeploymentJob::factory()->running()->create([
            'package_id'   => $package->id,
            'computer_id'  => $computer->id,
            'max_attempts' => 1,
            'attempts'     => 1,
        ]);
    }

    /** The Edge/Teams case: one package, many machines, one message. */
    public function test_a_package_that_cannot_install_is_reported_once_not_once_per_machine(): void
    {
        $package = Package::factory()->create();

        foreach (Computer::factory()->count(3)->create() as $computer) {
            $this->reportFailure($this->job($package, $computer), -1978335212);
        }

        Http::assertSentCount(1);
    }

    /** A machine problem is genuinely per-machine, so each one is reported. */
    public function test_a_machine_problem_is_reported_for_each_machine(): void
    {
        $package = Package::factory()->create();

        foreach (Computer::factory()->count(3)->create() as $computer) {
            $this->reportFailure($this->job($package, $computer), 112); // disk full
        }

        Http::assertSentCount(3);
    }

    public function test_a_different_cause_on_the_same_package_is_reported_separately(): void
    {
        $package = Package::factory()->create();
        $computers = Computer::factory()->count(2)->create();

        $this->reportFailure($this->job($package, $computers[0]), -1978335212);
        $this->reportFailure($this->job($package, $computers[1]), -1978335090);

        Http::assertSentCount(2);
    }

    public function test_the_message_says_what_kind_of_problem_it_is_and_who_owns_it(): void
    {
        $this->reportFailure($this->job(Package::factory()->create(), Computer::factory()->create()), -1978335212);

        Http::assertSent(function ($request) {
            $body = json_encode($request->data());

            return str_contains($body, 'cannot install here')
                && str_contains($body, 'Platform administrator');
        });
    }

    public function test_a_machine_problem_is_not_blamed_on_the_package(): void
    {
        $this->reportFailure($this->job(Package::factory()->create(), Computer::factory()->create()), 112);

        Http::assertSent(fn ($request) => str_contains(
            json_encode($request->data()),
            'nothing is wrong with the package'
        ));
    }
}
