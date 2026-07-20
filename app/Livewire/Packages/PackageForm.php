<?php

namespace App\Livewire\Packages;

use App\Enums\Architecture;
use App\Enums\InstallerType;
use App\Models\Package;
use App\Models\PackageCategory;
use App\Services\PackageService;
use Illuminate\Validation\Rule;
use Livewire\Component;

class PackageForm extends Component
{
    public ?Package $package = null;

    public ?int $package_category_id = null;
    public string $name = '';
    public ?string $vendor = null;
    public ?string $homepage = null;
    public ?string $description = null;
    public ?string $license = null;
    public string $installer_type = 'winget';
    public string $architecture = 'x64';
    public ?string $winget_id = null;
    public ?string $choco_id = null;

    public function mount(?Package $package = null): void
    {
        if ($package !== null && $package->exists) {
            $this->authorize('update', $package);
            $this->package = $package;
            $this->fill($package->only([
                'package_category_id', 'name', 'vendor', 'homepage',
                'description', 'license', 'winget_id', 'choco_id',
            ]));
            $this->installer_type = $package->installer_type->value;
            $this->architecture = $package->architecture->value;
        } else {
            $this->authorize('create', Package::class);
        }
    }

    public function save(PackageService $service)
    {
        $this->authorize($this->package ? 'update' : 'create', $this->package ?? Package::class);

        $idRule = 'regex:' . Package::ID_PATTERN;

        $validated = $this->validate([
            'package_category_id' => ['required', 'integer', Rule::exists('package_categories', 'id')],
            'name'                => ['required', 'string', 'max:255'],
            'vendor'              => ['nullable', 'string', 'max:255'],
            'homepage'            => ['nullable', 'url', 'max:255'],
            'description'         => ['nullable', 'string', 'max:2000'],
            'license'             => ['nullable', 'string', 'max:100'],
            'installer_type'      => ['required', Rule::in(InstallerType::values())],
            'architecture'        => ['required', Rule::in(Architecture::values())],
            'winget_id'           => ['nullable', 'string', 'max:255', $idRule,
                Rule::requiredIf($this->installer_type === 'winget')],
            'choco_id'            => ['nullable', 'string', 'max:255', $idRule,
                Rule::requiredIf($this->installer_type === 'choco')],
        ], [
            'winget_id.regex'       => 'winget IDs may only contain letters, digits, ".", "-", "+" and "_".',
            'choco_id.regex'        => 'Chocolatey IDs may only contain letters, digits, ".", "-", "+" and "_".',
            'winget_id.required'    => 'winget packages need a winget ID.',
            'choco_id.required'     => 'Chocolatey packages need a Chocolatey ID.',
        ]);

        if ($this->package) {
            $service->update($this->package, $validated);
            session()->flash('status', 'Package saved.');

            return $this->redirectRoute('packages.show', $this->package);
        }

        // A tenant's package is born private to their client — always, not
        // optionally: a tenant cannot publish into the shared catalogue.
        if (auth()->user()->tenantClientId() !== null) {
            $validated['client_id'] = auth()->user()->tenantClientId();
        }

        $package = $service->create($validated);
        session()->flash('status', 'Package created. Add a version below if it ships as a binary installer.');

        return $this->redirectRoute('packages.show', $package);
    }

    public function render()
    {
        return view('livewire.packages.package-form', [
            'categories'    => PackageCategory::orderBy('sort_order')->get(['id', 'name']),
            'types'         => InstallerType::cases(),
            'architectures' => Architecture::cases(),
        ])->layout('layouts.app');
    }
}
