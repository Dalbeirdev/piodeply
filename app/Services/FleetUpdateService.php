<?php

namespace App\Services;

use App\Models\ComputerSoftware;
use App\Models\Package;
use Illuminate\Support\Collection;

/**
 * What is behind, across the fleet.
 *
 * "Newer exists" has two possible sources and they are not interchangeable.
 * For winget/choco packages only the machine knows, and its agent reports it
 * (available_version). For binary packages nobody can ask a package manager,
 * so the catalogue's own pinned latest is the best available answer.
 *
 * Both are compared with version_compare rather than trusted: winget
 * occasionally offers something that is not ahead, and a machine running
 * *newer* than the catalogue is not out of date — it was the older check
 * calling that drift.
 */
class FleetUpdateService
{
    /**
     * One row per package that has something newer waiting on a machine.
     *
     * Scoped to a client when given; the query is bounded by what is actually
     * behind rather than by the size of the inventory, since only rows with a
     * known newer version are considered at all.
     *
     * @return Collection<int, array{computer_id: int, hostname: string, id: string, name: string, from: string, to: string, source: string}>
     */
    public function pending(?int $clientId = null): Collection
    {
        // Catalogue-pinned latests: the only answer for binary packages.
        $pinned = Package::query()
            ->whereNotNull('winget_id')
            ->join('package_versions', function ($join) {
                $join->on('package_versions.package_id', '=', 'packages.id')
                    ->where('package_versions.is_latest', true);
            })
            ->pluck('package_versions.version', 'packages.winget_id');

        $names = Package::whereNotNull('winget_id')->pluck('name', 'winget_id');

        return ComputerSoftware::query()
            ->whereNotNull('version')
            ->where('version', '!=', '')
            ->where(fn ($q) => $q
                ->whereNotNull('available_version')                    // the machine said so
                ->orWhereIn('name', $pinned->keys()))                  // or the catalogue does
            ->when($clientId !== null, fn ($q) => $q->whereHas(
                'computer.project',
                fn ($p) => $p->withTrashed()->where('client_id', $clientId)
            ))
            ->with('computer:id,hostname')
            ->get()
            ->map(function (ComputerSoftware $row) use ($pinned, $names) {
                // The machine's own answer wins: the catalogue's "latest" is a
                // curated guess, and the agent asked the package source.
                $offered = $row->available_version ?: ($pinned[$row->name] ?? null);

                if ($offered === null || version_compare($offered, $row->version, '<=')) {
                    return null;
                }

                return [
                    'computer_id' => $row->computer_id,
                    'hostname'    => $row->computer?->hostname ?? '—',
                    'id'          => $row->name,
                    'name'        => $names[$row->name] ?? $row->name,
                    'from'        => $row->version,
                    'to'          => $offered,
                    'source'      => $row->available_version ? 'agent' : 'catalogue',
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * The same rows folded by package — what an operator acts on. One update
     * across sixty machines is one decision, not sixty.
     *
     * @return Collection<int, array{id: string, name: string, machines: int, from: string, to: string}>
     */
    public function byPackage(?int $clientId = null): Collection
    {
        return $this->pending($clientId)
            ->groupBy('id')
            ->map(fn (Collection $rows) => [
                'id'       => $rows->first()['id'],
                'name'     => $rows->first()['name'],
                'machines' => $rows->pluck('computer_id')->unique()->count(),
                // Oldest still out there, and the newest on offer.
                'from'     => $rows->sort(fn ($a, $b) => version_compare($a['from'], $b['from']))->first()['from'],
                'to'       => $rows->sort(fn ($a, $b) => version_compare($b['to'], $a['to']))->first()['to'],
            ])
            ->sortByDesc('machines')
            ->values();
    }
}
