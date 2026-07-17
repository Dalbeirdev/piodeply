<?php

namespace App\Services;

use App\Enums\InstallerType;
use App\Models\Package;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Which versions winget can actually install for a package.
 *
 * Pinning a version winget no longer publishes produces a job that fails on
 * every machine it reaches, and the operator finds out one deployment at a
 * time. The answer is a property of the winget repository, not of any one
 * machine, so it is asked here once and cached — rather than asking every
 * agent in the fleet the same question.
 *
 * winget's default source is the public microsoft/winget-pkgs repo, whose
 * manifests are laid out as manifests/<initial>/<Publisher>/<Package>/<Version>/.
 */
class WingetVersionService
{
    private const REPO = 'https://api.github.com/repos/microsoft/winget-pkgs/contents';

    /** Version lists change rarely; a stale-by-a-day list beats hammering the API. */
    private const CACHE_HOURS = 24;

    /** A miss is cached too, briefly, so an unknown package is not retried on every keystroke. */
    private const MISS_CACHE_MINUTES = 30;

    /**
     * Newest first. Null means "we do not know" — the source was unreachable,
     * or the package is not in the public repo — which the caller must present
     * differently from "no versions exist".
     *
     * @return list<string>|null
     */
    public function versionsFor(Package $package): ?array
    {
        if ($package->installer_type !== InstallerType::Winget || $package->winget_id === null) {
            return null; // only winget publishes a version list we can read
        }

        $cached = Cache::get($this->cacheKey($package->winget_id));

        if ($cached !== null) {
            return $cached === 'unknown' ? null : $cached;
        }

        $versions = $this->fetch($package->winget_id);

        Cache::put(
            $this->cacheKey($package->winget_id),
            $versions ?? 'unknown',
            $versions !== null ? now()->addHours(self::CACHE_HOURS) : now()->addMinutes(self::MISS_CACHE_MINUTES),
        );

        return $versions;
    }

    /** @return list<string>|null */
    private function fetch(string $wingetId): ?array
    {
        try {
            // Google.Chrome -> manifests/g/Google/Chrome
            // Microsoft.DotNet.SDK.8 -> manifests/m/Microsoft/DotNet/SDK/8
            $path = 'manifests/'.mb_strtolower(mb_substr($wingetId, 0, 1))
                  .'/'.implode('/', explode('.', $wingetId));

            $response = Http::timeout(5)
                ->withHeaders(['Accept' => 'application/vnd.github+json'])
                ->get(self::REPO.'/'.$path);

            if ($response->status() === 404) {
                return null; // not in the public repo — a private source, or renamed
            }

            if (! $response->successful()) {
                // Rate limited or down. Unknown, not empty: claiming a package
                // has no versions would be worse than admitting ignorance.
                Log::info('winget version lookup failed', [
                    'winget_id' => $wingetId, 'status' => $response->status(),
                ]);

                return null;
            }

            $versions = collect($response->json())
                ->filter(fn ($entry) => ($entry['type'] ?? null) === 'dir')
                ->pluck('name')
                ->filter(fn ($name) => preg_match('/^\d[\w.\-+]*$/', (string) $name) === 1)
                ->values()
                ->all();

            usort($versions, fn ($a, $b) => version_compare($b, $a)); // newest first

            return $versions !== [] ? $versions : null;
        } catch (\Throwable $e) {
            Log::info('winget version lookup errored', ['winget_id' => $wingetId, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function cacheKey(string $wingetId): string
    {
        return 'winget-versions:'.mb_strtolower($wingetId);
    }
}
