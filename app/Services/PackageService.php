<?php

namespace App\Services;

use App\Models\Package;
use App\Models\PackageVersion;
use App\Repositories\Contracts\PackageRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PackageService
{
    public function __construct(
        private readonly PackageRepositoryInterface $packages,
    ) {
    }

    public function create(array $attributes): Package
    {
        $attributes['slug'] = $this->uniqueSlug($attributes['name']);

        /** @var Package */
        return $this->packages->create($attributes);
    }

    public function update(Package $package, array $attributes): Package
    {
        unset($attributes['slug']); // slugs are stable once issued

        $this->packages->update($package, $attributes);

        return $package->fresh(['category', 'latestVersion']);
    }

    /**
     * Add a version and mark it latest (atomically demoting the previous
     * one). Binary installer types must carry a URL + SHA-256.
     */
    public function addVersion(Package $package, array $attributes, bool $markLatest = true): PackageVersion
    {
        if ($package->installer_type->requiresBinary()) {
            if (blank($attributes['installer_url'] ?? null) || blank($attributes['sha256'] ?? null)) {
                throw new InvalidArgumentException(
                    "{$package->installer_type->label()} packages require an installer URL and SHA-256 checksum."
                );
            }
        }

        if (isset($attributes['sha256'])) {
            $attributes['sha256'] = strtolower((string) $attributes['sha256']) ?: null;
        }

        return DB::transaction(function () use ($package, $attributes, $markLatest) {
            if ($markLatest) {
                $package->versions()->update(['is_latest' => false]);
            }

            return $package->versions()->create($attributes + ['is_latest' => $markLatest]);
        });
    }

    public function markLatest(PackageVersion $version): void
    {
        DB::transaction(function () use ($version) {
            $version->package->versions()->update(['is_latest' => false]);
            $version->update(['is_latest' => true]);
        });
    }

    public function removeVersion(PackageVersion $version): void
    {
        DB::transaction(function () use ($version) {
            $wasLatest = $version->is_latest;
            $package = $version->package;
            $version->delete();

            if ($wasLatest) {
                $package->versions()->orderByDesc('id')->first()?->update(['is_latest' => true]);
            }
        });
    }

    public function setActive(Package $package, bool $active): Package
    {
        $this->packages->update($package, ['is_active' => $active]);

        return $package->fresh();
    }

    public function delete(Package $package): void
    {
        $this->packages->delete($package); // soft delete
    }

    public function restore(Package $package): void
    {
        $this->packages->restore($package);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'package';
        $slug = $base;
        $n = 2;

        while (Package::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $n++;
        }

        return $slug;
    }
}
