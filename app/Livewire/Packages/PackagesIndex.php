<?php

namespace App\Livewire\Packages;

use App\Models\Package;
use App\Repositories\Contracts\PackageRepositoryInterface;
use App\Services\PackageService;
use Livewire\Component;
use App\Livewire\Concerns\WithCompactPagination;

class PackagesIndex extends Component
{
    use WithCompactPagination;

    public string $search = '';

    public ?int $categoryId = null;

    public string $installerType = '';

    public bool $activeOnly = false;

    public bool $showTrashed = false;

    public function updating($name, $value): void
    {
        if (in_array($name, ['search', 'categoryId', 'installerType', 'activeOnly', 'showTrashed'], true)) {
            $this->resetPage();
        }
    }

    public function toggleActive(int $packageId, PackageService $service): void
    {
        $package = Package::findOrFail($packageId);
        $this->authorize('update', $package);

        $service->setActive($package, ! $package->is_active);
    }

    public function delete(int $packageId, PackageService $service): void
    {
        $package = Package::findOrFail($packageId);
        $this->authorize('delete', $package);

        $service->delete($package);
    }

    public function restore(int $packageId, PackageService $service): void
    {
        $package = Package::withTrashed()->findOrFail($packageId);
        $this->authorize('restore', $package);

        $service->restore($package);
    }

    public function render(PackageRepositoryInterface $packages)
    {
        $this->authorize('viewAny', Package::class);

        return view('livewire.packages.packages-index', [
            'packages'   => $packages->searchPaginated(
                search: $this->search,
                categoryId: $this->categoryId,
                installerType: $this->installerType ?: null,
                activeOnly: $this->activeOnly ?: null,
                withTrashed: $this->showTrashed,
                visibleToClientId: auth()->user()->tenantClientId(),
            ),
            'categories' => \App\Models\PackageCategory::orderBy('sort_order')->get(['id', 'name']),
            'types'      => \App\Enums\InstallerType::cases(),
        ])->layout('layouts.app');
    }
}
