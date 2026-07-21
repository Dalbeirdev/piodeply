<?php

namespace App\Livewire\Packages;

use App\Enums\JobAction;
use App\Enums\JobStatus;
use App\Models\Computer;
use App\Models\DeploymentJob;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Services\DeploymentService;
use App\Services\PackageService;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Component;

class PackageShow extends Component
{
    public Package $package;

    // Add-version form state
    public string $version = '';
    public ?string $installer_url = null;
    public ?string $sha256 = null;
    public ?string $silent_args = null;
    public ?string $uninstall_args = null;
    public ?string $release_date = null;

    // Quick-deploy state
    public ?int $deploy_computer_id = null;
    public string $deploy_action = 'install';
    public int $deploy_priority = 5;

    public function mount(Package $package): void
    {
        $this->authorize('view', $package);
        $this->package = $package->load(['category', 'versions']);
    }

    public function deploy(DeploymentService $service): void
    {
        $this->authorize('create', DeploymentJob::class);

        $validated = $this->validate([
            'deploy_computer_id' => ['required', 'integer', Rule::exists('computers', 'id')->whereNull('deleted_at')],
            'deploy_action'      => ['required', Rule::in(JobAction::values())],
            'deploy_priority'    => ['required', 'integer', 'between:1,10'],
        ]);

        $service->queue(
            computer: Computer::findOrFail($validated['deploy_computer_id']),
            package: $this->package,
            action: JobAction::from($validated['deploy_action']),
            priority: (int) $validated['deploy_priority'],
            createdBy: auth()->id(),
        );

        $this->reset('deploy_computer_id');
        session()->flash('status', 'Deployment queued.');
    }

    public function addVersion(PackageService $service): void
    {
        $this->authorize('update', $this->package);

        $requiresBinary = $this->package->installer_type->requiresBinary();

        $validated = $this->validate([
            'version'        => ['required', 'string', 'max:100',
                Rule::unique('package_versions', 'version')->where('package_id', $this->package->id)],
            'installer_url'  => [$requiresBinary ? 'required' : 'nullable', 'url', 'starts_with:https://,http://localhost', 'max:2048'],
            'sha256'         => [$requiresBinary ? 'required' : 'nullable', 'regex:/^[a-fA-F0-9]{64}$/'],
            'silent_args'    => ['nullable', 'string', 'max:255'],
            'uninstall_args' => ['nullable', 'string', 'max:255'],
            'release_date'   => ['nullable', 'date'],
        ], [
            'sha256.regex'              => 'SHA-256 must be 64 hex characters.',
            'installer_url.starts_with' => 'Installer URLs must use HTTPS.',
        ]);

        try {
            $service->addVersion($this->package, $validated);
        } catch (InvalidArgumentException $e) {
            $this->addError('installer_url', $e->getMessage());

            return;
        }

        $this->reset('version', 'installer_url', 'sha256', 'silent_args', 'uninstall_args', 'release_date');
        $this->package->refresh()->load('versions');
        $this->dispatch('version-added');
    }

    public function markLatest(int $versionId, PackageService $service): void
    {
        $this->authorize('update', $this->package);

        $version = PackageVersion::where('package_id', $this->package->id)->findOrFail($versionId);
        $service->markLatest($version);
        $this->package->refresh()->load('versions');
    }

    public function removeVersion(int $versionId, PackageService $service): void
    {
        $this->authorize('update', $this->package);

        $version = PackageVersion::where('package_id', $this->package->id)->findOrFail($versionId);
        $service->removeVersion($version);
        $this->package->refresh()->load('versions');
    }

    /**
     * What this package's versions actually look like on the fleet.
     *
     * A winget/choco package has no version WE own — winget resolves one at
     * install time — but the agents report what they found. "auto" was
     * honest and useless; this answers the real questions: what is out
     * there, what is the newest anyone has been offered, and how many
     * machines are behind it.
     *
     * @return array{latest: ?string, installed: \Illuminate\Support\Collection, outdated: int, tracked: int}
     */
    private function fleetVersions(): array
    {
        $id = $this->package->winget_id ?? $this->package->choco_id;
        $source = $this->package->winget_id !== null ? 'winget' : 'choco';

        if ($id === null) {
            return ['latest' => null, 'installed' => collect(), 'outdated' => 0, 'tracked' => 0];
        }

        $rows = \App\Models\ComputerSoftware::query()
            ->where('source', $source)
            ->where('name', $id)
            // Tenancy: a customer's view of "the fleet" is their own fleet.
            ->when(auth()->user()->tenantClientId() !== null, fn ($q) => $q
                ->whereHas('computer.project', fn ($p) => $p
                    ->where('client_id', auth()->user()->tenantClientId())))
            ->get(['version', 'available_version']);

        // Newest version anyone has been offered — the closest thing to a
        // true "latest" for a source-resolved package.
        $latest = $rows->pluck('available_version')
            ->merge($rows->pluck('version'))
            ->filter(fn (?string $v) => $v !== null && trim($v) !== '')
            ->sort(fn ($a, $b) => version_compare($a, $b))
            ->last();

        return [
            'latest'    => $latest,
            'installed' => $rows->pluck('version')
                ->filter(fn (?string $v) => $v !== null && trim($v) !== '')
                ->countBy()
                ->sortKeysDesc(),
            'outdated'  => $rows->filter->hasUpdate()->count(),
            'tracked'   => $rows->count(),
        ];
    }

    public function render()
    {
        $jobs = DeploymentJob::where('package_id', $this->package->id);

        $stats = [
            'installed_on' => (clone $jobs)->where('status', JobStatus::Succeeded)
                ->whereIn('action', [JobAction::Install, JobAction::Update])
                ->distinct('computer_id')->count('computer_id'),
            'in_flight'    => (clone $jobs)->whereIn('status', [JobStatus::Pending, JobStatus::Blocked, JobStatus::Running])->count(),
            'failed'       => (clone $jobs)->where('status', JobStatus::Failed)->count(),
            'last_deploy'  => (clone $jobs)->where('status', JobStatus::Succeeded)->max('finished_at'),
        ];

        return view('livewire.packages.package-show', [
            'stats'      => $stats,
            'recentJobs' => DeploymentJob::with('computer')
                ->where('package_id', $this->package->id)
                ->orderByDesc('id')->limit(8)->get(),
            'computers'  => auth()->user()->can('create', DeploymentJob::class)
                ? Computer::orderBy('hostname')->get(['id', 'hostname'])
                : collect(),
            'actions'    => JobAction::cases(),
            'fleet'      => $this->fleetVersions(),
        ])->layout('layouts.app');
    }
}
