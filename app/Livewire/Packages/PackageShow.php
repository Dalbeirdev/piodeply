<?php

namespace App\Livewire\Packages;

use App\Models\Package;
use App\Models\PackageVersion;
use App\Services\PackageService;
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

    public function mount(Package $package): void
    {
        $this->authorize('view', $package);
        $this->package = $package->load(['category', 'versions']);
    }

    public function addVersion(PackageService $service): void
    {
        $this->authorize('update', $this->package);

        $requiresBinary = $this->package->installer_type->requiresBinary();

        $validated = $this->validate([
            'version'        => ['required', 'string', 'max:100',
                \Illuminate\Validation\Rule::unique('package_versions', 'version')->where('package_id', $this->package->id)],
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

    public function render()
    {
        return view('livewire.packages.package-show')->layout('layouts.app');
    }
}
