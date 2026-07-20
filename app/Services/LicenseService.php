<?php

namespace App\Services;

use App\Models\Computer;
use App\Models\SoftwareLicense;

/**
 * License lifecycle with the same role-blind isolation as private
 * packages: a license serves its own client's machines, full stop. The
 * guards read ownership from the data, so no role — Super Admin included
 * — can assign one client's license to another client's computer.
 */
class LicenseService
{
    public function create(array $attributes, ?string $plainKey, ?int $userId): SoftwareLicense
    {
        $license = new SoftwareLicense($attributes + ['created_by' => $userId]);
        $license->setKey($plainKey);
        $license->save();

        activity('licenses')
            ->causedBy($userId ? \App\Models\User::find($userId) : null)
            ->performedOn($license)
            ->withProperties(['name' => $license->name, 'client_id' => $license->client_id])
            ->log('license_created');

        return $license;
    }

    public function update(SoftwareLicense $license, array $attributes, ?string $plainKey): SoftwareLicense
    {
        $license->fill($attributes);
        $license->setKey($plainKey); // blank keeps the stored key
        $license->save();

        return $license;
    }

    public function assign(SoftwareLicense $license, Computer $computer, ?int $userId): void
    {
        if ($computer->project->client_id !== $license->client_id) {
            throw new \DomainException(
                "\"{$license->name}\" belongs to {$license->client?->company_name} and cannot be "
                .'assigned to another client\'s machine.'
            );
        }

        if ($license->assignments()->where('computer_id', $computer->id)->exists()) {
            return; // already assigned — idempotent, not an error
        }

        if (! $license->hasFreeSeat()) {
            throw new \DomainException(
                "No free seats on \"{$license->name}\" ({$license->seatsUsed()}/{$license->seats} used). "
                .'Unassign a machine or add seats.'
            );
        }

        $license->assignments()->create([
            'computer_id' => $computer->id,
            'assigned_by' => $userId,
        ]);

        activity('licenses')
            ->causedBy($userId ? \App\Models\User::find($userId) : null)
            ->performedOn($license)
            ->withProperties(['computer' => $computer->hostname])
            ->log('license_assigned');
    }

    public function unassign(SoftwareLicense $license, Computer $computer, ?int $userId): void
    {
        $license->assignments()->where('computer_id', $computer->id)->delete();

        activity('licenses')
            ->causedBy($userId ? \App\Models\User::find($userId) : null)
            ->performedOn($license)
            ->withProperties(['computer' => $computer->hostname])
            ->log('license_unassigned');
    }
}
