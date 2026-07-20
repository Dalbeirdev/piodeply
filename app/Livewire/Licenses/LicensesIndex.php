<?php

namespace App\Livewire\Licenses;

use App\Enums\Permission;
use App\Models\Client;
use App\Models\Computer;
use App\Models\SoftwareLicense;
use App\Services\LicenseService;
use Livewire\Component;

/**
 * Paid-license register. A tenant sees and manages exactly their own
 * licenses; staff see every client's (metadata + key fingerprint — the
 * key VALUE stays the owning tenant's secret). All mutating paths guard
 * ownership in the service, so a smuggled id changes nothing.
 */
class LicensesIndex extends Component
{
    public string $search = '';

    public ?int $clientFilter = null; // staff only

    // Create/edit form state.
    public ?int $editingId = null;

    public string $name = '';

    public string $vendor = '';

    public ?int $packageId = null;

    public ?int $formClientId = null; // staff pick a client; tenants are forced

    public string $licenseKey = '';

    public ?int $seats = null;

    public ?string $expiresAt = null;

    public string $notes = '';

    /** license id => chosen computer id for the assign row. */
    public array $assignComputer = [];

    /** license id currently showing its revealed key (owner only). */
    public ?int $revealedId = null;

    public ?string $revealedKey = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->can(Permission::LicensesView->value), 403);
    }

    private function tenantId(): ?int
    {
        return auth()->user()->tenantClientId();
    }

    /** The licenses this user may see. */
    private function visibleLicenses()
    {
        return SoftwareLicense::query()
            ->with(['package', 'client', 'assignments.computer'])
            ->when($this->tenantId() !== null, fn ($q) => $q->where('client_id', $this->tenantId()))
            ->when($this->tenantId() === null && $this->clientFilter, fn ($q) => $q->where('client_id', $this->clientFilter))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('vendor', 'like', "%{$this->search}%")));
    }

    public function save(LicenseService $service): void
    {
        abort_unless(auth()->user()->can(Permission::LicensesManage->value), 403);

        $validated = $this->validate([
            'name'         => ['required', 'string', 'max:150'],
            'vendor'       => ['nullable', 'string', 'max:150'],
            'packageId'    => ['nullable', 'integer', 'exists:packages,id'],
            'formClientId' => [$this->tenantId() === null ? 'required' : 'nullable', 'integer', 'exists:clients,id'],
            'licenseKey'   => ['nullable', 'string', 'max:4000'],
            'seats'        => ['nullable', 'integer', 'between:1,100000'],
            'expiresAt'    => ['nullable', 'date'],
            'notes'        => ['nullable', 'string', 'max:1000'],
        ]);

        // Tenancy is not a form field a tenant controls.
        $clientId = $this->tenantId() ?? (int) $validated['formClientId'];

        $attributes = [
            'client_id'  => $clientId,
            'package_id' => $validated['packageId'],
            'name'       => $validated['name'],
            'vendor'     => $validated['vendor'] ?: null,
            'seats'      => $validated['seats'],
            'expires_at' => $validated['expiresAt'],
            'notes'      => $validated['notes'] ?: null,
        ];

        if ($this->editingId !== null) {
            $license = $this->visibleLicenses()->findOrFail($this->editingId);
            unset($attributes['client_id']); // ownership never changes on edit
            $service->update($license, $attributes, $this->licenseKey);
            session()->flash('status', "License \"{$license->name}\" saved.");
        } else {
            $service->create($attributes, $this->licenseKey, auth()->id());
            session()->flash('status', "License \"{$validated['name']}\" added.");
        }

        $this->reset('editingId', 'name', 'vendor', 'packageId', 'formClientId', 'licenseKey', 'seats', 'expiresAt', 'notes');
    }

    public function edit(int $licenseId): void
    {
        abort_unless(auth()->user()->can(Permission::LicensesManage->value), 403);

        $license = $this->visibleLicenses()->findOrFail($licenseId);

        $this->editingId = $license->id;
        $this->name = $license->name;
        $this->vendor = (string) $license->vendor;
        $this->packageId = $license->package_id;
        $this->formClientId = $license->client_id;
        $this->licenseKey = ''; // blank keeps the stored key
        $this->seats = $license->seats;
        $this->expiresAt = $license->expires_at?->format('Y-m-d');
        $this->notes = (string) $license->notes;
    }

    public function delete(int $licenseId): void
    {
        abort_unless(auth()->user()->can(Permission::LicensesManage->value), 403);

        $license = $this->visibleLicenses()->findOrFail($licenseId);
        $license->delete();

        activity('licenses')->causedBy(auth()->user())
            ->withProperties(['name' => $license->name])->log('license_deleted');

        session()->flash('status', "License \"{$license->name}\" deleted.");
    }

    public function assign(int $licenseId, LicenseService $service): void
    {
        abort_unless(auth()->user()->can(Permission::LicensesManage->value), 403);

        $license = $this->visibleLicenses()->findOrFail($licenseId);
        $computer = Computer::findOrFail((int) ($this->assignComputer[$licenseId] ?? 0));

        try {
            $service->assign($license, $computer, auth()->id());
            session()->flash('status', "\"{$license->name}\" assigned to {$computer->hostname}.");
        } catch (\DomainException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function unassign(int $licenseId, int $computerId, LicenseService $service): void
    {
        abort_unless(auth()->user()->can(Permission::LicensesManage->value), 403);

        $license = $this->visibleLicenses()->findOrFail($licenseId);
        $service->unassign($license, Computer::withTrashed()->findOrFail($computerId), auth()->id());
    }

    /** Owner-tenant only — revealKeyFor() enforces it, not the UI. */
    public function reveal(int $licenseId): void
    {
        $license = $this->visibleLicenses()->findOrFail($licenseId);

        $this->revealedKey = $license->revealKeyFor(auth()->user());
        $this->revealedId = $licenseId;
    }

    public function hideKey(): void
    {
        $this->reset('revealedId', 'revealedKey');
    }

    public function render()
    {
        $tenantId = $this->tenantId();

        return view('livewire.licenses.licenses-index', [
            'licenses'  => $this->visibleLicenses()->orderBy('name')->get(),
            'isStaff'   => $tenantId === null,
            'canManage' => auth()->user()->can(Permission::LicensesManage->value),
            'clients'   => $tenantId === null ? Client::orderBy('company_name')->get(['id', 'company_name']) : collect(),
            'packages'  => \App\Models\Package::active()->visibleTo(auth()->user())->orderBy('name')->get(['id', 'name']),
            // Computers assignable per license = the license's client's fleet.
            'computersByClient' => Computer::with('project')
                ->whereHas('project', fn ($q) => $q
                    ->when($tenantId !== null, fn ($qq) => $qq->where('client_id', $tenantId)))
                ->get()
                ->groupBy(fn ($c) => $c->project->client_id),
        ])->layout('layouts.app');
    }
}
