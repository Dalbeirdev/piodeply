<?php

namespace App\Console\Commands;

use App\Enums\JobAction;
use App\Enums\JobStatus;
use App\Models\BrowserPolicy;
use App\Models\Client;
use App\Models\Computer;
use App\Models\ComputerGroup;
use App\Models\ComputerSoftware;
use App\Models\DeploymentJob;
use App\Models\Package;
use App\Models\Project;
use App\Models\SoftwarePolicy;
use App\Services\BrowserPolicyService;
use App\Services\PolicyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Fleet-scale benchmark: seeds a synthetic fleet and times the hot paths an
 * agent fleet actually exercises — policy enforcement, the per-check-in
 * enforcement an agent report triggers, browser-policy document compilation,
 * and the dashboard aggregates. Prints wall time + query counts so a change
 * can be judged on numbers, not vibes.
 *
 * DESTRUCTIVE: refuses to run unless the database name contains "bench".
 *
 *   DB_DATABASE=piodeploy_bench php artisan perf:bench 500
 */
class PerfBench extends Command
{
    protected $signature = 'perf:bench {devices=100} {--fresh=1 : migrate:fresh + reseed}';

    protected $description = 'Seed a synthetic fleet and time the hot paths (bench DB only)';

    private int $queries = 0;

    public function handle(): int
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');

        if (! str_contains($database, 'bench')) {
            $this->error("Refusing: database '{$database}' does not contain 'bench'. Run with DB_DATABASE=piodeploy_bench.");

            return self::FAILURE;
        }

        $devices = max(10, (int) $this->argument('devices'));

        if ($this->option('fresh')) {
            $this->info('Migrating fresh…');
            Artisan::call('migrate:fresh', ['--force' => true]);
            $this->seed($devices);
        }

        DB::listen(function () {
            $this->queries++;
        });

        $this->line('');
        $this->info("── Benchmark @ {$devices} devices ──");
        $rows = [];

        $rows[] = $this->measure('policies:enforce (first pass — queues rollout jobs)', fn () => app(PolicyService::class)->enforceAll());

        // The number that matters: the scheduler runs this every 5 minutes,
        // and in steady state nothing needs queueing.
        $rows[] = $this->measure('policies:enforce (steady state)', fn () => app(PolicyService::class)->enforceAll());

        $sample = Computer::inRandomOrder()->limit(25)->get();
        $rows[] = $this->measure('agent software report → enforceForComputer ×25', function () use ($sample) {
            foreach ($sample as $computer) {
                app(PolicyService::class)->enforceForComputer($computer);
            }
        }, perUnit: 25);

        $rows[] = $this->measure('browser-policy documentFor ×25', function () use ($sample) {
            foreach ($sample as $computer) {
                app(BrowserPolicyService::class)->documentFor($computer);
            }
        }, perUnit: 25);

        $rows[] = $this->measure('browser fleetSummary (dashboard widget)', fn () => app(BrowserPolicyService::class)->fleetSummary(null));

        $rows[] = $this->measure('computers list query (first page)', fn () => Computer::with('project.client')->orderBy('hostname')->limit(15)->get());

        $rows[] = $this->measure('dashboard counts', function () {
            Computer::online()->count();
            Computer::offline()->count();
            Computer::agentOutdated()->count();
            DeploymentJob::whereIn('status', [JobStatus::Pending, JobStatus::Blocked, JobStatus::Running])->count();
            DeploymentJob::where('status', JobStatus::Failed)->count();
            ComputerSoftware::count();
        });

        $this->table(['Path', 'Wall ms', 'Queries', 'ms/unit'], $rows);

        return self::SUCCESS;
    }

    /** @return array{0: string, 1: string, 2: int, 3: string} */
    private function measure(string $label, callable $fn, int $perUnit = 1): array
    {
        $this->queries = 0;
        $start = hrtime(true);
        $fn();
        $ms = (hrtime(true) - $start) / 1e6;

        return [$label, number_format($ms, 1), $this->queries, number_format($ms / $perUnit, 1)];
    }

    private function seed(int $devices): void
    {
        $this->info("Seeding {$devices} devices…");

        $client = Client::factory()->create(['company_name' => 'Bench Client']);
        $projects = Project::factory()->count(max(1, intdiv($devices, 50)))->create(['client_id' => $client->id]);

        // A ten-package winget catalogue and one policy per package spread
        // across projects: a realistic "keep the basics current" setup.
        $packages = collect(range(1, 10))->map(fn (int $i) => Package::factory()->create([
            'name' => "Bench App {$i}", 'winget_id' => "Bench.App{$i}",
        ]));

        foreach ($projects as $pi => $project) {
            foreach ($packages->take(5) as $ni => $package) {
                SoftwarePolicy::factory()->create([
                    'project_id' => $project->id,
                    'package_id' => $package->id,
                    'action'     => $ni < 3 ? 'install' : 'update',
                ]);
            }
        }

        $group = ComputerGroup::factory()->create(['name' => 'Bench pilot ring']);
        BrowserPolicy::factory()->create(['project_id' => null, 'scope_type' => 'all', 'scope_id' => 0, 'type' => 'disable_incognito', 'name' => 'Bench all incognito']);
        BrowserPolicy::factory()->create(['project_id' => null, 'scope_type' => 'group', 'scope_id' => $group->id, 'type' => 'disable_downloads', 'name' => 'Bench group downloads']);

        $now = now();
        $projects->each(function (Project $project) use ($devices, $packages, $group, $now) {
            $perProject = intdiv($devices, max(1, intdiv($devices, 50)));

            for ($i = 0; $i < $perProject; $i++) {
                $computer = Computer::factory()->create([
                    'project_id'   => $project->id,
                    'last_seen_at' => $now->copy()->subMinutes(rand(1, 30)),
                ]);

                // Inventory: 5 managed winget rows + 25 registry rows.
                $software = [];
                foreach ($packages->take(5) as $package) {
                    $software[] = [
                        'computer_id' => $computer->id, 'name' => $package->winget_id,
                        'version' => '1.0.0', 'available_version' => rand(0, 4) === 0 ? '2.0.0' : null,
                        'source' => 'winget', 'created_at' => $now, 'updated_at' => $now,
                    ];
                }
                for ($r = 0; $r < 25; $r++) {
                    $software[] = [
                        'computer_id' => $computer->id, 'name' => "Registry App {$r}",
                        'version' => '1.0', 'available_version' => null,
                        'source' => 'registry', 'created_at' => $now, 'updated_at' => $now,
                    ];
                }
                ComputerSoftware::insert($software);

                // Job history: a succeeded install for three packages.
                DeploymentJob::insert($packages->take(3)->map(fn (Package $package) => [
                    'computer_id' => $computer->id, 'package_id' => $package->id,
                    'action' => JobAction::Install->value, 'status' => JobStatus::Succeeded->value,
                    'priority' => 5, 'attempts' => 1, 'max_attempts' => 3,
                    'finished_at' => $now->copy()->subDays(2), 'created_at' => $now, 'updated_at' => $now,
                ])->all());

                if ($computer->id % 10 === 0) {
                    $group->computers()->attach($computer->id);
                }
            }
        });

        $this->info('Seeded: '.Computer::count().' computers, '.ComputerSoftware::count().' software rows, '
            .SoftwarePolicy::count().' policies, '.DeploymentJob::count().' jobs.');
    }
}
